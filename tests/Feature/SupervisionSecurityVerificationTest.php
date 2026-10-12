<?php

namespace Tests\Feature;

use App\Models\PeriodSupervisor;
use App\Models\Question;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Services\Supervision\DraftService;
use App\Services\Supervision\EvidenceService;
use App\Services\Supervision\SubmitService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SupervisionSecurityVerificationTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    private Supervision $supervision;

    private Question $fileQuestion;

    private Question $optionalFileQuestion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
        $section = $this->instrument->formTemplate->sections()->firstOrFail();
        foreach ([true, false] as $required) {
            $question = $section->questions()->create([
                'form_template_id' => $this->instrument->form_template_id,
                'code' => $required ? 'FILE-REQUIRED' : 'FILE-OPTIONAL',
                'question_text' => 'Bukti pengujian', 'question_type' => 'FILE',
                'is_required' => $required, 'is_scored' => false, 'sort_order' => $required ? 2 : 3,
            ]);
            if ($required) {
                $this->fileQuestion = $question;
            } else {
                $this->optionalFileQuestion = $question;
            }
        }
        $this->stage->update(['status' => 'FINALIZED']);
        $this->supervision = $this->createAssessment($this->target->id, $this->occurrence->id);
        $this->answerSupervision($this->supervision);
        $this->actingAs($this->supervisorUser);
        Storage::fake('local');
    }

    private function createAssessment(int $targetId, int $occurrenceId): Supervision
    {
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $targetId,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $occurrenceId,
            'status' => 'FINAL', 'assignment_type' => 'MANUAL',
        ]);

        return Supervision::create([
            'supervision_assignment_id' => $assignment->id, 'stage_instrument_id' => $this->instrument->id,
            'form_template_id' => $this->instrument->form_template_id, 'scoring_policy_id' => $this->instrument->scoring_policy_id,
            'status' => 'DRAFT', 'row_version' => 1,
        ]);
    }

    private function assertIncomplete(): void
    {
        $version = $this->supervision->fresh()->row_version;
        foreach ([
            fn () => app(SubmitService::class)->preview($this->supervision),
            fn () => app(SubmitService::class)->submit($this->supervision, $this->supervisorUser, $version),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Required FILE needs its own stored evidence.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('form', $exception->errors());
            }
        }
        $fresh = $this->supervision->fresh();
        $this->assertSame('DRAFT', $fresh->status);
        $this->assertSame($version, $fresh->row_version);
        $this->assertNull($fresh->snapshot_json);
    }

    #[TestWith(['missing'])]
    #[TestWith(['text'])]
    #[TestWith(['note'])]
    #[TestWith(['url'])]
    #[TestWith(['stale_id'])]
    public function test_required_file_rejects_non_file_payloads(string $payload): void
    {
        $value = match ($payload) {
            'text' => 'bukti.pdf',
            'url' => 'https://example.test/bukti.pdf',
            'stale_id' => '999999',
            default => null,
        };
        app(DraftService::class)->saveDraft($this->supervision,
            $value === null ? [] : [$this->fileQuestion->id => $value], 1,
            $payload === 'note' ? [$this->fileQuestion->id => 'Bukti menyusul.'] : []);
        $this->assertSame(0, $this->supervision->evidence()->count());
        $this->assertIncomplete();
    }

    public function test_valid_evidence_completes_required_file_without_requiring_optional_file(): void
    {
        $evidence = app(EvidenceService::class)->storeEvidence($this->supervision,
            UploadedFile::fake()->image('bukti.jpg'), $this->fileQuestion->id);
        $this->assertTrue($evidence->isReady());
        $this->assertSame('image/jpeg', $evidence->mime_type);
        $this->assertGreaterThan(0, $evidence->size_bytes);
        $this->assertSame(hash_file('sha256', Storage::disk('local')->path($evidence->file_path)), $evidence->sha256);
        $this->assertNotNull($evidence->validated_at);
        $this->assertSame(0, $this->supervision->answers()->where('question_id', $this->optionalFileQuestion->id)->count());
        $review = app(SubmitService::class)->preview($this->supervision);
        $submitted = app(SubmitService::class)->submit($this->supervision, $this->supervisorUser, $review['row_version']);
        $this->assertSame('SUBMITTED', $submitted->status);
        $this->assertSame(1, count($submitted->snapshot_json['evidence']));
    }

    public function test_evidence_for_another_file_question_does_not_complete_required_file(): void
    {
        app(EvidenceService::class)->storeEvidence($this->supervision,
            UploadedFile::fake()->image('lain.jpg'), $this->optionalFileQuestion->id);
        $this->assertIncomplete();
    }

    public function test_evidence_for_the_same_question_in_another_supervision_does_not_complete_this_one(): void
    {
        $target = $this->createSchedulingTarget('Target kedua');
        $occurrence = $target->teachingSchedules()->firstOrFail()->occurrences()->firstOrFail();
        $other = $this->createAssessment($target->id, $occurrence->id);
        $evidence = app(EvidenceService::class)->storeEvidence($other,
            UploadedFile::fake()->image('milik-lain.jpg'), $this->fileQuestion->id);
        app(DraftService::class)->saveDraft($this->supervision, [$this->fileQuestion->id => (string) $evidence->id], 1);
        $this->assertSame(0, $this->supervision->evidence()->count());
        $this->assertSame(1, $other->evidence()->count());
        $this->assertIncomplete();
    }

    public function test_deleted_evidence_record_with_a_remaining_file_does_not_complete_required_file(): void
    {
        $evidence = app(EvidenceService::class)->storeEvidence($this->supervision,
            UploadedFile::fake()->image('usang.jpg'), $this->fileQuestion->id);
        $evidence->delete();
        Storage::disk('local')->assertExists($evidence->file_path);
        $this->assertIncomplete();
    }

    #[TestWith(['REJECTED'])]
    #[TestWith(['DELETED'])]
    #[TestWith(['unknown_disk'])]
    public function test_non_ready_status_or_unconfigured_disk_does_not_complete_required_file(string $invalid): void
    {
        $evidence = app(EvidenceService::class)->storeEvidence($this->supervision,
            UploadedFile::fake()->image('invalid.jpg'), $this->fileQuestion->id);
        $evidence->update($invalid === 'unknown_disk' ? ['storage_disk' => 'unconfigured'] : ['status' => $invalid]);
        $this->assertIncomplete();
    }

    #[TestWith(['cancelled'])]
    #[TestWith(['not_started'])]
    #[TestWith(['ended'])]
    #[TestWith(['period_closed'])]
    public function test_services_recheck_cancelled_assignments_and_input_windows_with_stale_models(string $restriction): void
    {
        $evidence = app(EvidenceService::class)->storeEvidence($this->supervision,
            UploadedFile::fake()->image('valid.jpg'), $this->fileQuestion->id);
        $this->supervision->load('assignment.supervisionStage.period');
        $evidence->load('answer.supervision.assignment.supervisionStage.period');
        $version = $this->supervision->fresh()->row_version;
        match ($restriction) {
            'cancelled' => SupervisionAssignment::whereKey($this->supervision->supervision_assignment_id)->update(['status' => 'CANCELLED']),
            'period_closed' => $this->period->update(['status' => 'CLOSED']),
            'not_started' => $this->stage->update(['starts_on' => now()->addDay(), 'ends_on' => now()->addDays(2)]),
            'ended' => $this->stage->update(['starts_on' => now()->subDays(2), 'ends_on' => now()->subDay()]),
        };
        foreach ([
            fn () => app(DraftService::class)->saveDraft($this->supervision, [], $version),
            fn () => app(EvidenceService::class)->storeEvidence($this->supervision, UploadedFile::fake()->image('denied.jpg'), $this->fileQuestion->id),
            fn () => app(EvidenceService::class)->deleteEvidence($evidence),
            fn () => app(SubmitService::class)->preview($this->supervision),
            fn () => app(SubmitService::class)->submit($this->supervision, $this->supervisorUser, $version),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Services must reject stale eligible models.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($version, $this->supervision->fresh()->row_version);
        $this->assertSame('DRAFT', $this->supervision->fresh()->status);
        $this->assertSame(1, $this->supervision->evidence()->count());
        $this->assertSame([$evidence->file_path], Storage::disk('local')->allFiles('private/evidence'));
    }

    public function test_revoking_one_period_preserves_other_membership_and_global_role(): void
    {
        $other = $this->period->replicate();
        $other->name = 'Periode lain';
        $other->status = 'CLOSED';
        $other->save();
        PeriodSupervisor::create(['supervision_period_id' => $other->id,
            'lecturer_id' => $this->supervisor->id, 'is_active' => true, 'selected_by' => $this->admin->id]);
        $this->actingAs($this->admin)->delete(route('lpm.supervisors.destroy', $this->supervisor), [
            'period_id' => $this->period->id,
        ])->assertRedirect();
        $this->assertFalse(PeriodSupervisor::where('supervision_period_id', $this->period->id)
            ->where('lecturer_id', $this->supervisor->id)->firstOrFail()->is_active);
        $this->assertTrue(PeriodSupervisor::where('supervision_period_id', $other->id)
            ->where('lecturer_id', $this->supervisor->id)->firstOrFail()->is_active);
        $this->assertTrue($this->supervisorUser->fresh()->hasRole('supervisor'));
        $this->actingAs($this->supervisorUser)->postJson(route('supervisor.supervisions.draft', $this->supervision), [
            'answers' => [], 'row_version' => 1,
        ])->assertForbidden();
        $this->assertSame(1, $this->supervision->fresh()->row_version);
    }
}
