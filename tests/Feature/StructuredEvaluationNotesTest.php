<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\StageInstrument;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\User;
use App\Services\Reporting\SupervisionResultPdfService;
use App\Services\Reporting\SupervisorNoteClassifier;
use App\Services\Supervision\DraftService;
use App\Services\Supervision\EvidenceService;
use App\Services\Supervision\SubmitService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class StructuredEvaluationNotesTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(DemoAcademicSeeder::class);
    }

    public function test_structured_notes_autosave_restore_and_change_workspace_status(): void
    {
        [$user, $supervision, $question] = $this->evaluation();
        $this->actingAs($user)->get(route('supervisor.assignments.index'))->assertOk()->assertSee('Belum Diisi');
        $notes = ['positive' => ' SPADA sudah lengkap. ', 'improvement' => 'Video belum tersedia.', 'suggestion' => 'Tambahkan video pembelajaran.'];
        $this->postJson(route('supervisor.supervisions.draft', $supervision), ['answers' => [$question->id => $notes], 'row_version' => 1])
            ->assertOk()->assertJsonPath('row_version', 2);
        $answer = $supervision->answers()->where('question_id', $question->id)->firstOrFail();
        $this->assertSame('SPADA sudah lengkap.', $answer->evaluation_notes['positive']);
        $this->get(route('supervisor.supervisions.form', $supervision->assignment))->assertOk()
            ->assertSee('Hal Positif')->assertSee('Hal yang Perlu Diperbaiki')->assertSee('Saran Tindak Lanjut')
            ->assertSee('Video belum tersedia.')->assertDontSee('note-'.$question->id, false);
        $this->get(route('supervisor.assignments.index'))->assertOk()->assertSee('Lanjutkan Penilaian')->assertSee('Draf');
        $this->get(route('supervisor.dashboard'))->assertOk()->assertSee('Lanjutkan Penilaian')->assertSee('Draf');
        $this->postJson(route('supervisor.supervisions.draft', $supervision), ['answers' => [$question->id => ['positive' => '', 'improvement' => '', 'suggestion' => '']], 'row_version' => 2])->assertOk();
        $this->get(route('supervisor.assignments.index'))->assertOk()->assertDontSee('Lanjutkan Penilaian')->assertSee('Belum Diisi');
    }

    #[TestWith(['string'])]
    #[TestWith(['nested'])]
    #[TestWith(['unknown'])]
    #[TestWith(['long'])]
    public function test_invalid_structured_notes_are_rejected_without_saving(string $case): void
    {
        [$user, $supervision, $question] = $this->evaluation();
        $value = match ($case) {
            'string' => 'catatan tidak terstruktur',
            'nested' => ['positive' => ['nested']],
            'unknown' => ['unexpected' => 'nilai'],
            'long' => ['positive' => str_repeat('a', 5001)],
        };
        $this->actingAs($user)->postJson(route('supervisor.supervisions.draft', $supervision), ['answers' => [$question->id => $value], 'row_version' => 1])->assertUnprocessable();
        $this->assertSame(1, $supervision->fresh()->row_version);
        $this->assertSame(0, $supervision->answers()->count());
    }

    public function test_only_positive_notes_satisfy_required_evaluation_and_freeze_in_pdf_snapshot(): void
    {
        Storage::fake('local');
        [$user, $supervision, $question] = $this->evaluation();
        $this->actingAs($user);
        $notes = ['positive' => 'Materi SPADA sudah lengkap. <script>alert(1)</script>', 'improvement' => '', 'suggestion' => ''];
        $answers = $this->completeAnswers($supervision, $question, $notes);
        app(DraftService::class)->saveDraft($supervision, $answers, $supervision->fresh()->row_version);
        $this->get(route('supervisor.supervisions.review', $supervision))->assertOk()->assertSee('Hal Positif')->assertDontSee('<script>alert(1)</script>', false);
        $submitted = app(SubmitService::class)->submit($supervision->fresh(), $user, $supervision->fresh()->row_version);
        $this->assertEquals($notes, collect($submitted->submission_snapshot['answers'])->firstWhere('question_id', $question->id)['evaluation_notes']);
        $supervision->answers()->where('question_id', $question->id)->update(['evaluation_notes' => json_encode(['positive' => 'berubah']), 'text_value' => 'berubah']);
        $report = app(SupervisionResultPdfService::class)->reportData($submitted->fresh());
        $frozen = collect($report['structuredSections'])->flatMap(fn ($section) => $section['questions'])->firstWhere('code', $question->code);
        $this->assertEquals($notes, $frozen['evaluation_notes']);
        $this->get(route('supervisor.supervisions.show', $submitted))->assertOk()->assertSee('Hal Positif')->assertDontSee('<script>alert(1)</script>', false);
        $this->assertStringStartsWith('%PDF-', app(SupervisionResultPdfService::class)->generate($submitted->fresh())->output());
    }

    public function test_blank_required_evaluation_is_rejected_at_review(): void
    {
        Storage::fake('local');
        [, $supervision, $question] = $this->evaluation();
        $answers = $this->completeAnswers($supervision, $question, ['positive' => ' ', 'improvement' => '', 'suggestion' => '']);
        app(DraftService::class)->saveDraft($supervision, $answers, $supervision->fresh()->row_version);
        $this->expectException(ValidationException::class);
        app(SubmitService::class)->preview($supervision->fresh());
    }

    public function test_legacy_textarea_keeps_its_existing_format(): void
    {
        [$user, $supervision, $question] = $this->evaluation(false);
        $this->actingAs($user)->postJson(route('supervisor.supervisions.draft', $supervision), ['answers' => [$question->id => 'Catatan lama tetap utuh.'], 'row_version' => 1])->assertOk();
        $answer = $supervision->answers()->firstOrFail();
        $this->assertNull($answer->evaluation_notes);
        $this->assertSame('Catatan lama tetap utuh.', $answer->text_value);
        $this->get(route('supervisor.supervisions.form', $supervision->assignment))->assertOk()->assertSee('Catatan lama tetap utuh.')->assertDontSee('Hal Positif');
    }

    public function test_explicit_positive_intent_never_becomes_an_improvement_finding(): void
    {
        $classifier = app(SupervisorNoteClassifier::class);
        $positive = $classifier->classify('Video belum tersedia.', 'positive');
        $this->assertSame(['positive'], array_values(array_unique(array_column($positive, 'status'))));
        $this->assertSame('improvement', $classifier->classify('Video belum tersedia.', 'improvement')[0]['status']);
        $this->assertSame('suggestion', $classifier->classify('Tambahkan video pembelajaran.', 'suggestion')[0]['status']);
    }

    /** @return array{User, Supervision, Question} */
    private function evaluation(bool $structured = true): array
    {
        $user = User::where('email', 'supervisor1@teknokrat.ac.id')->firstOrFail();
        $this->actingAs($user);
        $stage = SupervisionStage::firstOrFail();
        $stage->update(['status' => 'FINALIZED', 'starts_on' => now('Asia/Jakarta')->subDay(), 'ends_on' => now('Asia/Jakarta')->addDay()]);
        $occurrence = TeachingOccurrence::with('schedule')->whereHas('schedule', fn ($query) => $query->where('lecturer_id', '<>', $user->lecturer_id))->firstOrFail();
        $instrument = StageInstrument::where('supervision_stage_id', $stage->id)->firstOrFail();
        $template = $this->legacyTemplateCopy($instrument->formTemplate);
        $question = $template->questions()->where('question_type', 'TEXTAREA')->firstOrFail();
        $question->update(['is_required' => true, 'validation_rules' => ['evaluation_notes' => $structured]]);
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id, 'target_lecturer_id' => $occurrence->schedule->lecturer_id,
            'supervisor_lecturer_id' => $user->lecturer_id, 'teaching_occurrence_id' => $occurrence->id,
            'assignment_type' => 'MANUAL', 'status' => 'FINAL',
        ]);
        $supervision = Supervision::create([
            'supervision_assignment_id' => $assignment->id, 'stage_instrument_id' => $instrument->id,
            'form_template_id' => $template->id, 'scoring_policy_id' => $instrument->scoring_policy_id,
            'status' => 'DRAFT', 'row_version' => 1,
        ]);

        return [$user, $supervision, $question->fresh()];
    }

    /** @param array<string, string> $notes
     * @return array<int, mixed>
     */
    private function completeAnswers(Supervision $supervision, Question $evaluation, array $notes): array
    {
        $answers = [];
        foreach ($supervision->formTemplate->questions()->with('options')->get() as $question) {
            if ($question->id === $evaluation->id) {
                $answers[$question->id] = $notes;
            } elseif (in_array($question->question_type, ['SCORE', 'YES_NO'], true)) {
                $answers[$question->id] = $question->options->last()->id;
            } elseif ($question->question_type === 'LINK') {
                $answers[$question->id] = 'https://lms.teknokrat.ac.id/course/1';
            } elseif ($question->question_type === 'TEXTAREA') {
                $answers[$question->id] = 'Catatan evaluasi.';
            } elseif ($question->question_type === 'FILE') {
                app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf'), $question->id);
            }
        }

        return $answers;
    }
}
