<?php

namespace Tests\Feature;

use App\Http\Controllers\Lpm\LecturerDirectoryController;
use App\Models\GeneratedDocument;
use App\Models\Lecturer;
use App\Models\SchedulingState;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Models\TeachingOccurrence;
use App\Models\TeachingSchedule;
use App\Services\Reporting\SupervisorAssignmentLetterService;
use App\Services\ScheduleImport\ScheduleCommitService;
use App\Services\ScheduleImport\ScheduleCourseResolver;
use App\Services\Scheduling\ManualOverrideService;
use App\Services\Scheduling\StageFinalizationService;
use App\Services\Supervision\SubmitService;
use Carbon\Carbon;
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

    public function test_manual_override_rebalances_automatic_pairs_in_both_stages(): void
    {
        $otherTarget = $this->createSchedulingTarget('Dosen Otomatis Terdampak');
        $otherSchedule = $otherTarget->teachingSchedules()->firstOrFail();
        $otherStageOneOccurrence = $this->createOccurrence($otherSchedule, '2030-10-14');
        $otherStageTwoOccurrence = $this->createOccurrence($otherSchedule, '2031-01-13');
        $fixedManualTarget = $this->createSchedulingTarget('Dosen Manual Yang Tidak Berubah');
        $fixedManualSchedule = $fixedManualTarget->teachingSchedules()->firstOrFail();
        $fixedManualStageOneOccurrence = $this->createOccurrence($fixedManualSchedule, '2030-10-21');
        $fixedManualStageTwoOccurrence = $this->createOccurrence($fixedManualSchedule, '2031-01-20');
        $fixedManualAssignments = [];
        foreach ([[$this->stage, $fixedManualStageOneOccurrence], [$this->secondStage, $fixedManualStageTwoOccurrence]] as [$stage, $occurrence]) {
            $fixedManualAssignments[] = SupervisionAssignment::create([
                'supervision_stage_id' => $stage->id,
                'target_lecturer_id' => $fixedManualTarget->id,
                'supervisor_lecturer_id' => $this->supervisor->id,
                'teaching_occurrence_id' => $occurrence->id,
                'starts_at' => $occurrence->starts_at,
                'ends_at' => $occurrence->ends_at,
                'assignment_type' => 'MANUAL',
                'is_locked' => true,
                'status' => 'DRAFT',
            ]);
        }

        $oldSource = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id,
            'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id,
            'teaching_occurrence_id' => $this->occurrence->id,
            'assignment_type' => 'AUTO',
            'status' => 'DRAFT',
        ]);
        $oldCounterpart = SupervisionAssignment::create([
            'supervision_stage_id' => $this->secondStage->id,
            'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id,
            'teaching_occurrence_id' => $this->target->teachingSchedules()->firstOrFail()->occurrences()
                ->whereDate('occurs_on', '2031-01-06')->value('id'),
            'assignment_type' => 'AUTO',
            'status' => 'DRAFT',
        ]);
        SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id,
            'target_lecturer_id' => $otherTarget->id,
            'supervisor_lecturer_id' => $this->supervisor->id,
            'teaching_occurrence_id' => $otherTarget->teachingSchedules()->firstOrFail()->occurrences()
                ->whereDate('occurs_on', '2030-10-07')->value('id'),
            'assignment_type' => 'AUTO',
            'status' => 'DRAFT',
        ]);
        SupervisionAssignment::create([
            'supervision_stage_id' => $this->secondStage->id,
            'target_lecturer_id' => $otherTarget->id,
            'supervisor_lecturer_id' => $this->supervisor->id,
            'teaching_occurrence_id' => $otherTarget->teachingSchedules()->firstOrFail()->occurrences()
                ->whereDate('occurs_on', '2031-01-06')->value('id'),
            'assignment_type' => 'AUTO',
            'status' => 'DRAFT',
        ]);

        $result = app(ManualOverrideService::class)->assignWithResults(
            $this->stage,
            $this->target->id,
            $this->supervisor->id,
            $this->occurrence->id,
            $this->admin
        );

        $manualAssignment = $result['assignment']->fresh();
        $manualCounterpart = $this->secondStage->assignments()
            ->where('target_lecturer_id', $this->target->id)
            ->where('status', '<>', 'CANCELLED')
            ->with('teachingOccurrence.teachingSchedule')
            ->firstOrFail();
        $otherStageOneAssignment = $this->stage->assignments()->where('target_lecturer_id', $otherTarget->id)->where('status', '<>', 'CANCELLED')->firstOrFail();
        $otherStageTwoAssignment = $this->secondStage->assignments()->where('target_lecturer_id', $otherTarget->id)->where('status', '<>', 'CANCELLED')->firstOrFail();

        $this->assertSame('MANUAL', $manualAssignment->assignment_type);
        $manualAssignment->load('teachingOccurrence.teachingSchedule');
        $this->assertSame($manualAssignment->supervisor_lecturer_id, $manualCounterpart->supervisor_lecturer_id);
        $this->assertTrue($manualAssignment->teachingOccurrence->hasSameSupervisionSlotAs($manualCounterpart->teachingOccurrence));
        $this->assertNotSame(
            $manualAssignment->teachingOccurrence->occurs_on->toDateString(),
            $manualCounterpart->teachingOccurrence->occurs_on->toDateString()
        );
        $this->assertSame('CANCELLED', $oldSource->fresh()->status);
        $this->assertSame('CANCELLED', $oldCounterpart->fresh()->status);
        $this->assertSame($otherStageOneOccurrence->id, $otherStageOneAssignment->teaching_occurrence_id);
        $this->assertSame($otherStageTwoOccurrence->id, $otherStageTwoAssignment->teaching_occurrence_id);
        $this->assertSame('AUTO', $otherStageOneAssignment->assignment_type);
        $this->assertSame('AUTO', $otherStageTwoAssignment->assignment_type);
        $this->assertSame($fixedManualStageOneOccurrence->id, $fixedManualAssignments[0]->fresh()->teaching_occurrence_id);
        $this->assertSame($fixedManualStageTwoOccurrence->id, $fixedManualAssignments[1]->fresh()->teaching_occurrence_id);
        $this->assertSame('MANUAL', $fixedManualAssignments[0]->fresh()->assignment_type);
        $this->assertSame('MANUAL', $fixedManualAssignments[1]->fresh()->assignment_type);
        $this->assertSame(1, $result['reassigned_count']);
        $this->assertSame([], $result['unassigned_targets']);
    }

    public function test_manual_candidate_can_displace_an_editable_automatic_pair(): void
    {
        $otherTarget = $this->createSchedulingTarget('Dosen Otomatis Yang Digeser');
        $schedule = $otherTarget->teachingSchedules()->firstOrFail();
        foreach ([[$this->stage, '2030-10-07'], [$this->secondStage, '2031-01-06']] as [$stage, $date]) {
            $occurrence = $schedule->occurrences()->whereDate('occurs_on', $date)->firstOrFail();
            SupervisionAssignment::create([
                'supervision_stage_id' => $stage->id,
                'target_lecturer_id' => $otherTarget->id,
                'supervisor_lecturer_id' => $this->supervisor->id,
                'teaching_occurrence_id' => $occurrence->id,
                'starts_at' => $occurrence->starts_at,
                'ends_at' => $occurrence->ends_at,
                'assignment_type' => 'AUTO',
                'status' => 'DRAFT',
            ]);
        }

        $check = app(ManualOverrideService::class)->checkCandidate(
            $this->stage,
            $this->target->id,
            $this->supervisor->id,
            $this->occurrence->id
        );

        $this->assertTrue($check['valid']);
        $this->assertSame([], $check['conflicts']);
        $this->assertSame(1, $check['displaced_count']);
    }

    public function test_manual_candidate_cannot_displace_another_manual_pair(): void
    {
        $otherTarget = $this->createSchedulingTarget('Dosen Manual Yang Tetap');
        $schedule = $otherTarget->teachingSchedules()->firstOrFail();
        foreach ([[$this->stage, '2030-10-07'], [$this->secondStage, '2031-01-06']] as [$stage, $date]) {
            $occurrence = $schedule->occurrences()->whereDate('occurs_on', $date)->firstOrFail();
            SupervisionAssignment::create([
                'supervision_stage_id' => $stage->id,
                'target_lecturer_id' => $otherTarget->id,
                'supervisor_lecturer_id' => $this->supervisor->id,
                'teaching_occurrence_id' => $occurrence->id,
                'starts_at' => $occurrence->starts_at,
                'ends_at' => $occurrence->ends_at,
                'assignment_type' => 'MANUAL',
                'is_locked' => true,
                'status' => 'DRAFT',
            ]);
        }

        $check = app(ManualOverrideService::class)->checkCandidate(
            $this->stage,
            $this->target->id,
            $this->supervisor->id,
            $this->occurrence->id
        );

        $this->assertFalse($check['valid']);
        $this->assertContains('H04: Supervisor telah memiliki penugasan supervisi lain pada waktu yang bersamaan.', $check['conflicts']);
        $this->assertSame(0, $check['displaced_count']);
    }

    public function test_manual_choice_is_saved_and_unreplaced_automatic_target_is_reported(): void
    {
        $otherTarget = $this->createSchedulingTarget('Dosen Tanpa Slot Pengganti');
        $schedule = $otherTarget->teachingSchedules()->firstOrFail();
        $firstOccurrence = $schedule->occurrences()->whereDate('occurs_on', '2030-10-07')->firstOrFail();
        $secondOccurrence = $schedule->occurrences()->whereDate('occurs_on', '2031-01-06')->firstOrFail();
        $oldAutomaticPair = [];
        foreach ([[$this->stage, $firstOccurrence], [$this->secondStage, $secondOccurrence]] as [$stage, $occurrence]) {
            $oldAutomaticPair[] = SupervisionAssignment::create([
                'supervision_stage_id' => $stage->id,
                'target_lecturer_id' => $otherTarget->id,
                'supervisor_lecturer_id' => $this->supervisor->id,
                'teaching_occurrence_id' => $occurrence->id,
                'starts_at' => $occurrence->starts_at,
                'ends_at' => $occurrence->ends_at,
                'assignment_type' => 'AUTO',
                'status' => 'DRAFT',
            ]);
        }

        $result = app(ManualOverrideService::class)->assignWithResults(
            $this->stage,
            $this->target->id,
            $this->supervisor->id,
            $this->occurrence->id,
            $this->admin
        );

        $this->assertSame('MANUAL', $result['assignment']->fresh()->assignment_type);
        $this->assertSame('CANCELLED', $oldAutomaticPair[0]->fresh()->status);
        $this->assertSame('CANCELLED', $oldAutomaticPair[1]->fresh()->status);
        $this->assertSame(0, $result['reassigned_count']);
        $this->assertSame([['lecturer_id' => $otherTarget->id, 'name' => $otherTarget->name]], $result['unassigned_targets']);
    }

    public function test_manual_rebalancing_prefers_offline_and_uses_online_when_offline_conflicts(): void
    {
        $offlineTarget = $this->createSchedulingTarget('Dosen Dengan Slot Offline Pengganti');
        $offlineStageOne = $this->createModeOccurrence($offlineTarget, 'OFFLINE', '2030-10-14');
        $offlineStageTwo = $this->createModeOccurrence($offlineTarget, 'OFFLINE', '2031-01-13');
        $fallbackTarget = $this->createSchedulingTarget('Dosen Dengan Cadangan Online');
        $fallbackOfflineStageOne = $this->createModeOccurrence($fallbackTarget, 'OFFLINE', '2030-10-07');
        $fallbackOfflineStageTwo = $this->createModeOccurrence($fallbackTarget, 'OFFLINE', '2031-01-06');
        $fallbackOnlineStageOne = $this->createModeOccurrence($fallbackTarget, 'ONLINE', '2030-10-08');
        $fallbackOnlineStageTwo = $this->createModeOccurrence($fallbackTarget, 'ONLINE', '2031-01-07');

        app(ManualOverrideService::class)->assignWithResults(
            $this->stage,
            $this->target->id,
            $this->supervisor->id,
            $this->occurrence->id,
            $this->admin
        );

        $offlineAssignment = $this->stage->assignments()
            ->where('target_lecturer_id', $offlineTarget->id)
            ->where('status', '<>', 'CANCELLED')
            ->firstOrFail();
        $offlineCounterpart = $this->secondStage->assignments()
            ->where('target_lecturer_id', $offlineTarget->id)
            ->where('status', '<>', 'CANCELLED')
            ->firstOrFail();
        $fallbackAssignment = $this->stage->assignments()
            ->where('target_lecturer_id', $fallbackTarget->id)
            ->where('status', '<>', 'CANCELLED')
            ->firstOrFail();
        $fallbackCounterpart = $this->secondStage->assignments()
            ->where('target_lecturer_id', $fallbackTarget->id)
            ->where('status', '<>', 'CANCELLED')
            ->firstOrFail();

        $this->assertSame($offlineStageOne->id, $offlineAssignment->teaching_occurrence_id);
        $this->assertSame($offlineStageTwo->id, $offlineCounterpart->teaching_occurrence_id);
        $this->assertSame($fallbackOnlineStageOne->id, $fallbackAssignment->teaching_occurrence_id);
        $this->assertSame($fallbackOnlineStageTwo->id, $fallbackCounterpart->teaching_occurrence_id);
        $this->assertNotSame($fallbackOfflineStageOne->id, $fallbackAssignment->teaching_occurrence_id);
        $this->assertNotSame($fallbackOfflineStageTwo->id, $fallbackCounterpart->teaching_occurrence_id);
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

    public function test_finalization_rejects_a_pair_with_different_schedule_details(): void
    {
        $otherSchedule = $this->target->teachingSchedules()->firstOrFail()->replicate(['fingerprint']);
        $otherSchedule->class_name = 'Kelas Berbeda';
        $otherSchedule->room_name = 'Ruang Berbeda';
        $otherSchedule->save();
        $otherOccurrence = $this->createOccurrence($otherSchedule, '2031-01-06');

        SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id,
            'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id,
            'teaching_occurrence_id' => $this->occurrence->id,
            'assignment_type' => 'AUTO',
            'status' => 'DRAFT',
        ]);
        SupervisionAssignment::create([
            'supervision_stage_id' => $this->secondStage->id,
            'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id,
            'teaching_occurrence_id' => $otherOccurrence->id,
            'assignment_type' => 'AUTO',
            'status' => 'DRAFT',
        ]);

        $this->expectException(ValidationException::class);

        app(StageFinalizationService::class)->finalize($this->stage, $this->admin);
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

    private function createOccurrence(TeachingSchedule $schedule, string $date): TeachingOccurrence
    {
        return TeachingOccurrence::create([
            'teaching_schedule_id' => $schedule->id,
            'original_date' => $date,
            'occurs_on' => $date,
            'starts_at' => Carbon::parse($date.' 08:00', 'Asia/Jakarta')->utc(),
            'ends_at' => Carbon::parse($date.' 09:40', 'Asia/Jakarta')->utc(),
            'status' => 'SCHEDULED',
        ]);
    }

    private function createModeOccurrence(Lecturer $target, string $mode, string $date): TeachingOccurrence
    {
        $schedule = $target->teachingSchedules()->firstOrFail()->replicate(['fingerprint']);
        $schedule->delivery_mode = $mode;
        $schedule->room_name = $mode === 'ONLINE' ? 'ONLINE' : 'Ruang Offline';
        $schedule->day_of_week = Carbon::parse($date)->dayOfWeekIso;
        $schedule->save();

        return $this->createOccurrence($schedule, $date);
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
