<?php

namespace Tests\Feature;

use App\Models\FormTemplate;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Services\InstrumentVersionService;
use App\Services\Reporting\SupervisionResultPdfService;
use App\Services\Scheduling\PeriodAssignmentPairService;
use App\Services\Supervision\DraftService;
use App\Services\Supervision\EvidenceService;
use App\Services\Supervision\SubmitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class InstrumentHistoryTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
        Storage::fake('local');
    }

    private function supervision(): Supervision
    {
        $this->stage->update(['status' => 'FINALIZED']);
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'status' => 'FINAL', 'assignment_type' => 'MANUAL',
        ]);

        return Supervision::create([
            'supervision_assignment_id' => $assignment->id, 'stage_instrument_id' => $this->instrument->id,
            'form_template_id' => $this->instrument->form_template_id, 'scoring_policy_id' => $this->instrument->scoring_policy_id,
            'status' => 'DRAFT', 'row_version' => 1,
        ]);
    }

    private function questionPayload(): array
    {
        $question = $this->instrument->formTemplate->questions()->firstOrFail();
        $option = $question->options()->firstOrFail();

        return [
            'form_section_id' => $question->form_section_id, 'question_text' => 'Redaksi diubah',
            'question_type' => 'SCORE', 'is_scored' => true, 'is_required' => true, 'sort_order' => 1,
            'options' => [['id' => $option->id, 'value' => '3', 'label' => 'Label diubah', 'numeric_score' => 4]],
        ];
    }

    public function test_unused_instrument_remains_editable_including_options_and_sections(): void
    {
        $template = $this->instrument->formTemplate;
        $question = $template->questions()->firstOrFail();
        $this->putJson(route('lpm.instruments.questions.update', $question), $this->questionPayload())->assertRedirect();
        $this->assertSame('Redaksi diubah', $question->fresh()->question_text);
        $this->assertSame('Label diubah', $question->options()->firstOrFail()->label);
        $section = $question->section;
        $this->putJson(route('lpm.instruments.sections.update', $section), ['title' => 'Bagian baru', 'sort_order' => 2])->assertRedirect();
        $this->assertSame('Bagian baru', $section->fresh()->title);
        $this->assertSame(2, $section->fresh()->sort_order);
        $this->postJson(route('lpm.instruments.sections.store', $template), ['title' => 'Tambahan'])->assertRedirect();
        $this->postJson(route('lpm.instruments.questions.store', $template), [
            'form_section_id' => $section->id, 'question_text' => 'Pertanyaan baru', 'question_type' => 'TEXTAREA',
        ])->assertRedirect();
        $this->deleteJson(route('lpm.instruments.questions.destroy', $question))->assertRedirect();
        $this->deleteJson(route('lpm.instruments.sections.destroy', $section))->assertRedirect();
        $this->assertDatabaseMissing('questions', ['id' => $question->id]);
        $this->assertDatabaseMissing('form_sections', ['id' => $section->id]);
    }

    #[TestWith(['question'])]
    #[TestWith(['option'])]
    #[TestWith(['delete_option'])]
    #[TestWith(['add_option'])]
    #[TestWith(['type'])]
    #[TestWith(['order'])]
    #[TestWith(['add_question'])]
    #[TestWith(['delete_question'])]
    #[TestWith(['add_section'])]
    #[TestWith(['edit_section'])]
    #[TestWith(['delete_section'])]
    public function test_used_instrument_rejects_direct_mutation_without_any_answers(string $operation): void
    {
        $supervision = $this->supervision();
        $template = $this->instrument->formTemplate;
        $question = $template->questions()->firstOrFail();
        $section = $question->section;
        $before = $template->load('sections.questions.options')->toArray();
        $payload = $this->questionPayload();
        if ($operation === 'delete_option') {
            $payload['options'] = [['value' => '4', 'label' => 'Pengganti', 'numeric_score' => 4]];
        } elseif ($operation === 'add_option') {
            $payload['options'][] = ['value' => '4', 'label' => 'Tambahan', 'numeric_score' => 4];
        } elseif ($operation === 'type') {
            $payload['question_type'] = 'TEXTAREA';
        } elseif ($operation === 'order') {
            $payload['sort_order'] = 10;
        }
        $response = match ($operation) {
            'add_question' => $this->postJson(route('lpm.instruments.questions.store', $template), $payload),
            'delete_question' => $this->deleteJson(route('lpm.instruments.questions.destroy', $question)),
            'add_section' => $this->postJson(route('lpm.instruments.sections.store', $template), ['title' => 'Baru']),
            'edit_section' => $this->putJson(route('lpm.instruments.sections.update', $section), ['title' => 'Baru', 'sort_order' => 2]),
            'delete_section' => $this->deleteJson(route('lpm.instruments.sections.destroy', $section)),
            default => $this->putJson(route('lpm.instruments.questions.update', $question), $payload),
        };
        $response->assertUnprocessable()->assertJsonValidationErrors('instrument');
        $this->assertSame($before, $template->fresh()->load('sections.questions.options')->toArray());
        $this->assertSame(0, $supervision->answers()->count());
    }

    public function test_submitted_instrument_is_protected_and_html_shows_a_clear_message(): void
    {
        $supervision = $this->supervision();
        $supervision->update(['status' => 'SUBMITTED']);
        $question = $supervision->formTemplate->questions()->firstOrFail();
        $this->from(route('lpm.instruments.show', $supervision->formTemplate))
            ->put(route('lpm.instruments.questions.update', $question), $this->questionPayload())
            ->assertSessionHasErrors('instrument');
        $this->get(route('lpm.instruments.mode', ['mode' => 'offline', 'template' => $supervision->form_template_id]))
            ->assertOk()->assertSee('Buat versi baru')->assertSee('Instrumen sudah digunakan dalam penilaian.');
    }

    public function test_clone_copies_full_instrument_with_new_ids_and_can_be_edited(): void
    {
        $source = $this->instrument->formTemplate;
        $question = $source->questions()->firstOrFail();
        $question->update(['help_text' => 'Petunjuk asli', 'validation_rules' => ['max_files' => 1], 'conditional_rule' => ['key' => 'value'], 'source_reference' => ['page' => 1]]);
        $supervision = $this->supervision();
        $this->post(route('lpm.instruments.duplicate', $source))->assertRedirect();
        $copy = FormTemplate::where('code', $source->code)->latest('version')->firstOrFail();
        $this->assertSame(2, $copy->version);
        $this->assertSame('DRAFT', $copy->status);
        $this->assertNotSame($source->id, $copy->id);
        $copiedQuestion = $copy->questions()->firstOrFail();
        $this->assertNotSame($question->id, $copiedQuestion->id);
        $this->assertNotSame($question->form_section_id, $copiedQuestion->form_section_id);
        $this->assertSame($question->only(['code', 'question_text', 'help_text', 'question_type', 'is_scored', 'is_required', 'sort_order', 'validation_rules', 'conditional_rule', 'source_reference']), $copiedQuestion->only(['code', 'question_text', 'help_text', 'question_type', 'is_scored', 'is_required', 'sort_order', 'validation_rules', 'conditional_rule', 'source_reference']));
        $this->assertNotSame($question->options()->first()->id, $copiedQuestion->options()->first()->id);
        $this->assertSame($question->options()->first()->only(['value', 'numeric_score', 'label', 'sort_order']), $copiedQuestion->options()->first()->only(['value', 'numeric_score', 'label', 'sort_order']));
        $payload = $this->questionPayload();
        $payload['form_section_id'] = $copiedQuestion->form_section_id;
        $payload['options'][0]['id'] = $copiedQuestion->options()->first()->id;
        $this->putJson(route('lpm.instruments.questions.update', $copiedQuestion), $payload)->assertRedirect();
        $this->assertSame('Redaksi diubah', $copiedQuestion->fresh()->question_text);
        $this->assertSame('Pengamatan pengujian', $question->fresh()->question_text);
        $this->assertSame($source->id, $supervision->fresh()->form_template_id);
    }

    public function test_existing_draft_keeps_its_binding_after_stage_instrument_changes(): void
    {
        $supervision = $this->supervision();
        $sourceId = $supervision->form_template_id;
        $copy = app(InstrumentVersionService::class)->duplicate($supervision->formTemplate);
        $this->instrument->update(['form_template_id' => $copy->id]);
        app(PeriodAssignmentPairService::class)->refreshSupervision($supervision->assignment, $this->occurrence);
        $this->assertSame($sourceId, $supervision->fresh()->form_template_id);
        $this->expectException(ValidationException::class);
        $supervision->update(['form_template_id' => $copy->id]);
    }

    public function test_new_period_uses_published_version_and_keeps_old_stage_bindings(): void
    {
        $source = $this->instrument->formTemplate;
        $source->update(['code' => 'DEMO_OFFLINE']);
        $copy = app(InstrumentVersionService::class)->duplicate($source);
        $this->post(route('lpm.instruments.publish', $copy))->assertRedirect();
        $this->post(route('lpm.periods.store'), [
            'name' => 'Periode baru F14', 'status' => 'CLOSED', 'academic_year' => '2032/2033', 'semester' => 'GANJIL',
            'stage_1_starts_on' => '2032-10-01', 'stage_1_ends_on' => '2032-10-31',
            'stage_2_starts_on' => '2033-01-01', 'stage_2_ends_on' => '2033-01-31',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $period = SupervisionPeriod::where('name', 'Periode baru F14')->firstOrFail();
        $this->assertSame($copy->id, $period->stages()->firstOrFail()->instruments()->where('delivery_mode', 'OFFLINE')->firstOrFail()->form_template_id);
        $this->assertSame($source->id, $this->instrument->fresh()->form_template_id);
        $this->instrument->update(['form_template_id' => $copy->id]);
        $this->assertSame($copy->id, $this->supervision()->form_template_id);
    }

    public function test_unpublished_version_is_not_selected_for_new_periods(): void
    {
        $source = $this->instrument->formTemplate;
        $source->update(['code' => 'DEMO_OFFLINE']);
        $copy = app(InstrumentVersionService::class)->duplicate($source);
        $this->post(route('lpm.periods.store'), [
            'name' => 'Periode sebelum publikasi', 'status' => 'CLOSED', 'academic_year' => '2032/2033', 'semester' => 'GANJIL',
            'stage_1_starts_on' => '2032-10-01', 'stage_1_ends_on' => '2032-10-31',
            'stage_2_starts_on' => '2033-01-01', 'stage_2_ends_on' => '2033-01-31',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $period = SupervisionPeriod::where('name', 'Periode sebelum publikasi')->firstOrFail();
        $this->assertSame($source->id, $period->stages()->firstOrFail()->instruments()->where('delivery_mode', 'OFFLINE')->firstOrFail()->form_template_id);
        $this->assertSame('DRAFT', $copy->fresh()->status);
    }

    public function test_final_snapshot_and_both_reports_survive_master_changes(): void
    {
        $template = $this->instrument->formTemplate;
        $section = $template->sections()->firstOrFail();
        $extra = $template->sections()->create(['code' => 'EXTRA', 'title' => 'Bagian Kedua Asli', 'subtitle' => 'Subjudul asli', 'sort_order' => 2]);
        foreach (['TEXTAREA', 'LINK', 'FILE', 'YES_NO'] as $i => $type) {
            $extra->questions()->create(['form_template_id' => $template->id, 'code' => $type, 'question_text' => 'Butir asli '.$type, 'question_type' => $type, 'sort_order' => $i + 1, 'is_required' => false, 'is_scored' => false]);
        }
        $supervision = $this->supervision();
        $this->actingAs($this->supervisorUser);
        $answers = [];
        foreach ($template->questions()->with('options')->get() as $question) {
            if ($question->question_type === 'FILE') {
                app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->create('bukti-asli.pdf', 1, 'application/pdf'), $question->id);
            } else {
                $answers[$question->id] = match ($question->question_type) {
                    'SCORE' => $question->options->first()->id,
                    'LINK' => 'https://example.org/asli', 'YES_NO' => false,
                    default => 'Jawaban asli',
                };
            }
        }
        app(DraftService::class)->saveDraft($supervision, $answers, $supervision->fresh()->row_version, [$template->questions()->first()->id => 'Catatan asli']);
        $submitted = app(SubmitService::class)->submit($supervision, $this->supervisorUser, $supervision->fresh()->row_version);
        $snapshot = $submitted->fresh()->submission_snapshot;
        $this->assertSame(2, $snapshot['schema_version']);
        $this->assertSame($template->id, $snapshot['instrument']['id']);
        $this->assertSame(['Pengamatan', 'Bagian Kedua Asli'], array_column($snapshot['instrument']['sections'], 'title'));
        $this->assertSame('Baik', $snapshot['instrument']['sections'][0]['questions'][0]['selected_option']['label']);
        $this->assertSame('3.00', $snapshot['instrument']['sections'][0]['questions'][0]['answer']['numeric_score']);
        $this->assertSame('bukti-asli.pdf', $snapshot['instrument']['sections'][1]['questions'][2]['evidence'][0]['original_filename']);
        $this->assertFalse($snapshot['instrument']['sections'][1]['questions'][3]['answer']['boolean_value']);
        $serialized = json_encode($snapshot, JSON_THROW_ON_ERROR);
        foreach (['file_path', 'storage_disk', 'base64', 'password', 'session', base_path()] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
        $pdf = app(SupervisionResultPdfService::class);
        $before = $pdf->reportData($submitted->fresh());
        DB::table('form_sections')->where('id', $section->id)->update(['title' => 'MASTER BERUBAH', 'sort_order' => 10]);
        DB::table('questions')->where('form_template_id', $template->id)->update(['question_text' => 'MASTER BERUBAH']);
        DB::table('question_options')->where('question_id', $template->questions()->first()->id)->update(['label' => 'MASTER BERUBAH', 'numeric_score' => 0]);
        DB::table('supervision_answers')->where('supervision_id', $submitted->id)->update(['text_value' => 'MASTER BERUBAH', 'notes' => 'MASTER BERUBAH']);
        $template->update(['name' => 'MASTER BERUBAH']);
        $this->target->update(['name' => 'MASTER BERUBAH']);
        $this->instrument->policy->update(['strategy' => 'LINEAR_1_4']);
        DB::table('supervision_evidence')->whereIn('supervision_answer_id', $submitted->answers()->pluck('id'))->update(['original_filename' => 'MASTER BERUBAH']);
        $fresh = $submitted->fresh();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $after = $pdf->reportData($fresh);
        $this->assertSame([], DB::getQueryLog(), 'A complete final snapshot must render without reading mutable master data.');
        DB::disableQueryLog();
        $this->assertSame($before['structuredSections'], $after['structuredSections']);
        $this->assertSame($before['policyName'], $after['policyName']);
        $html = $pdf->generate($submitted->fresh())->getDomPDF()->getDom()->saveHTML();
        $this->assertStringContainsString('Pengamatan pengujian', $html);
        $this->assertStringContainsString('Baik', $html);
        $this->assertStringContainsString('bukti-asli.pdf', $html);
        $this->assertStringContainsString('Catatan asli', $html);
        $this->assertStringNotContainsString('MASTER BERUBAH', $html);
        $this->assertStringNotContainsString('Data legacy:', $html);
        $this->assertLessThan(strpos($html, 'Bagian Kedua Asli'), strpos($html, 'Pengamatan pengujian'));
        $this->get(route('supervisor.supervisions.show', $submitted))->assertOk()->assertSee('Pengamatan pengujian')->assertSee('Catatan asli')->assertDontSee('MASTER BERUBAH');
        $this->assertSame($snapshot, $submitted->fresh()->submission_snapshot);
    }

    public function test_stage_two_pdf_uses_each_stages_own_snapshot_after_master_mutation(): void
    {
        $first = $this->supervision();
        $this->actingAs($this->supervisorUser);
        $this->answerSupervision($first);
        $first = app(SubmitService::class)->submit($first, $this->supervisorUser, 1);
        $copy = app(InstrumentVersionService::class)->duplicate($first->formTemplate);
        $copy->questions()->firstOrFail()->options()->firstOrFail()->update(['label' => 'Hasil khusus T2', 'numeric_score' => 4]);
        $instrument = $this->secondStage->instruments()->firstOrFail();
        $instrument->update(['form_template_id' => $copy->id]);
        $this->secondStage->update(['status' => 'FINALIZED']);
        $this->travelTo(Carbon::parse('2031-01-06 10:00:00', 'Asia/Jakarta'));
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->secondStage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id,
            'teaching_occurrence_id' => $this->target->teachingSchedules()->firstOrFail()->occurrences()->where('occurs_on', '2031-01-06')->firstOrFail()->id,
            'status' => 'FINAL', 'assignment_type' => 'MANUAL',
        ]);
        $second = Supervision::create(['supervision_assignment_id' => $assignment->id,
            'stage_instrument_id' => $instrument->id, 'form_template_id' => $copy->id,
            'scoring_policy_id' => $instrument->scoring_policy_id, 'status' => 'DRAFT', 'row_version' => 1]);
        $question = $copy->questions()->firstOrFail();
        app(DraftService::class)->saveDraft($second, [$question->id => $question->options()->firstOrFail()->id], 1);
        $second = app(SubmitService::class)->submit($second, $this->supervisorUser, $second->fresh()->row_version);
        DB::table('questions')->whereIn('form_template_id', [$first->form_template_id, $copy->id])->update(['question_text' => 'MASTER BERUBAH']);
        $html = app(SupervisionResultPdfService::class)->generate($second->fresh())->getDomPDF()->getDom()->saveHTML();
        $this->assertStringContainsString('NILAI TAHAP 1', $html);
        $this->assertStringContainsString('NILAI TAHAP 2', $html);
        $this->assertStringContainsString('3.00', $html);
        $this->assertStringContainsString('4.00', $html);
        $this->assertStringContainsString('Baik', $html);
        $this->assertStringContainsString('Hasil khusus T2', $html);
        $this->assertSame(1, substr_count($html, 'Pengamatan pengujian'));
        $this->assertStringNotContainsString('MASTER BERUBAH', $html);
        $this->assertStringNotContainsString('Data legacy:', $html);
    }

    public function test_legacy_report_is_marked_without_backfilling_the_old_snapshot(): void
    {
        $supervision = $this->supervision();
        $partial = ['answers' => [], 'metadata' => ['form_template_version' => 1]];
        $supervision->update(['status' => 'SUBMITTED', 'submission_snapshot' => $partial]);
        $html = app(SupervisionResultPdfService::class)->generate($supervision)->getDomPDF()->getDom()->saveHTML();
        $this->assertStringContainsString('Data legacy:', $html);
        $this->assertStringContainsString('Pengamatan pengujian', $html);
        $this->assertSame($partial, $supervision->fresh()->submission_snapshot);
    }
}
