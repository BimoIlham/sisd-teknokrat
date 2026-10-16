<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\FormTemplate;
use App\Models\Lecturer;
use App\Models\PeriodSupervisor;
use App\Models\PeriodTarget;
use App\Models\ScheduleRun;
use App\Models\ScoringPolicy;
use App\Models\StageInstrument;
use App\Models\StudyProgram;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\TeachingSchedule;
use App\Models\User;
use App\Services\Scheduling\CandidateBuilder;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkspacePerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected SupervisionPeriod $period;

    protected SupervisionStage $stage;

    protected SupervisionStage $secondStage;

    protected Lecturer $supervisor;

    protected Collection $lecturers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('superadmin');
        $this->actingAs($this->admin);
        $term = AcademicTerm::create([
            'academic_year' => '2026/2027', 'semester' => 'GANJIL',
            'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31',
        ]);
        $this->period = SupervisionPeriod::create([
            'academic_term_id' => $term->id, 'name' => 'Periode Pengujian Kinerja',
            'starts_on' => $term->starts_on, 'ends_on' => $term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id,
        ]);
        $this->stage = $this->period->stages()->create([
            'stage_no' => 1, 'name' => 'Tahap 1', 'starts_on' => '2026-10-16', 'ends_on' => '2026-10-31', 'status' => 'DRAFT',
        ]);
        $this->secondStage = $this->period->stages()->create([
            'stage_no' => 2, 'name' => 'Tahap 2', 'starts_on' => '2027-01-16', 'ends_on' => '2027-01-31', 'status' => 'DRAFT',
        ]);
        $faculty = Faculty::firstOrCreate(['code' => 'FTIK'], ['name' => 'Fakultas Teknik dan Ilmu Komputer', 'is_active' => true]);
        $program = StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id, 'name' => 'Informatika'],
            ['code' => 'IF', 'is_active' => true]
        );
        $this->lecturers = collect(range(1, 13))->map(function (int $index) use ($faculty, $program): Lecturer {
            $lecturer = Lecturer::create([
                'name' => sprintf('Dosen Pengujian %03d', $index), 'external_code' => sprintf('100000%04d', $index),
                'faculty_id' => $faculty->id, 'study_program_id' => $program->id, 'is_active' => true,
            ]);
            PeriodTarget::create([
                'supervision_period_id' => $this->period->id, 'lecturer_id' => $lecturer->id,
                'scope_status' => 'INCLUDED', 'selected_by' => $this->admin->id,
            ]);

            return $lecturer;
        });
        $this->supervisor = Lecturer::create(['name' => 'Supervisor Pengujian', 'external_code' => '2000000001', 'is_active' => true]);
        PeriodSupervisor::create([
            'supervision_period_id' => $this->period->id, 'lecturer_id' => $this->supervisor->id,
            'is_active' => true, 'selected_by' => $this->admin->id,
        ]);
        $template = FormTemplate::create(['code' => 'PERFORMANCE_OFFLINE', 'version' => 1, 'name' => 'Instrumen Uji', 'delivery_mode' => 'OFFLINE', 'status' => 'PUBLISHED']);
        $policy = ScoringPolicy::create(['code' => 'PERFORMANCE_MEAN', 'version' => 1, 'strategy' => 'MEAN_0_4', 'config' => [], 'status' => 'APPROVED']);
        foreach ([$this->stage, $this->secondStage] as $stage) {
            StageInstrument::create([
                'supervision_stage_id' => $stage->id, 'delivery_mode' => 'OFFLINE', 'form_template_id' => $template->id,
                'scoring_policy_id' => $policy->id, 'bound_by' => $this->admin->id,
            ]);
        }
    }

    public function test_workspace_paginates_rows_and_keeps_global_pair_statistics(): void
    {
        $lecturer = $this->lecturers->get(11);
        $this->createAssignment($lecturer, $this->stage, '2026-10-20');
        $this->createAssignment($lecturer, $this->secondStage, '2027-01-20');
        $response = $this->get(route('lpm.assignments.index'));

        $response->assertOk()->assertViewHas('targets', fn ($targets) => $targets->count() === 10 && $targets->total() === 13)
            ->assertViewHas('stats', fn ($stats) => $stats['total_targets'] === 13 && $stats['assigned_count'] === 1 && $stats['pair_mismatch_count'] === 0)
            ->assertViewHas('supervisorWorkloads', fn ($workloads) => $workloads->first()['assigned_count'] === 1)
            ->assertDontSee($lecturer->name)->assertViewMissing('occurrencesByTarget')->assertViewMissing('enrichedProposals');
        $this->get(route('lpm.assignments.index', ['page' => 2]))->assertOk()->assertSee($lecturer->name)
            ->assertViewHas('targets', fn ($targets) => $targets->count() === 3 && $targets->firstItem() === 11);
    }

    public function test_workspace_filters_search_program_and_status_before_paginating(): void
    {
        $lecturer = $this->lecturers->get(11);
        $this->createAssignment($lecturer, $this->stage, '2026-10-20');
        foreach ([$lecturer->name, $lecturer->external_code, $this->supervisor->name, 'Mata Kuliah Pengujian'] as $search) {
            $this->get(route('lpm.assignments.index', ['search' => $search, 'prodi' => 'Informatika', 'status' => 'manual']))
                ->assertOk()->assertSee($lecturer->name)
                ->assertViewHas('targets', fn ($targets) => $targets->total() === 1)
                ->assertViewHas('stats', fn ($stats) => $stats['total_targets'] === 13 && $stats['pair_mismatch_count'] === 1);
        }
        $this->get(route('lpm.assignments.index', ['status' => 'unassigned']))->assertOk()
            ->assertViewHas('targets', fn ($targets) => $targets->total() === 12)->assertDontSee($lecturer->name);
        $this->get(route('lpm.assignments.index', ['status' => 'auto']))->assertOk()
            ->assertViewHas('targets', fn ($targets) => $targets->total() === 0);
    }

    public function test_manual_options_only_return_active_schedule_slots_for_target_term_and_stage(): void
    {
        $lecturer = $this->lecturers->first();
        $assignment = $this->createAssignment($lecturer, $this->stage, '2026-10-16');
        $lastDay = $this->createOccurrence($lecturer, '2026-10-31');
        $this->createOccurrence($lecturer, '2026-10-15');
        $this->createOccurrence($lecturer, '2026-11-01');
        $this->createOccurrence($lecturer, '2027-01-20');
        $this->createOccurrence($lecturer, '2026-10-20', false);
        $this->createOccurrence($this->lecturers->get(1), '2026-10-20');
        $otherTerm = AcademicTerm::create(['academic_year' => '2025/2026', 'semester' => 'GANJIL', 'starts_on' => '2025-09-01', 'ends_on' => '2026-01-31']);
        $otherOccurrence = $this->createOccurrence($lecturer, '2026-10-20');
        $otherOccurrence->teachingSchedule->update(['academic_term_id' => $otherTerm->id]);

        $this->getJson(route('lpm.assignments.manual_options', [$this->stage, $lecturer]))->assertOk()
            ->assertJsonPath('target.id', $lecturer->id)->assertJsonPath('assignment.id', $assignment->id)
            ->assertJsonCount(2, 'occurrences')->assertJsonPath('occurrences.0.id', $assignment->teaching_occurrence_id)
            ->assertJsonPath('occurrences.0.start_time', '08:00')->assertJsonPath('occurrences.1.id', $lastDay->id);
    }

    public function test_manual_options_reject_excluded_targets_inactive_periods_and_finalized_stages(): void
    {
        $lecturer = $this->lecturers->first();
        $this->getJson(route('lpm.assignments.manual_options', [$this->stage, $this->supervisor]))->assertNotFound();
        PeriodTarget::where('lecturer_id', $lecturer->id)->update(['scope_status' => 'EXCLUDED']);
        $this->getJson(route('lpm.assignments.manual_options', [$this->stage, $lecturer]))->assertNotFound();
        PeriodTarget::where('lecturer_id', $lecturer->id)->update(['scope_status' => 'INCLUDED']);
        $this->stage->update(['status' => 'FINALIZED']);
        $this->getJson(route('lpm.assignments.manual_options', [$this->stage, $lecturer]))->assertForbidden();
        $this->stage->update(['status' => 'DRAFT']);
        $this->period->update(['status' => 'CLOSED']);
        $this->getJson(route('lpm.assignments.manual_options', [$this->stage, $lecturer]))->assertNotFound();
        $this->get(route('lpm.assignments.index'))->assertOk()->assertSee('Belum ada periode supervisi aktif');
    }

    public function test_proposals_load_separately_with_search_and_pagination(): void
    {
        $proposals = $this->lecturers->map(fn (Lecturer $lecturer): array => [
            'target_lecturer_id' => $lecturer->id, 'supervisor_lecturer_id' => $this->supervisor->id,
            'occurrence_id' => $this->createOccurrence($lecturer, '2026-10-20')->id, 'assignment_type' => 'AUTO',
        ])->all();
        $run = ScheduleRun::create(['supervision_stage_id' => $this->stage->id, 'status' => 'FEASIBLE', 'result_payload' => ['assignments' => $proposals, 'coverage_rate' => 100]]);
        $this->get(route('lpm.assignments.index'))->assertOk()->assertViewHas('proposalCount', 13)
            ->assertViewHas('latestRun', fn ($run) => ! array_key_exists('result_payload', $run->getAttributes()))
            ->assertViewMissing('enrichedProposals')->assertDontSee($this->lecturers->last()->name);
        $this->get(route('lpm.assignments.proposal_preview', $run))->assertOk()->assertViewIs('lpm.assignments.proposals')
            ->assertViewHas('enrichedProposals', fn ($proposals) => $proposals->count() === 10 && $proposals->total() === 13);
        $this->get(route('lpm.assignments.proposal_preview', ['run' => $run, 'page' => 2]))->assertOk()
            ->assertSee($this->lecturers->last()->name)->assertViewHas('enrichedProposals', fn ($proposals) => $proposals->count() === 3);
        $this->get(route('lpm.assignments.proposal_preview', ['run' => $run, 'search' => $this->lecturers->last()->name]))->assertOk()
            ->assertViewHas('enrichedProposals', fn ($proposals) => $proposals->total() === 1);
        $this->period->update(['status' => 'CLOSED']);
        $this->get(route('lpm.assignments.proposal_preview', $run))->assertNotFound();
    }

    public function test_new_workspace_endpoints_remain_restricted_to_superadmin(): void
    {
        $run = ScheduleRun::create(['supervision_stage_id' => $this->stage->id, 'status' => 'FEASIBLE']);
        $supervisorUser = User::factory()->create(['must_change_password' => false]);
        $supervisorUser->assignRole('supervisor');
        $this->actingAs($supervisorUser);
        $this->getJson(route('lpm.assignments.manual_options', [$this->stage, $this->lecturers->first()]))->assertForbidden();
        $this->get(route('lpm.assignments.proposal_preview', $run))->assertForbidden();
        $this->getJson(route('lpm.supervisors.candidates', $this->period))->assertForbidden();
    }

    public function test_creating_a_proposal_opens_its_preview_after_redirecting(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->createOccurrence($this->lecturers->first(), '2026-10-20');

        $response = $this->post(route('lpm.assignments.run_solver', $this->stage))
            ->assertRedirect(route('lpm.assignments.index', ['stage_id' => $this->stage->id]))
            ->assertSessionHas('show_proposal_preview', true);
        $this->get($response->headers->get('Location'))->assertOk()
            ->assertSee('x-init="toggleProposalPreview();', false)
            ->assertViewHas('proposalCount', 1);
    }

    public function test_empty_proposals_do_not_open_an_empty_preview(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $response = $this->post(route('lpm.assignments.run_solver', $this->stage))
            ->assertSessionHas('show_proposal_preview', false);
        $this->get($response->headers->get('Location'))->assertOk()
            ->assertDontSee('x-init="toggleProposalPreview();', false);
    }

    public function test_candidate_dates_times_and_queries_do_not_depend_on_lazy_schedule_loading(): void
    {
        foreach ($this->lecturers as $lecturer) {
            $this->createOccurrence($lecturer, '2026-10-20');
            $this->createOccurrence($lecturer, '2026-10-27');
        }
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $pool = app(CandidateBuilder::class)->build($this->stage);

        $this->assertLessThanOrEqual(25, $queries);
        $this->assertCount(26, $pool['candidates']);
        $this->assertSame('2026-10-20', $pool['candidates'][0]['date']);
        $this->assertSame(2, $pool['candidates'][0]['day_of_week']);
        $this->assertSame('08:00', $pool['candidates'][0]['start_time']);
        $this->assertSame('09:40', $pool['candidates'][0]['end_time']);
        $this->assertSame($this->supervisor->id, $pool['candidates'][0]['supervisor_lecturer_id']);
    }

    public function test_internal_solver_keeps_manual_assignments_and_reports_a_valid_duration(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $manual = $this->createAssignment($this->lecturers->first(), $this->stage, '2026-10-20');
        $this->createOccurrence($this->lecturers->get(1), '2026-10-20');

        $this->post(route('lpm.assignments.run_solver', $this->stage))->assertSessionHas('success');
        $run = ScheduleRun::where('supervision_stage_id', $this->stage->id)->latest('id')->firstOrFail();
        $proposal = collect($run->proposed_assignments)->firstWhere('target_lecturer_id', $manual->target_lecturer_id);
        $this->assertSame($manual->supervisor_lecturer_id, $proposal['supervisor_lecturer_id']);
        $this->assertSame($manual->teaching_occurrence_id, $proposal['occurrence_id']);
        $this->assertSame('MANUAL', $proposal['assignment_type']);
        $this->assertGreaterThanOrEqual(0, $run->execution_duration_ms);
        $this->assertLessThan(5000, $run->execution_duration_ms);
        $this->assertSame('DRAFT', $manual->fresh()->status);
    }

    public function test_directory_profile_aggregation_keeps_null_and_whitespace_profiles_incomplete(): void
    {
        $this->lecturers->get(0)->update(['external_code' => null]);
        $this->lecturers->get(1)->update(['name' => " \t\n\r\0\x0B "]);
        $this->lecturers->get(2)->update(['study_program_id' => null]);
        $this->get(route('lpm.lecturers.index', ['completion' => 'incomplete']))->assertOk()
            ->assertViewHas('completenessCounts', ['complete' => 10, 'incomplete' => 3])
            ->assertViewHas('lecturers', fn ($lecturers) => $lecturers->total() === 3 && $lecturers->every(fn ($lecturer) => ! $lecturer->profile_complete));
        $this->get(route('lpm.lecturers.index', ['completion' => 'complete']))->assertOk()
            ->assertViewHas('lecturers', fn ($lecturers) => $lecturers->total() === 10 && $lecturers->every(fn ($lecturer) => $lecturer->profile_complete));
    }

    public function test_dashboard_aggregates_submissions_without_counting_drafts_or_cancelled_assignments_as_scores(): void
    {
        $first = $this->createAssignment($this->lecturers->first(), $this->stage, '2026-10-20');
        $second = $this->createAssignment($this->lecturers->get(1), $this->stage, '2026-10-20');
        $draft = $this->createAssignment($this->lecturers->get(2), $this->stage, '2026-10-20');
        $cancelled = $this->createAssignment($this->lecturers->get(3), $this->stage, '2026-10-20');
        $cancelled->update(['status' => 'CANCELLED']);
        foreach ([[$first, 'SUBMITTED', 3.5], [$second, 'SUBMITTED', 2.5], [$draft, 'DRAFT', 4], [$cancelled, 'SUBMITTED', 4]] as [$assignment, $status, $score]) {
            Supervision::create(['supervision_assignment_id' => $assignment->id, 'status' => $status, 'final_score' => $score]);
        }
        $this->get(route('lpm.dashboard'))->assertOk()->assertViewHas('completedCount', 2)->assertViewHas('inProgressCount', 1)
            ->assertViewHas('totalAssignments', 3)->assertViewHas('avgScore', 3.0)
            ->assertViewHas('tierCounts', fn ($tiers) => $tiers['sangat_lengkap'] === 1 && $tiers['cukup'] === 1 && array_sum($tiers) === 2)
            ->assertViewHas('progressData', fn ($progress) => $progress['total_required'] === 26 && $progress['submitted_count'] === 2)
            ->assertViewHas('faculties', fn ($faculties) => $faculties->firstWhere('code', 'FTIK')['total'] === 13 && $faculties->firstWhere('code', 'FTIK')['completed'] === 2);
    }

    private function createAssignment(Lecturer $lecturer, SupervisionStage $stage, string $date): SupervisionAssignment
    {
        return SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id, 'target_lecturer_id' => $lecturer->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->createOccurrence($lecturer, $date)->id,
            'assignment_type' => 'MANUAL', 'status' => 'DRAFT',
        ]);
    }

    private function createOccurrence(Lecturer $lecturer, string $date, bool $active = true): TeachingOccurrence
    {
        $course = Course::firstOrCreate(['code' => 'PERFORMANCE-COURSE'], ['name' => 'Mata Kuliah Pengujian']);
        $schedule = TeachingSchedule::create([
            'academic_term_id' => $this->period->academic_term_id, 'lecturer_id' => $lecturer->id, 'course_id' => $course->id,
            'class_name' => 'IF 26 A', 'room_name' => 'Lab 1', 'delivery_mode' => 'OFFLINE',
            'day_of_week' => 2, 'starts_local' => '08:00', 'ends_local' => '09:40', 'is_active' => $active,
        ]);

        return TeachingOccurrence::create([
            'teaching_schedule_id' => $schedule->id, 'original_date' => $date, 'occurs_on' => $date,
            'starts_at' => Carbon::parse($date.' 08:00', 'Asia/Jakarta')->utc(),
            'ends_at' => Carbon::parse($date.' 09:40', 'Asia/Jakarta')->utc(), 'status' => 'SCHEDULED',
        ]);
    }
}
