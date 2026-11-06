<?php

namespace Tests\Feature;

use App\Models\PeriodSupervisor;
use App\Models\Question;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SystemAudit;
use App\Services\Supervision\DraftService;
use App\Services\Supervision\EvidenceService;
use App\Services\Supervision\SubmitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class ReopenSupervisionTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
        Storage::fake('local');
    }

    public function test_preview_shows_the_selected_submission_without_changing_it(): void
    {
        $supervision = $this->submittedSupervision();
        $before = $supervision->fresh()->load('answers')->toArray();
        $auditCount = SystemAudit::count();

        $this->artisan('supervisions:reopen', ['supervision' => $supervision->id])
            ->expectsOutput('ID penilaian: '.$supervision->id)
            ->expectsOutput('Dosen dinilai: '.$this->target->name)
            ->expectsOutput('Supervisor: '.$this->supervisor->name)
            ->expectsOutputToContain('Pratinjau saja.')->assertSuccessful();

        $this->assertSame($before, $supervision->fresh()->load('answers')->toArray());
        $this->assertSame($auditCount, SystemAudit::count());
    }

    public function test_reopening_only_the_selected_submission_preserves_answers_evidence_and_history(): void
    {
        $template = $this->instrument->formTemplate;
        $fileQuestion = $template->sections()->firstOrFail()->questions()->create([
            'form_template_id' => $template->id, 'code' => 'FILE', 'question_text' => 'Bukti supervisi',
            'question_type' => 'FILE', 'sort_order' => 2, 'is_required' => false, 'is_scored' => false,
        ]);
        $supervision = $this->submittedSupervision($fileQuestion);
        $other = $this->otherSubmittedSupervision();
        $before = $supervision->fresh();
        $answersBefore = $before->answers()->orderBy('id')->get()->toArray();
        $evidenceBefore = $before->evidence()->get()->toArray();
        $assignmentBefore = $before->assignment->toArray();
        $otherBefore = $other->fresh()->toArray();

        $this->artisan('supervisions:reopen', [
            'supervision' => $supervision->id, '--reason' => 'Tidak sengaja menekan Submit.', '--apply' => true,
        ])->expectsOutputToContain('berhasil dibuka kembali menjadi DRAFT')->assertSuccessful();

        $reopened = $supervision->fresh();
        $this->assertSame('DRAFT', $reopened->status);
        $this->assertNull($reopened->final_score);
        $this->assertNull($reopened->raw_score);
        $this->assertNull($reopened->scored_question_count);
        $this->assertNull($reopened->submitted_by);
        $this->assertNull($reopened->submitted_at);
        $this->assertNull($reopened->submission_snapshot);
        $this->assertNotNull($reopened->draft_started_at);
        $this->assertSame($before->row_version + 1, $reopened->row_version);
        $this->assertSame($before->form_template_id, $reopened->form_template_id);
        $this->assertSame($answersBefore, $reopened->answers()->orderBy('id')->get()->toArray());
        $this->assertSame($evidenceBefore, $reopened->evidence()->get()->toArray());
        Storage::disk('local')->assertExists($evidenceBefore[0]['file_path']);
        $this->assertSame($assignmentBefore, $reopened->assignment->toArray());
        $this->assertSame($otherBefore, $other->fresh()->toArray());
        $audit = SystemAudit::where('event', 'REOPEN_SUPERVISION')->sole();
        $this->assertNull($audit->user_id);
        $this->assertSame('Artisan: supervisions:reopen', $audit->user_agent);
        $this->assertSame($supervision->id, $audit->entity_id);
        $this->assertSame('Tidak sengaja menekan Submit.', $audit->reason);
        $this->assertSame($before->submission_snapshot, $audit->old_values['submission_snapshot']);
        $this->assertSame($before->final_score, $audit->old_values['final_score']);
        $this->assertCount(count($answersBefore), $audit->old_values['answers']);
        $this->assertSame($evidenceBefore[0]['id'], $audit->old_values['evidence'][0]['id']);
        $this->assertSame('DRAFT', $audit->new_values['status']);

        $this->actingAs($this->supervisorUser)->get(route('supervisor.supervisions.form', $reopened->assignment))->assertOk();
        $this->postJson(route('supervisor.supervisions.draft', $reopened), [
            'answers' => [], 'row_version' => $before->row_version,
        ])->assertConflict();
        $this->assertSame($answersBefore, $reopened->answers()->orderBy('id')->get()->toArray());

        $reopenedBefore = $reopened->fresh()->toArray();
        $this->artisan('supervisions:reopen', [
            'supervision' => $supervision->id, '--reason' => 'Percobaan ulang', '--apply' => true,
        ])->assertFailed();
        $this->assertSame($reopenedBefore, $reopened->fresh()->toArray());
        $this->assertSame(1, SystemAudit::where('event', 'REOPEN_SUPERVISION')->count());
    }

    public function test_supervisor_can_edit_and_submit_again_while_the_original_snapshot_remains_archived(): void
    {
        $question = $this->instrument->formTemplate->questions()->firstOrFail();
        $newOption = $question->options()->create(['value' => '4', 'label' => 'Sangat Baik', 'numeric_score' => 4, 'sort_order' => 2]);
        $supervision = $this->submittedSupervision();
        $originalSnapshot = $supervision->fresh()->submission_snapshot;
        $this->artisan('supervisions:reopen', [
            'supervision' => $supervision->id, '--reason' => 'Salah submit', '--apply' => true,
        ])->assertSuccessful();

        $this->actingAs($this->supervisorUser);
        $supervision->refresh();
        app(DraftService::class)->saveDraft($supervision, [$question->id => $newOption->id], $supervision->row_version);
        $submitted = app(SubmitService::class)->submit($supervision->fresh(), $this->supervisorUser, $supervision->fresh()->row_version);

        $this->assertSame('SUBMITTED', $submitted->status);
        $this->assertSame('4.000000', $submitted->final_score);
        $this->assertNotSame($originalSnapshot, $submitted->submission_snapshot);
        $this->assertSame($originalSnapshot, SystemAudit::where('event', 'REOPEN_SUPERVISION')->sole()->old_values['submission_snapshot']);
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    public function test_applying_requires_a_reason_and_keeps_the_submission_unchanged(string $reason): void
    {
        $supervision = $this->submittedSupervision();
        $before = $supervision->fresh()->toArray();
        $this->artisan('supervisions:reopen', [
            'supervision' => $supervision->id, '--reason' => $reason, '--apply' => true,
        ])->assertFailed();
        $this->assertSame($before, $supervision->fresh()->toArray());
        $this->assertSame(0, SystemAudit::where('event', 'REOPEN_SUPERVISION')->count());
    }

    #[TestWith(['period_closed'])]
    #[TestWith(['stage_expired'])]
    #[TestWith(['stage_not_finalized'])]
    #[TestWith(['assignment_cancelled'])]
    #[TestWith(['supervisor_inactive'])]
    #[TestWith(['membership_revoked'])]
    public function test_inaccessible_assignments_are_not_reopened(string $restriction): void
    {
        $supervision = $this->submittedSupervision();
        match ($restriction) {
            'period_closed' => $this->period->update(['status' => 'CLOSED']),
            'stage_expired' => $this->stage->update(['ends_on' => now()->subDay()]),
            'stage_not_finalized' => $this->stage->update(['status' => 'DRAFT']),
            'assignment_cancelled' => $supervision->assignment->update(['status' => 'CANCELLED']),
            'supervisor_inactive' => $this->supervisorUser->update(['is_active' => false]),
            'membership_revoked' => PeriodSupervisor::where('supervision_period_id', $this->period->id)->where('lecturer_id', $this->supervisor->id)->update(['is_active' => false]),
        };
        $before = $supervision->fresh()->toArray();

        $this->artisan('supervisions:reopen', [
            'supervision' => $supervision->id, '--reason' => 'Salah submit', '--apply' => true,
        ])->assertFailed();

        $this->assertSame($before, $supervision->fresh()->toArray());
        $this->assertSame(0, SystemAudit::where('event', 'REOPEN_SUPERVISION')->count());
    }

    public function test_a_later_final_stage_prevents_reopening_the_previous_result(): void
    {
        $supervision = $this->submittedSupervision();
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->secondStage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'status' => 'FINAL', 'assignment_type' => 'MANUAL',
        ]);
        Supervision::create([
            'supervision_assignment_id' => $assignment->id, 'stage_instrument_id' => $this->instrument->id,
            'form_template_id' => $this->instrument->form_template_id, 'scoring_policy_id' => $this->instrument->scoring_policy_id,
            'status' => 'SUBMITTED', 'row_version' => 1, 'final_score' => 4,
        ]);
        $before = $supervision->fresh()->toArray();

        $this->artisan('supervisions:reopen', [
            'supervision' => $supervision->id, '--reason' => 'Salah submit', '--apply' => true,
        ])->expectsOutputToContain('tahap berikutnya')->assertFailed();
        $this->assertSame($before, $supervision->fresh()->toArray());
    }

    public function test_audit_failure_rolls_back_the_reopening(): void
    {
        $supervision = $this->submittedSupervision();
        $before = $supervision->fresh()->toArray();
        $dispatcher = SystemAudit::getEventDispatcher();
        $failingDispatcher = clone $dispatcher;
        $failingDispatcher->listen('eloquent.creating: '.SystemAudit::class, function (): void {
            throw new RuntimeException('Audit unavailable');
        });
        SystemAudit::setEventDispatcher($failingDispatcher);

        try {
            $this->artisan('supervisions:reopen', [
                'supervision' => $supervision->id, '--reason' => 'Salah submit', '--apply' => true,
            ])->run();
            $this->fail('Reopening must fail if the original result cannot be archived.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            SystemAudit::setEventDispatcher($dispatcher);
        }

        $this->assertSame($before, $supervision->fresh()->toArray());
        $this->assertSame(0, SystemAudit::where('event', 'REOPEN_SUPERVISION')->count());
    }

    public function test_an_unknown_submission_id_does_not_change_any_results(): void
    {
        $supervision = $this->submittedSupervision();
        $before = $supervision->fresh()->toArray();
        $this->artisan('supervisions:reopen', [
            'supervision' => 999999999, '--reason' => 'Salah submit', '--apply' => true,
        ])->expectsOutputToContain('tidak ditemukan')->assertFailed();
        $this->assertSame($before, $supervision->fresh()->toArray());
    }

    private function submittedSupervision(?Question $fileQuestion = null): Supervision
    {
        $this->stage->update(['status' => 'FINALIZED']);
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'status' => 'FINAL', 'assignment_type' => 'MANUAL',
        ]);
        $supervision = Supervision::create([
            'supervision_assignment_id' => $assignment->id, 'stage_instrument_id' => $this->instrument->id,
            'form_template_id' => $this->instrument->form_template_id, 'scoring_policy_id' => $this->instrument->scoring_policy_id,
            'status' => 'DRAFT', 'row_version' => 1,
        ]);
        $this->actingAs($this->supervisorUser);
        if ($fileQuestion) {
            app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->create('bukti.pdf', 1, 'application/pdf'), $fileQuestion->id);
        }
        $question = $this->instrument->formTemplate->questions()->where('question_type', 'SCORE')->firstOrFail();
        app(DraftService::class)->saveDraft($supervision, [$question->id => $question->options()->firstOrFail()->id], $supervision->fresh()->row_version, [$question->id => 'Catatan pengamatan asli.']);
        $submitted = app(SubmitService::class)->submit($supervision->fresh(), $this->supervisorUser, $supervision->fresh()->row_version);
        $this->actingAs($this->admin);

        return $submitted;
    }

    private function otherSubmittedSupervision(): Supervision
    {
        $target = $this->createSchedulingTarget('Dosen Lain');
        $occurrence = $target->teachingSchedules()->firstOrFail()->occurrences()->firstOrFail();
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $occurrence->id,
            'status' => 'FINAL', 'assignment_type' => 'MANUAL',
        ]);

        return Supervision::create([
            'supervision_assignment_id' => $assignment->id, 'stage_instrument_id' => $this->instrument->id,
            'form_template_id' => $this->instrument->form_template_id, 'scoring_policy_id' => $this->instrument->scoring_policy_id,
            'status' => 'SUBMITTED', 'row_version' => 1, 'final_score' => 4,
            'submission_snapshot' => ['legacy' => 'Unrelated final result'],
        ]);
    }
}
