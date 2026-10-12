<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Lecturer;
use App\Models\ScheduleRun;
use App\Models\SchedulingState;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\TeachingSchedule;
use App\Models\User;
use App\Services\Scheduling\ApplyRunService;
use App\Services\Scheduling\CandidateBuilder;
use App\Services\Scheduling\ConflictChecker;
use App\Services\Scheduling\SchedulingInputSnapshot;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ConstraintSchedulerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('sisd_teknokrat_test', app('db')->connection()->getDatabaseName());

        if (TeachingOccurrence::count() === 0) {
            $this->seed(DatabaseSeeder::class);
            $this->seed(UserSeeder::class);
            $this->seed(DemoAcademicSeeder::class);
        }

        SchedulingState::firstOrCreate(['id' => 1], [
            'revision' => 1,
            'updated_at' => now(),
        ]);
    }

    public function test_h01_self_supervision_is_forbidden(): void
    {
        $checker = app(ConflictChecker::class);
        $stage = SupervisionStage::first();

        // Get an occurrence
        $occurrence = TeachingOccurrence::whereHas('teachingSchedule')->with('teachingSchedule')->first();
        $lecturerId = $occurrence->teachingSchedule->lecturer_id;

        // Check assigning lecturer as supervisor of their own class
        $result = $checker->check($occurrence, $lecturerId, $stage);

        $this->assertFalse($result['valid']);
        $this->assertTrue(collect($result['conflicts'])->contains(fn ($c) => str_contains($c, 'H01')));
    }

    public function test_candidates_and_manual_checks_exclude_schedules_from_other_semesters(): void
    {
        $stage = SupervisionStage::firstOrFail();
        $occurrence = TeachingOccurrence::with('teachingSchedule')
            ->whereBetween('occurs_on', [$stage->starts_on, $stage->ends_on])->firstOrFail();
        $foreignTerm = AcademicTerm::create([
            'academic_year' => '2030/2031', 'semester' => 'GENAP',
            'starts_on' => $stage->starts_on, 'ends_on' => $stage->ends_on,
        ]);
        $foreignSchedule = $occurrence->teachingSchedule->replicate();
        $foreignSchedule->academic_term_id = $foreignTerm->id;
        $foreignSchedule->save();
        $foreignOccurrence = $occurrence->replicate();
        $foreignOccurrence->teaching_schedule_id = $foreignSchedule->id;
        $foreignOccurrence->save();
        $foreignOccurrence->refresh();
        $supervisorId = Lecturer::where('id', '<>', $foreignSchedule->lecturer_id)->value('id');

        $candidates = app(CandidateBuilder::class)->build($stage)['candidates'];
        $result = app(ConflictChecker::class)->check($foreignOccurrence, $supervisorId, $stage);

        $this->assertNotEmpty($candidates);
        $this->assertNotContains($foreignOccurrence->id, array_column($candidates, 'occurrence_id'));
        $this->assertFalse($result['valid']);
        $this->assertContains('Jadwal perkuliahan harus berasal dari semester pada periode supervisi ini.', $result['conflicts']);
    }

    public function test_h02_supervisor_teaching_conflict_is_forbidden(): void
    {
        $checker = app(ConflictChecker::class);
        $stage = SupervisionStage::first();

        // Find occurrence A
        $occurrenceA = TeachingOccurrence::whereHas('teachingSchedule')->with('teachingSchedule')->first();
        $lecturerAId = $occurrenceA->teachingSchedule->lecturer_id;
        $lecturerB = Lecturer::where('id', '<>', $lecturerAId)->first();

        // Create occurrence B for lecturer B at the exact same date and time
        $scheduleB = TeachingSchedule::create([
            'academic_term_id' => $occurrenceA->teachingSchedule->academic_term_id,
            'study_program_id' => $occurrenceA->teachingSchedule->study_program_id,
            'faculty_id' => $occurrenceA->teachingSchedule->faculty_id ?? 1,
            'lecturer_id' => $lecturerB->id,
            'course_id' => $occurrenceA->teachingSchedule->course_id,
            'class_name' => 'B',
            'day_of_week' => $occurrenceA->day_of_week,
            'start_time' => $occurrenceA->start_time_local,
            'end_time' => $occurrenceA->end_time_local,
            'room' => 'Lab Conflict',
            'delivery_mode' => 'OFFLINE',
            'is_active' => true,
        ]);

        $occurrenceB = TeachingOccurrence::create([
            'teaching_schedule_id' => $scheduleB->id,
            'original_date' => $occurrenceA->occurrence_date ?? $occurrenceA->occurs_on,
            'occurs_on' => $occurrenceA->occurrence_date ?? $occurrenceA->occurs_on,
            'starts_at' => $occurrenceA->starts_at,
            'ends_at' => $occurrenceA->ends_at,
            'status' => 'SCHEDULED',
        ]);

        // Trying to assign lecturer B to supervise occurrence A when lecturer B is teaching occurrence B!
        $result = $checker->check($occurrenceA, $lecturerB->id, $stage);

        $this->assertFalse($result['valid']);
        $this->assertTrue(collect($result['conflicts'])->contains(fn ($c) => str_contains($c, 'H02')));

        $occurrenceB->update(['status' => 'CANCELLED']);
        $result = (new ConflictChecker)->check($occurrenceA, $lecturerB->id, $stage);
        $this->assertFalse(collect($result['conflicts'])->contains(fn ($conflict) => str_contains($conflict, 'H02')));

        $occurrenceB->update(['status' => 'SCHEDULED']);
        $scheduleB->update(['is_active' => false]);
        $result = (new ConflictChecker)->check($occurrenceA, $lecturerB->id, $stage);
        $this->assertFalse(collect($result['conflicts'])->contains(fn ($conflict) => str_contains($conflict, 'H02')));
    }

    public function test_inactive_or_cancelled_teaching_cannot_be_used_for_supervision(): void
    {
        $stage = SupervisionStage::firstOrFail();
        $occurrence = TeachingOccurrence::with('teachingSchedule')
            ->whereBetween('occurs_on', [$stage->starts_on, $stage->ends_on])->firstOrFail();
        $supervisorId = Lecturer::where('id', '<>', $occurrence->teachingSchedule->lecturer_id)->value('id');

        $occurrence->update(['status' => 'CANCELLED']);
        $result = app(ConflictChecker::class)->check($occurrence, $supervisorId, $stage);
        $this->assertFalse($result['valid']);
        $this->assertContains('Jadwal perkuliahan belum aktif atau pertemuan sudah dibatalkan.', $result['conflicts']);
        $this->assertNotContains($occurrence->id, array_column(app(CandidateBuilder::class)->build($stage)['candidates'], 'occurrence_id'));

        $occurrence->update(['status' => 'SCHEDULED']);
        $occurrence->teachingSchedule->update(['is_active' => false]);
        $result = app(ConflictChecker::class)->check($occurrence, $supervisorId, $stage);
        $this->assertFalse($result['valid']);
        $this->assertContains('Jadwal perkuliahan belum aktif atau pertemuan sudah dibatalkan.', $result['conflicts']);
        $this->assertNotContains($occurrence->id, array_column(app(CandidateBuilder::class)->build($stage)['candidates'], 'occurrence_id'));
    }

    public function test_h05_supervisor_with_at_least_three_classes_on_same_date_is_blocked(): void
    {
        $checker = app(ConflictChecker::class);
        $stage = SupervisionStage::first();

        $targetOccurrence = TeachingOccurrence::whereHas('teachingSchedule')->with('teachingSchedule')->first();
        $targetLecturerId = $targetOccurrence->teachingSchedule->lecturer_id;
        $supervisor = Lecturer::where('id', '<>', $targetLecturerId)->first();
        $date = $targetOccurrence->occurrence_date ?? $targetOccurrence->occurs_on;

        $dateStr = is_string($date) ? $date : $date->toDateString();

        for ($i = 0; $i < 3; $i++) {
            $schedule = TeachingSchedule::create([
                'academic_term_id' => $targetOccurrence->teachingSchedule->academic_term_id,
                'study_program_id' => $targetOccurrence->teachingSchedule->study_program_id,
                'faculty_id' => $targetOccurrence->teachingSchedule->faculty_id ?? 1,
                'lecturer_id' => $supervisor->id,
                'course_id' => $targetOccurrence->teachingSchedule->course_id,
                'class_name' => "H05-Class-{$i}",
                'day_of_week' => 1,
                'start_time' => sprintf('%02d:00', 8 + ($i * 2)),
                'end_time' => sprintf('%02d:40', 8 + ($i * 2)),
                'room' => "Lab {$i}",
                'delivery_mode' => 'OFFLINE',
                'is_active' => true,
            ]);

            TeachingOccurrence::create([
                'teaching_schedule_id' => $schedule->id,
                'original_date' => $dateStr,
                'occurs_on' => $dateStr,
                'starts_at' => Carbon::parse($dateStr)->setTime(8 + ($i * 2), 0),
                'ends_at' => Carbon::parse($dateStr)->setTime(8 + ($i * 2), 40),
                'status' => 'SCHEDULED',
            ]);
        }

        $result = $checker->check($targetOccurrence, $supervisor->id, $stage);

        $this->assertFalse($result['valid']);
        $this->assertTrue(collect($result['conflicts'])->contains(fn ($c) => str_contains($c, 'H05')));
    }

    public function test_invariant_16_manual_assignments_are_never_overwritten_by_apply_run(): void
    {
        $stage = SupervisionStage::first();
        $user = User::where('email', 'lpm@teknokrat.ac.id')->first();
        $applyService = app(ApplyRunService::class);

        $targetOccurrence = TeachingOccurrence::whereHas('teachingSchedule')->with('teachingSchedule')->first();
        $targetLecturerId = $targetOccurrence->teachingSchedule->lecturer_id;
        $otherLecturers = Lecturer::where('id', '<>', $targetLecturerId)->take(2)->get();
        $supervisor1Id = $otherLecturers[0]->id;
        $supervisor2Id = $otherLecturers[1]->id;

        // Manually lock target to supervisor 1
        $manualAssignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id,
            'target_lecturer_id' => $targetLecturerId,
            'supervisor_lecturer_id' => $supervisor1Id,
            'teaching_occurrence_id' => $targetOccurrence->id,
            'assignment_type' => 'MANUAL', // Locked!
            'status' => 'FINAL',
        ]);

        // Create a solver run proposing target to supervisor 2
        $run = ScheduleRun::create([
            'supervision_stage_id' => $stage->id,
            'created_by_user_id' => $user->id,
            'run_type' => 'AUTOMATIC',
            'status' => 'OPTIMAL',
            'started_at' => now(),
            'completed_at' => now(),
            'target_coverage_rate' => 100.0,
            'proposed_assignments' => [
                [
                    'target_lecturer_id' => $targetLecturerId,
                    'supervisor_lecturer_id' => $supervisor2Id, // Solver wants supervisor 2
                    'occurrence_id' => $targetOccurrence->id,
                    'assignment_type' => 'AUTO',
                ],
            ],
        ]);

        $state = SchedulingState::find(1);
        $snapshots = app(SchedulingInputSnapshot::class);
        $input = ['sources' => $snapshots->capture($stage), 'solver' => []];
        $run->update(['input_revision' => $state->revision, 'input_payload' => $input, 'input_hash' => $snapshots->hash($input)]);
        $applyService->apply($run, $state->revision, $user);

        // Check that target is STILL assigned to supervisor 1 (MANUAL preserved)
        $fresh = $manualAssignment->fresh();
        $this->assertEquals($supervisor1Id, $fresh->supervisor_lecturer_id);
        $this->assertEquals('MANUAL', $fresh->assignment_type);
    }

    public function test_invariant_20_apply_with_stale_revision_triggers_409_conflict(): void
    {
        $this->expectException(ConflictHttpException::class);

        $stage = SupervisionStage::first();
        $user = User::where('email', 'lpm@teknokrat.ac.id')->first();
        $applyService = app(ApplyRunService::class);

        $run = ScheduleRun::create([
            'supervision_stage_id' => $stage->id,
            'created_by_user_id' => $user->id,
            'run_type' => 'AUTOMATIC',
            'status' => 'OPTIMAL',
            'started_at' => now(),
            'completed_at' => now(),
            'target_coverage_rate' => 100.0,
            'proposed_assignments' => [],
        ]);

        $state = SchedulingState::find(1);

        $run->update(['input_revision' => $state->revision - 1]);
        $applyService->apply($run, $state->revision, $user);
    }
}
