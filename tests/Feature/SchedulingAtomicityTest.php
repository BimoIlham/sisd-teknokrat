<?php

namespace Tests\Feature;

use App\Http\Controllers\Lpm\LecturerDirectoryController;
use App\Models\GeneratedDocument;
use App\Models\SchedulingState;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Models\TeachingSchedule;
use App\Services\Reporting\SupervisorAssignmentLetterService;
use App\Services\ScheduleImport\ScheduleCommitService;
use App\Services\ScheduleImport\ScheduleCourseResolver;
use App\Services\Scheduling\ManualOverrideService;
use App\Services\Scheduling\StageFinalizationService;
use App\Services\Supervision\SubmitService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SchedulingAtomicityTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
        Storage::fake('local');
    }

    private function assign(): SupervisionAssignment
    {
        return app(ManualOverrideService::class)->assign($this->stage, $this->target->id, $this->supervisor->id, $this->occurrence->id, $this->admin);
    }

    public function test_two_activations_leave_only_one_active_period(): void
    {
        $this->period->update(['status' => 'CLOSED']);
        $other = $this->period->replicate();
        $other->name = 'Periode Pengujian Kedua';
        $other->save();
        $this->post(route('lpm.periods.toggle_status', $this->period))->assertSessionHas('success');
        $this->post(route('lpm.periods.toggle_status', $other))->assertSessionHas('success');
        $this->assertSame(1, SupervisionPeriod::where('status', 'ACTIVE')->count());
        $this->assertSame('CLOSED', $this->period->fresh()->status);
        $this->assertSame('ACTIVE', $other->fresh()->status);
    }

    public function test_manual_override_rechecks_conflicts_instead_of_reusing_cached_state(): void
    {
        $other = $this->createSchedulingTarget('Dosen Bentrok');
        $otherOccurrence = $other->teachingSchedules()->first()->occurrences()->where('occurs_on', '2030-10-07')->first();
        $service = app(ManualOverrideService::class);
        $service->assign($this->stage, $this->target->id, $this->supervisor->id, $this->occurrence->id, $this->admin);
        $this->expectException(ValidationException::class);
        try {
            $service->assign($this->stage, $other->id, $this->supervisor->id, $otherOccurrence->id, $this->admin);
        } finally {
            $this->assertSame(0, SupervisionAssignment::where('target_lecturer_id', $other->id)->count());
            $this->assertSame(2, SupervisionAssignment::where('target_lecturer_id', $this->target->id)->count());
        }
    }

    public function test_manual_assignment_reads_finalized_status_again_from_database(): void
    {
        $this->stage->fresh()->update(['status' => 'FINALIZED']);
        $this->expectException(ValidationException::class);
        $this->assign();
    }

    public function test_finalization_from_either_stage_is_atomic_and_idempotent(): void
    {
        $this->assign();
        $service = app(StageFinalizationService::class);
        $service->finalize($this->secondStage, $this->admin);
        $service->finalize($this->stage, $this->admin);
        $this->assertSame(['FINALIZED'], $this->period->stages()->pluck('status')->unique()->values()->all());
        $this->assertSame(['FINAL'], SupervisionAssignment::whereIn('supervision_stage_id', [$this->stage->id, $this->secondStage->id])->pluck('status')->unique()->values()->all());
        $this->assertSame(2, GeneratedDocument::where('supervision_period_id', $this->period->id)->count());
        $this->assertSame(2, GeneratedDocument::where('supervision_period_id', $this->period->id)->pluck('document_number')->unique()->count());
    }

    public function test_partial_finalization_is_rolled_back_when_counterpart_is_missing(): void
    {
        $this->assign();
        $this->secondStage->assignments()->update(['status' => 'CANCELLED']);
        $this->expectException(ValidationException::class);
        try {
            app(StageFinalizationService::class)->finalize($this->secondStage, $this->admin);
        } finally {
            $this->assertSame(['DRAFT'], $this->period->stages()->pluck('status')->unique()->values()->all());
            $this->assertSame(0, GeneratedDocument::where('supervision_period_id', $this->period->id)->count());
        }
    }

    public function test_reopen_cannot_pass_a_supervision_submitted_after_the_stage_model_was_loaded(): void
    {
        $assignment = $this->assign();
        app(StageFinalizationService::class)->finalize($this->stage, $this->admin);
        $oldStage = $this->stage->fresh();
        $supervision = Supervision::where('supervision_assignment_id', $assignment->id)->firstOrFail();
        $this->answerSupervision($supervision);
        $this->actingAs($this->supervisorUser);
        $submitted = app(SubmitService::class)->submit($supervision, $this->supervisorUser, $supervision->row_version);
        $this->assertSame('SUBMITTED', $submitted->status);
        $this->assertSame('3.000000', $submitted->final_score);
        $this->assertSame(2, $submitted->row_version);
        $this->expectException(ValidationException::class);
        try {
            app(StageFinalizationService::class)->reopen($oldStage, $this->admin);
        } finally {
            $this->assertSame('FINALIZED', $this->stage->fresh()->status);
            $this->assertSame('FINAL', $assignment->fresh()->status);
            $this->assertSame('SUBMITTED', $submitted->fresh()->status);
        }
    }

    public function test_submit_is_denied_when_reopen_completes_first(): void
    {
        $assignment = $this->assign();
        app(StageFinalizationService::class)->finalize($this->stage, $this->admin);
        $supervision = Supervision::where('supervision_assignment_id', $assignment->id)->firstOrFail();
        $this->answerSupervision($supervision);
        app(StageFinalizationService::class)->reopen($this->stage, $this->admin);
        $this->actingAs($this->supervisorUser);
        $this->expectException(AuthorizationException::class);
        try {
            app(SubmitService::class)->submit($supervision, $this->supervisorUser, $supervision->row_version);
        } finally {
            $this->assertSame('DRAFT', $supervision->fresh()->status);
            $this->assertSame(1, $supervision->fresh()->row_version);
        }
    }

    public function test_letter_allocation_preserves_supervisor_prefix_and_sequence_meaning(): void
    {
        DB::table('assignment_letter_sequences')->insertOrIgnore(['year' => 2030, 'last_number' => 99]);
        $this->assign();
        app(StageFinalizationService::class)->finalize($this->stage, $this->admin);
        $numbers = GeneratedDocument::where('supervision_period_id', $this->period->id)->orderBy('supervision_stage_id')->pluck('document_number')->all();
        $this->assertSame(['001/LPM-UTI/X/2030', '001/LPM-UTI/X/2030/T2'], $numbers);
        $this->assertSame(99, DB::table('assignment_letter_sequences')->where('year', 2030)->value('last_number'));
        app(SupervisorAssignmentLetterService::class)->issueForStage($this->stage, $this->admin);
        $this->assertSame(2, GeneratedDocument::where('supervision_period_id', $this->period->id)->count());
    }

    public function test_letter_pdf_refresh_does_not_render_inside_its_transaction(): void
    {
        $this->assign();
        app(StageFinalizationService::class)->finalize($this->stage, $this->admin);
        $level = DB::transactionLevel();
        $original = app(SupervisorAssignmentLetterService::class);
        $service = Mockery::mock(SupervisorAssignmentLetterService::class)->makePartial();
        $service->shouldReceive('render')->once()->andReturnUsing(function (array $snapshot) use ($level, $original) {
            $this->assertSame($level, DB::transactionLevel());

            return $original->render($snapshot);
        });
        $this->assertSame(1, $service->refreshForStage($this->stage));
    }

    public function test_double_import_commit_reloads_status_and_is_idempotent(): void
    {
        $import = $this->createSchedulingImport();
        $staleReference = $import->fresh();
        $service = app(ScheduleCommitService::class);
        $this->assertSame(1, $service->commit($import));
        $this->assertSame(0, $service->commit($staleReference));
        $this->assertSame(1, TeachingSchedule::where('source_import_id', $import->id)->count());
        $this->assertSame('COMMITTED', $import->fresh()->status);
        $this->assertSame(11, SchedulingState::findOrFail(1)->revision);
    }

    public function test_import_commit_checks_latest_row_approval(): void
    {
        $import = $this->createSchedulingImport();
        $import->rows()->update(['is_approved' => false]);
        $this->expectException(ValidationException::class);
        try {
            app(ScheduleCommitService::class)->commit($import);
        } finally {
            $this->assertSame('PENDING_REVIEW', $import->fresh()->status);
            $this->assertSame(0, TeachingSchedule::where('source_import_id', $import->id)->count());
        }
    }

    public function test_import_failure_rolls_back_rows_schedules_status_and_revision(): void
    {
        $import = $this->createSchedulingImport();
        $resolver = Mockery::mock(ScheduleCourseResolver::class);
        $resolver->shouldReceive('resolve')->once()->andThrow(new RuntimeException('Synthetic commit failure'));
        $this->app->instance(ScheduleCourseResolver::class, $resolver);
        $this->expectException(RuntimeException::class);
        try {
            app(ScheduleCommitService::class)->commit($import);
        } finally {
            $this->assertSame('PENDING_REVIEW', $import->fresh()->status);
            $this->assertNull($import->rows()->first()->committed_schedule_id);
            $this->assertSame(0, TeachingSchedule::where('source_import_id', $import->id)->count());
            $this->assertSame(10, SchedulingState::findOrFail(1)->revision);
        }
    }

    public function test_schedule_delete_rechecks_active_status_after_lock_instead_of_using_loaded_model(): void
    {
        $loadedSchedule = $this->occurrence->teachingSchedule;
        $loadedSchedule->fresh()->update(['is_active' => false]);
        try {
            app(LecturerDirectoryController::class)->destroySchedule($this->target, $this->period, $loadedSchedule);
            $this->fail('A schedule changed after loading must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertNotNull($loadedSchedule->fresh());
        $this->assertSame(10, SchedulingState::findOrFail(1)->revision);
    }

    public function test_letter_issuance_rechecks_finalized_status_after_waiting_for_parent_locks(): void
    {
        $this->assign();
        app(StageFinalizationService::class)->finalize($this->stage, $this->admin);
        $loadedStage = $this->stage->fresh();
        app(StageFinalizationService::class)->reopen($this->secondStage, $this->admin);
        $this->expectException(ValidationException::class);
        try {
            app(SupervisorAssignmentLetterService::class)->issueForStage($loadedStage, $this->admin);
        } finally {
            $this->assertSame(0, GeneratedDocument::where('supervision_stage_id', $this->stage->id)->where('status', 'READY')->count());
        }
    }
}
