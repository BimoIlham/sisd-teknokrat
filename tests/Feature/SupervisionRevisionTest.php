<?php

namespace Tests\Feature;

use App\Models\FormTemplate;
use App\Models\Question;
use App\Models\StageInstrument;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\User;
use App\Services\Reporting\SupervisionResultPdfService;
use App\Services\Supervision\EvidenceService;
use App\Services\Supervision\ScoringService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SupervisionRevisionTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['MEAN_0_4', 0.5])]
    #[TestWith(['LINEAR_1_4', 1.375])]
    #[TestWith(['FLOOR_1_4', 1.0])]
    public function test_live_score_uses_saved_answers_and_the_official_policy(string $strategy, float $expectedScore): void
    {
        [$supervisor, $supervision] = $this->createSupervision();
        $supervision->scoringPolicy->update(['strategy' => $strategy]);
        $questions = $supervision->formTemplate->questions()->where('question_type', 'SCORE')
            ->where('is_scored', true)->with('options')->take(2)->get();
        $answers = [
            $questions[0]->id => $questions[0]->options->firstWhere('numeric_score', 0)->id,
            $questions[1]->id => $questions[1]->options->firstWhere('numeric_score', 1)->id,
        ];

        $this->actingAs($supervisor)->postJson(route('supervisor.supervisions.draft', $supervision), [
            'answers' => $answers,
            'notes' => [$questions[0]->id => 'Catatan tidak menambah skor.'],
            'row_version' => 1,
        ])->assertOk();

        $response = $this->actingAs($supervisor)->get(route('supervisor.supervisions.form', $supervision->assignment));
        $response->assertOk()->assertSee('Nilai sementara')->assertSee('id="live-supervision-score"', false);
        $this->assertSame($answers, $response->viewData('existingAnswersPayload')->all());
        $payload = $response->viewData('scoredQuestionsPayload');
        $expectedQuestions = $supervision->formTemplate->questions()
            ->where('is_scored', true)->whereIn('question_type', ['SCORE', 'YES_NO'])->count();
        $this->assertCount($expectedQuestions, $payload);
        $questionPayload = $payload->firstWhere('id', $questions[0]->id);
        $this->assertSame(0.0, $questionPayload['options']->firstWhere('id', $answers[$questions[0]->id])['score']);

        $officialScore = app(ScoringService::class)->calculate($answers, $supervision->scoringPolicy);
        $this->assertSame($expectedScore, $officialScore['final_score']);
        $this->assertSame(2, $officialScore['n_items']);
        $this->assertSame(1.0, $officialScore['raw_score']);
    }

    public function test_optional_notes_can_be_saved_updated_and_cleared_without_changing_the_score(): void
    {
        [$supervisor, $supervision] = $this->createSupervision();
        $question = $supervision->formTemplate->questions()->where('question_type', 'SCORE')->with('options')->firstOrFail();
        $option = $question->options->last();

        $this->actingAs($supervisor)->postJson(route('supervisor.supervisions.draft', $supervision), [
            'answers' => [$question->id => $option->id],
            'notes' => [$question->id => '  Mahasiswa aktif berdiskusi.  '],
            'row_version' => 1,
        ])->assertOk()->assertJsonPath('row_version', 2);

        $this->assertDatabaseHas('supervision_answers', [
            'supervision_id' => $supervision->id,
            'question_id' => $question->id,
            'question_option_id' => $option->id,
            'notes' => 'Mahasiswa aktif berdiskusi.',
        ]);

        $this->actingAs($supervisor)->postJson(route('supervisor.supervisions.draft', $supervision), [
            'answers' => [$question->id => $option->id],
            'row_version' => 2,
        ])->assertOk();
        $this->assertSame('Mahasiswa aktif berdiskusi.', $supervision->answers()->firstOrFail()->notes);

        $this->actingAs($supervisor)->postJson(route('supervisor.supervisions.draft', $supervision), [
            'answers' => [],
            'notes' => [$question->id => ''],
            'row_version' => 3,
        ])->assertOk();
        $answer = $supervision->answers()->firstOrFail();
        $this->assertNull($answer->notes);
        $this->assertSame($option->id, $answer->question_option_id);
        $this->assertSame((float) $option->numeric_score, (float) $answer->numeric_score);
    }

    public function test_invalid_notes_and_questions_from_other_forms_do_not_modify_answers(): void
    {
        [$supervisor, $supervision] = $this->createSupervision();
        $question = $supervision->formTemplate->questions()->firstOrFail();

        $this->actingAs($supervisor)->postJson(route('supervisor.supervisions.draft', $supervision), [
            'answers' => [],
            'notes' => [$question->id => str_repeat('a', 5001)],
            'row_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('notes.'.$question->id);
        $this->assertSame(0, $supervision->answers()->count());
        $this->assertSame(1, $supervision->fresh()->row_version);

        $otherQuestion = Question::where('form_template_id', '<>', $supervision->form_template_id)->firstOrFail();
        $this->actingAs($supervisor)->postJson(route('supervisor.supervisions.draft', $supervision), [
            'answers' => [],
            'notes' => [$otherQuestion->id => 'Catatan dari formulir lain'],
            'row_version' => 1,
        ])->assertOk();
        $this->assertSame(0, $supervision->answers()->count());
    }

    #[TestWith([null])]
    #[TestWith(['<script>alert("catatan")</script> Mahasiswa aktif.'])]
    public function test_submission_keeps_optional_notes_in_the_snapshot_and_pdf(?string $note): void
    {
        Storage::fake('local');
        [$supervisor, $supervision] = $this->createSupervision();
        $this->actingAs($supervisor);
        $answers = [];
        foreach ($supervision->formTemplate->questions()->with('options')->get() as $question) {
            if ($question->question_type === 'FILE') {
                app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->create('rps.pdf', 120, 'application/pdf'), $question->id);

                continue;
            }
            $answers[$question->id] = match ($question->question_type) {
                'SCORE', 'YES_NO' => $question->options->last()->id,
                'LINK' => 'https://lms.teknokrat.ac.id/course/101',
                default => 'Pembelajaran berlangsung baik.',
            };
        }
        $questionId = array_key_first($answers);

        $this->actingAs($supervisor)->post(route('supervisor.supervisions.review_draft', $supervision), [
            'answers' => $answers,
            'notes' => [$questionId => $note],
            'row_version' => $supervision->fresh()->row_version,
        ])->assertRedirect(route('supervisor.supervisions.review', $supervision));
        $reviewResponse = $this->actingAs($supervisor)->get(route('supervisor.supervisions.review', $supervision));
        $reviewResponse->assertOk();
        if ($note !== null) {
            $reviewResponse->assertSee($note)->assertDontSee($note, false);
        }

        $this->actingAs($supervisor)->post(route('supervisor.supervisions.submit', $supervision), [
            'review_confirmed' => '1',
            'review_version' => $supervision->fresh()->row_version,
        ])->assertRedirect(route('supervisor.supervisions.show', $supervision))->assertSessionHasNoErrors();

        $submitted = $supervision->fresh();
        $this->assertSame('SUBMITTED', $submitted->status);
        $snapshotAnswer = collect($submitted->snapshot_json['answers'])->firstWhere('question_id', $questionId);
        $this->assertSame($note, $snapshotAnswer['notes']);
        $this->assertSame(4.0, (float) $submitted->final_score);

        $html = app(SupervisionResultPdfService::class)->generate($submitted)->getDomPDF()->getDom()->saveHTML();
        $this->assertSame(2, substr_count($html, 'class="signature-role"'));
        $this->assertStringContainsString('Dosen Pengampu', $html);
        $this->assertStringContainsString('Dosen Supervisor', $html);
        $this->assertStringNotContainsString('Ketua LPM', $html);
        $this->assertStringNotContainsString('Catatan, Temuan, dan Rekomendasi', $html);
        $this->assertSame(1, substr_count($html, 'Pembelajaran berlangsung baik.'));
        if ($note !== null) {
            $this->assertStringContainsString('Mahasiswa aktif.', $html);
            $this->assertStringNotContainsString($note, $html);
        }

        $this->actingAs($supervisor)->get(route('supervisor.supervisions.show', $submitted))
            ->assertOk()->assertDontSee('Cetak PDF Nilai');
        $this->actingAs($supervisor)->postJson(route('supervisor.supervisions.draft', $submitted), [
            'answers' => [], 'notes' => [$questionId => 'Ubah hasil'], 'row_version' => 3,
        ])->assertForbidden();
    }

    public function test_execution_time_uses_actual_submission_in_wib_and_remains_the_same_when_downloaded_later(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-09 18:05:27', 'UTC'));
        [$supervisor, $supervision] = $this->createSupervision();
        $this->actingAs($supervisor);
        $answers = [];
        foreach ($supervision->formTemplate->questions()->with('options')->get() as $question) {
            if ($question->question_type === 'FILE') {
                app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->create('rps.pdf', 120, 'application/pdf'), $question->id);

                continue;
            }
            $answers[$question->id] = match ($question->question_type) {
                'SCORE', 'YES_NO' => $question->options->last()->id,
                'LINK' => 'https://lms.teknokrat.ac.id/course/101',
                default => 'Perkuliahan berlangsung baik.',
            };
        }

        $this->actingAs($supervisor)->post(route('supervisor.supervisions.review_draft', $supervision), [
            'answers' => $answers,
            'row_version' => $supervision->fresh()->row_version,
        ])->assertRedirect(route('supervisor.supervisions.review', $supervision));
        $this->get(route('supervisor.supervisions.review', $supervision))->assertOk();
        $this->post(route('supervisor.supervisions.submit', $supervision), [
            'review_confirmed' => '1',
            'review_version' => $supervision->fresh()->row_version,
        ])->assertRedirect(route('supervisor.supervisions.show', $supervision))->assertSessionHasNoErrors();

        $submitted = $supervision->fresh();
        $this->assertSame('2026-10-09 18:05:27', $submitted->submitted_at->toDateTimeString());
        $this->assertSame('10 Oktober 2026 | 01:05:27 WIB', $this->pdfExecutionDetails($submitted));
        $this->get(route('supervisor.supervisions.show', $submitted))
            ->assertOk()->assertSee('10 Oktober 2026')->assertSee('01:05:27 WIB');
        $admin = User::where('email', 'lpm@teknokrat.ac.id')->firstOrFail();
        $this->withSession(['password_hash_web' => null])->actingAs($admin)->get(route('lpm.supervisions.completed'))
            ->assertOk()->assertSee('10 Okt 2026 01:05 WIB');

        $this->travel(2)->days();

        $this->assertSame('10 Oktober 2026 | 01:05:27 WIB', $this->pdfExecutionDetails($submitted->fresh()));
    }

    public function test_result_without_a_submission_timestamp_does_not_invent_an_execution_time(): void
    {
        [, $supervision] = $this->createSupervision();
        $supervision->update(['status' => 'SUBMITTED', 'final_score' => 3.0]);

        $this->assertSame('Belum tercatat', $this->pdfExecutionDetails($supervision));
    }

    public function test_only_lpm_can_download_supervision_pdfs_and_supervisor_cannot_download_schedule_pdf(): void
    {
        [$supervisor, $supervision] = $this->createSupervision();
        $supervision->update(['status' => 'SUBMITTED', 'final_score' => 3.5]);
        $admin = User::where('email', 'lpm@teknokrat.ac.id')->firstOrFail();
        $this->assertTrue(Gate::forUser($admin)->allows('downloadPdf', $supervision));
        $this->assertFalse(Gate::forUser($supervisor)->allows('downloadPdf', $supervision));

        $this->actingAs($supervisor)->get(route('supervisor.supervisions.pdf', $supervision))->assertForbidden();
        $this->actingAs($supervisor)->get(route('lpm.supervisions.pdf', $supervision))->assertForbidden();
        $this->actingAs($supervisor)->get(route('supervisor.schedule.pdf'))->assertForbidden();
        $this->actingAs($supervisor)->get(route('supervisor.schedule'))->assertOk()->assertDontSee('Unduh Jadwal Resmi (PDF)');
        $this->withSession(['password_hash_web' => null])->actingAs($admin)->get(route('lpm.supervisions.pdf', $supervision))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_lpm_cannot_download_an_unsubmitted_supervision_pdf(): void
    {
        [, $supervision] = $this->createSupervision();
        $admin = User::where('email', 'lpm@teknokrat.ac.id')->firstOrFail();

        $this->actingAs($admin)->get(route('lpm.supervisions.pdf', $supervision))->assertNotFound();
    }

    public function test_stage_one_pdf_labels_its_score_and_observation_without_stage_two_columns(): void
    {
        [, $supervision] = $this->createSupervision();
        $supervision->update(['status' => 'SUBMITTED', 'final_score' => 3.0, 'submitted_at' => now()]);

        $document = app(SupervisionResultPdfService::class)->generate($supervision)->getDomPDF()->getDom();
        $xpath = new \DOMXPath($document);
        $this->assertSame('NILAI TAHAP 1', $xpath->query('//div[@class="score-label"]')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//div[@class="score-label"]')->length);
        $headers = $xpath->query('//table[@class="assessment-table"]/thead/tr')->item(0);
        $this->assertSame(['No', 'Indikator Penilaian', 'Hasil Pengamatan', 'Skor'], $this->tableCellTexts($headers));
        $this->assertStringNotContainsString('NILAI AKHIR', $document->textContent);
        $this->assertStringNotContainsString('Hasil Pengamatan / Jawaban', $document->textContent);
    }

    public function test_stage_two_pdf_pairs_scores_and_observations_by_indicator_across_form_versions(): void
    {
        [, $stageOne] = $this->createSupervision();
        $stageOne->update(['status' => 'SUBMITTED', 'final_score' => 2.0, 'submitted_at' => now()]);
        $sectionId = $stageOne->formTemplate->questions()->where('question_type', 'SCORE')->firstOrFail()->form_section_id;
        $questions = $stageOne->formTemplate->questions()->where('form_section_id', $sectionId)
            ->where('question_type', 'SCORE')->with('options', 'section')->take(2)->get();
        foreach ($questions as $index => $question) {
            $stageOne->answers()->create([
                'question_id' => $question->id,
                'question_option_id' => $question->options->firstWhere('numeric_score', 2)->id,
                'numeric_score' => 2,
                'notes' => 'Pengamatan T1 indikator '.($index + 1),
            ]);
        }

        $template = $stageOne->formTemplate->replicate();
        $template->version++;
        $template->save();
        $section = $questions[0]->section->replicate();
        $section->form_template_id = $template->id;
        $section->save();
        $stageTwo = $this->createStageTwoSupervision($stageOne, $template);
        foreach ($questions as $index => $question) {
            $newQuestion = $question->replicate();
            $newQuestion->form_template_id = $template->id;
            $newQuestion->form_section_id = $section->id;
            $newQuestion->sort_order = 2 - $index;
            $newQuestion->save();
            $option = $question->options->firstWhere('numeric_score', 4)->replicate();
            $option->question_id = $newQuestion->id;
            $option->save();
            $stageTwo->answers()->create([
                'question_id' => $newQuestion->id,
                'question_option_id' => $option->id,
                'numeric_score' => 4,
                'notes' => 'Pengamatan T2 indikator '.($index + 1),
            ]);
        }

        $document = app(SupervisionResultPdfService::class)->generate($stageTwo)->getDomPDF()->getDom();
        $xpath = new \DOMXPath($document);
        $this->assertSame('NILAI TAHAP 1', $xpath->query('//div[@class="score-label"]')->item(0)->textContent);
        $this->assertSame('NILAI TAHAP 2', $xpath->query('//div[@class="score-label"]')->item(1)->textContent);
        $this->assertSame('2.00', $xpath->query('//div[@class="score-value"]')->item(0)->textContent);
        $this->assertSame('4.00', $xpath->query('//div[@class="score-value"]')->item(1)->textContent);
        $headers = $xpath->query('//table[contains(@class,"assessment-comparison")]/thead/tr')->item(0);
        $this->assertSame(['No', 'Indikator Penilaian', 'Hasil Pengamatan T1', 'Hasil Pengamatan T2', 'Skor T1', 'Skor T2'], $this->tableCellTexts($headers));
        $rows = $xpath->query('//table[contains(@class,"assessment-comparison")]/tbody/tr');
        foreach ($questions as $index => $question) {
            $cells = $this->tableCellTexts($rows->item($index));
            $this->assertStringContainsString('Pengamatan T1 indikator '.($index + 1), $cells[2]);
            $this->assertStringContainsString('Pengamatan T2 indikator '.($index + 1), $cells[3]);
            $this->assertSame(['2.0', '4.0'], array_slice($cells, 4));
        }
        $this->assertStringStartsWith('%PDF-', app(SupervisionResultPdfService::class)->generate($stageTwo)->output());
    }

    #[TestWith(['missing'])]
    #[TestWith(['draft'])]
    #[TestWith(['cancelled'])]
    #[TestWith(['other_lecturer'])]
    #[TestWith(['other_period'])]
    public function test_stage_two_pdf_does_not_use_unsubmitted_or_unrelated_stage_one_results(string $scenario): void
    {
        [, $stageOne] = $this->createSupervision();
        $stageOne->update(['status' => 'SUBMITTED', 'final_score' => 2.75, 'submitted_at' => now()]);
        $stageTwo = $this->createStageTwoSupervision($stageOne);
        $question = $stageTwo->formTemplate->questions()->where('question_type', 'SCORE')->firstOrFail();
        $stageTwo->answers()->create(['question_id' => $question->id, 'numeric_score' => 4, 'notes' => 'Hasil tahap kedua.']);

        if ($scenario === 'missing') {
            $stageOne->delete();
        } elseif ($scenario === 'draft') {
            $stageOne->update(['status' => 'DRAFT']);
        } elseif ($scenario === 'cancelled') {
            $stageOne->assignment->update(['status' => 'CANCELLED']);
        } elseif ($scenario === 'other_lecturer') {
            $stageOne->assignment->update(['target_lecturer_id' => $stageOne->assignment->supervisor_lecturer_id]);
        } else {
            $period = $stageOne->assignment->stage->period->replicate();
            $period->save();
            $stage = $stageOne->assignment->stage->replicate();
            $stage->supervision_period_id = $period->id;
            $stage->save();
            $stageOne->assignment->update(['supervision_stage_id' => $stage->id]);
        }

        $document = app(SupervisionResultPdfService::class)->generate($stageTwo->fresh())->getDomPDF()->getDom();
        $xpath = new \DOMXPath($document);
        $this->assertSame('-', $xpath->query('//div[@class="score-value"]')->item(0)->textContent);
        $this->assertSame('4.00', $xpath->query('//div[@class="score-value"]')->item(1)->textContent);
        $row = $xpath->query('//table[contains(@class,"assessment-comparison")]/tbody/tr')->item(0);
        $cells = $this->tableCellTexts($row);
        $this->assertSame('Belum dinilai', $cells[2]);
        $this->assertStringContainsString('Hasil tahap kedua.', $cells[3]);
        $this->assertSame('4.0', $cells[5]);
    }

    public function test_changed_indicators_are_shown_separately_instead_of_pairing_unrelated_observations(): void
    {
        [, $stageOne] = $this->createSupervision();
        $stageOne->update(['status' => 'SUBMITTED', 'final_score' => 2.0, 'submitted_at' => now()]);
        $original = $stageOne->formTemplate->questions()->where('question_type', 'SCORE')->with('section')->firstOrFail();
        $stageOne->answers()->create(['question_id' => $original->id, 'numeric_score' => 2, 'notes' => 'Pengamatan indikator lama.']);
        $template = $stageOne->formTemplate->replicate();
        $template->version++;
        $template->save();
        $section = $original->section->replicate();
        $section->form_template_id = $template->id;
        $section->save();
        $changed = $original->replicate();
        $changed->form_template_id = $template->id;
        $changed->form_section_id = $section->id;
        $changed->question_text = 'Indikator baru yang berbeda';
        $changed->save();
        $stageTwo = $this->createStageTwoSupervision($stageOne, $template);
        $stageTwo->answers()->create(['question_id' => $changed->id, 'numeric_score' => 4, 'notes' => 'Pengamatan indikator baru.']);

        $document = app(SupervisionResultPdfService::class)->generate($stageTwo)->getDomPDF()->getDom();
        $xpath = new \DOMXPath($document);
        $rows = $xpath->query('//table[contains(@class,"assessment-comparison")]/tbody/tr');
        $originalCells = $this->tableCellTexts($rows->item(0));
        $this->assertStringContainsString('Pengamatan indikator lama.', $originalCells[2]);
        $this->assertSame("\u{2014}", $originalCells[3]);
        $this->assertSame(['2.0', "\u{2014}"], array_slice($originalCells, 4));
        $changedCells = $this->tableCellTexts($rows->item($original->section->questions()->count()));
        $this->assertSame('Indikator baru yang berbeda', $changedCells[1]);
        $this->assertSame("\u{2014}", $changedCells[2]);
        $this->assertStringContainsString('Pengamatan indikator baru.', $changedCells[3]);
        $this->assertSame(["\u{2014}", '4.0'], array_slice($changedCells, 4));
    }

    /**
     * @return array<int, string>
     */
    private function tableCellTexts(\DOMNode $row): array
    {
        $cells = [];
        foreach ($row->childNodes as $cell) {
            if ($cell instanceof \DOMElement) {
                $cells[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent));
            }
        }

        return $cells;
    }

    private function createStageTwoSupervision(Supervision $stageOne, ?FormTemplate $template = null): Supervision
    {
        $stage = SupervisionStage::where('supervision_period_id', $stageOne->assignment->stage->supervision_period_id)
            ->where('stage_no', 2)->firstOrFail();
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id,
            'target_lecturer_id' => $stageOne->assignment->target_lecturer_id,
            'supervisor_lecturer_id' => $stageOne->assignment->supervisor_lecturer_id,
            'teaching_occurrence_id' => $stageOne->assignment->teaching_occurrence_id,
            'assignment_type' => 'MANUAL',
            'status' => 'FINAL',
        ]);

        return Supervision::create([
            'supervision_assignment_id' => $assignment->id,
            'form_template_id' => $template?->id ?? $stageOne->form_template_id,
            'scoring_policy_id' => $stageOne->scoring_policy_id,
            'status' => 'SUBMITTED',
            'final_score' => 4.0,
            'submitted_at' => now(),
        ]);
    }

    private function pdfExecutionDetails(Supervision $supervision): string
    {
        $document = app(SupervisionResultPdfService::class)->generate($supervision)->getDomPDF()->getDom();
        $xpath = new \DOMXPath($document);
        $cell = $xpath->query('//td[@class="info-label" and normalize-space(.)="Pelaksanaan"]/following-sibling::td[@class="info-value"]')->item(0);
        $this->assertNotNull($cell);

        return trim(preg_replace('/\s+/u', ' ', $cell->textContent));
    }

    /**
     * @return array{User, Supervision}
     */
    private function createSupervision(): array
    {
        $this->seed([DatabaseSeeder::class, UserSeeder::class, DemoAcademicSeeder::class]);
        $supervisor = User::where('email', 'supervisor1@teknokrat.ac.id')->firstOrFail();
        $stage = SupervisionStage::firstOrFail();
        $stage->update([
            'status' => 'FINALIZED',
            'starts_on' => now('Asia/Jakarta')->subDay()->toDateString(),
            'ends_on' => now('Asia/Jakarta')->addDay()->toDateString(),
        ]);
        $occurrence = TeachingOccurrence::with('teachingSchedule')
            ->whereHas('teachingSchedule', fn ($query) => $query->where('lecturer_id', '<>', $supervisor->lecturer_id))
            ->firstOrFail();
        $instrument = StageInstrument::where('supervision_stage_id', $stage->id)
            ->where('delivery_mode', strtoupper($occurrence->teachingSchedule->delivery_mode))->firstOrFail();
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id,
            'target_lecturer_id' => $occurrence->teachingSchedule->lecturer_id,
            'supervisor_lecturer_id' => $supervisor->lecturer_id,
            'teaching_occurrence_id' => $occurrence->id,
            'assignment_type' => 'MANUAL',
            'status' => 'FINAL',
        ]);
        $supervision = Supervision::create([
            'supervision_assignment_id' => $assignment->id,
            'stage_instrument_id' => $instrument->id,
            'form_template_id' => $instrument->form_template_id,
            'scoring_policy_id' => $instrument->scoring_policy_id,
            'status' => 'DRAFT',
            'row_version' => 1,
        ]);

        return [$supervisor, $supervision];
    }
}
