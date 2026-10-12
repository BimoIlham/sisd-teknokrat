<?php

namespace Tests\Feature;

use App\Models\Lecturer;
use App\Models\PeriodSupervisor;
use App\Models\StageInstrument;
use App\Models\SupervisionAssignment;
use App\Models\TeachingOccurrence;
use App\Services\Scheduling\ApplyRunService;
use App\Services\Scheduling\CandidateBuilder;
use App\Services\Scheduling\PeriodAssignmentPairService;
use App\Services\Scheduling\SchedulingInputSnapshot;
use App\Services\Scheduling\SchedulingLockService;
use App\Services\Scheduling\SolverClientService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class OfflineSupervisionPriorityTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
        Http::fake(['*' => Http::response([], 503)]);
        foreach ([$this->stage, $this->secondStage] as $stage) {
            StageInstrument::create(['supervision_stage_id' => $stage->id, 'delivery_mode' => 'ONLINE',
                'form_template_id' => $this->instrument->form_template_id, 'scoring_policy_id' => $this->instrument->scoring_policy_id, 'bound_by' => $this->admin->id]);
        }
    }

    public function test_offline_is_preferred_over_an_earlier_online_slot_and_is_used_for_both_stages(): void
    {
        $this->occurrence->teachingSchedule->update(['delivery_mode' => 'ONLINE']);
        $offline = $this->option($this->target, 'OFFLINE', '2030-10-08');
        $secondOffline = $this->option($this->target, 'OFFLINE', '2031-01-07');
        $pool = app(CandidateBuilder::class)->build($this->stage);
        $this->assertContains('OFFLINE', array_column($pool['candidates'], 'delivery_mode'));
        $run = app(SolverClientService::class)->solve($this->stage, $this->admin);
        $this->assertSame($offline->id, $run->proposed_assignments[0]['occurrence_id']);
        $this->assertSame('offline_first_v1', $run->input_payload['sources']['selection_policy']);
        app(ApplyRunService::class)->apply($run, $run->input_revision, $this->admin);
        $this->assertSame($secondOffline->id, $this->secondStage->assignments()->sole()->teaching_occurrence_id);
    }

    public function test_online_is_selected_only_after_all_offline_slots_conflict_with_teaching(): void
    {
        $this->option($this->supervisor, 'OFFLINE', '2030-10-07');
        $this->option($this->target, 'OFFLINE', '2030-10-08');
        $this->option($this->supervisor, 'OFFLINE', '2030-10-08');
        $online = $this->option($this->target, 'ONLINE', '2030-10-09');
        $run = app(SolverClientService::class)->solve($this->stage, $this->admin);
        $this->assertSame('OPTIMAL', $run->status);
        $this->assertSame($online->id, $run->proposed_assignments[0]['occurrence_id']);
    }

    public function test_a_lecturer_with_only_online_classes_remains_eligible(): void
    {
        $this->occurrence->teachingSchedule->update(['delivery_mode' => 'ONLINE']);
        $run = app(SolverClientService::class)->solve($this->stage, $this->admin);
        $this->assertSame('OPTIMAL', $run->status);
        $this->assertSame($this->occurrence->id, $run->proposed_assignments[0]['occurrence_id']);
    }

    public function test_online_fallback_can_avoid_another_targets_offline_observation(): void
    {
        $secondTarget = $this->createSchedulingTarget('Target Dengan Satu Pilihan Offline');
        $online = $this->option($this->target, 'ONLINE', '2030-10-08');
        $run = app(SolverClientService::class)->solve($this->stage, $this->admin);
        $proposals = collect($run->proposed_assignments)->keyBy('target_lecturer_id');
        $this->assertSame(100.0, $run->target_coverage_rate);
        $this->assertSame($online->id, $proposals[$this->target->id]['occurrence_id']);
        $secondOccurrence = TeachingOccurrence::with('teachingSchedule')->findOrFail($proposals[$secondTarget->id]['occurrence_id']);
        $this->assertSame('OFFLINE', $secondOccurrence->teachingSchedule->delivery_mode);
    }

    #[TestWith(['AUTO', 'DRAFT', 'OFFLINE'])]
    #[TestWith(['MANUAL', 'DRAFT', 'ONLINE'])]
    #[TestWith(['AUTO', 'FINAL', 'ONLINE'])]
    #[TestWith(['AUTO', 'DRAFT', 'ONLINE', true])]
    public function test_second_stage_prefers_offline_while_preserving_locked_choices(string $type, string $status, string $expectedMode, bool $isLocked = false): void
    {
        $this->occurrence->teachingSchedule->update(['delivery_mode' => 'ONLINE']);
        $sourceOffline = $this->option($this->target, 'OFFLINE', '2030-10-08');
        $secondOffline = $this->option($this->target, 'OFFLINE', '2031-01-07');
        $source = SupervisionAssignment::create(['supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $sourceOffline->id, 'assignment_type' => 'AUTO', 'status' => 'DRAFT']);
        $secondOnline = TeachingOccurrence::where('teaching_schedule_id', $this->occurrence->teaching_schedule_id)->whereDate('occurs_on', '2031-01-06')->sole();
        $existing = SupervisionAssignment::create(['supervision_stage_id' => $this->secondStage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $secondOnline->id, 'assignment_type' => $type, 'status' => $status, 'is_locked' => $isLocked]);
        DB::transaction(function () use ($source): void {
            $locks = app(SchedulingLockService::class);
            $locks->state();
            $locks->period($this->period->id);
            app(PeriodAssignmentPairService::class)->synchronizeCounterpart($source, $this->admin, 'Uji prioritas offline');
        });
        $this->assertSame($expectedMode === 'OFFLINE' ? $secondOffline->id : $secondOnline->id, $existing->fresh()->teaching_occurrence_id);
        if ($isLocked) {
            $this->assertTrue($existing->fresh()->is_locked);
        }
    }

    public function test_manual_online_assignment_is_preserved_by_automatic_generation(): void
    {
        $online = $this->option($this->target, 'ONLINE', '2030-10-08');
        SupervisionAssignment::create(['supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $online->id, 'assignment_type' => 'MANUAL', 'status' => 'DRAFT']);
        $run = app(SolverClientService::class)->solve($this->stage, $this->admin);
        $this->assertSame($online->id, $run->proposed_assignments[0]['occurrence_id']);
        $this->assertSame('MANUAL', $run->proposed_assignments[0]['assignment_type']);
    }

    public function test_offline_preference_does_not_block_a_target_with_only_one_online_slot(): void
    {
        $this->occurrence->teachingSchedule->update(['delivery_mode' => 'ONLINE']);
        $secondTarget = $this->createSchedulingTarget('Target Dengan Pilihan Offline Dan Online');
        $online = $this->option($secondTarget, 'ONLINE', '2030-10-08');
        $run = app(SolverClientService::class)->solve($this->stage, $this->admin);
        $proposals = collect($run->proposed_assignments)->keyBy('target_lecturer_id');
        $this->assertSame(100.0, $run->target_coverage_rate);
        $this->assertSame($this->occurrence->id, $proposals[$this->target->id]['occurrence_id']);
        $this->assertSame($online->id, $proposals[$secondTarget->id]['occurrence_id']);
    }

    public function test_proposals_from_before_the_priority_change_are_rejected(): void
    {
        $run = app(SolverClientService::class)->solve($this->stage, $this->admin);
        $input = $run->input_payload;
        $input['sources']['version'] = 1;
        unset($input['sources']['selection_policy']);
        $run->update(['input_payload' => $input, 'input_hash' => app(SchedulingInputSnapshot::class)->hash($input)]);
        $this->expectException(ConflictHttpException::class);
        app(ApplyRunService::class)->apply($run, $run->input_revision, $this->admin);
    }

    public function test_offline_priority_is_stronger_than_load_balancing(): void
    {
        $this->occurrence->teachingSchedule->update(['delivery_mode' => 'ONLINE']);
        $offline = $this->option($this->target, 'OFFLINE', '2030-10-08');
        $otherSupervisor = Lecturer::create(['name' => 'Supervisor Dengan Beban Lebih Ringan', 'is_active' => true]);
        PeriodSupervisor::create(['supervision_period_id' => $this->period->id, 'lecturer_id' => $otherSupervisor->id, 'is_active' => true, 'selected_by' => $this->admin->id]);
        $this->option($otherSupervisor, 'OFFLINE', '2030-10-08');
        $lockedTarget = $this->createSchedulingTarget('Target Terkunci Untuk Beban Supervisor');
        $lockedOccurrence = $this->option($lockedTarget, 'OFFLINE', '2030-10-06');
        SupervisionAssignment::create(['supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $lockedTarget->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $lockedOccurrence->id, 'assignment_type' => 'MANUAL', 'status' => 'DRAFT']);
        $run = app(SolverClientService::class)->solve($this->stage, $this->admin);
        $proposal = collect($run->proposed_assignments)->firstWhere('target_lecturer_id', $this->target->id);
        $this->assertSame($offline->id, $proposal['occurrence_id']);
        $this->assertSame($this->supervisor->id, $proposal['supervisor_lecturer_id']);
    }

    private function option(Lecturer $lecturer, string $mode, string $date): TeachingOccurrence
    {
        $schedule = $this->occurrence->teachingSchedule->replicate(['fingerprint']);
        $schedule->lecturer_id = $lecturer->id;
        $schedule->delivery_mode = $mode;
        $schedule->day_of_week = Carbon::parse($date)->dayOfWeekIso;
        $schedule->room_name = $mode === 'ONLINE' ? 'ONLINE' : 'Ruang Offline';
        $schedule->save();

        return TeachingOccurrence::create(['teaching_schedule_id' => $schedule->id, 'original_date' => $date, 'occurs_on' => $date,
            'starts_at' => Carbon::parse($date.' 08:00', 'Asia/Jakarta')->utc(),
            'ends_at' => Carbon::parse($date.' 09:40', 'Asia/Jakarta')->utc(), 'status' => 'SCHEDULED']);
    }
}
