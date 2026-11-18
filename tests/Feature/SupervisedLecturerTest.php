<?php

namespace Tests\Feature;

use App\Models\Lecturer;
use App\Models\PeriodTarget;
use App\Models\StageInstrument;
use App\Models\StudyProgram;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Models\TeachingOccurrence;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SupervisedLecturerTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_shows_each_target_once_with_both_stages_even_after_input_closes(): void
    {
        $scenario = $this->createScenario();

        $response = $this->actingAs($scenario['admin'])->get(route('lpm.supervisions.monitoring', ['period_id' => $scenario['period']->id]));

        $response->assertSee('Monitoring Supervisi')->assertSee('Belum Disupervisi')->assertSee('Draf')->assertSee('Sudah Disupervisi');
        $response->assertDontSee('value="unassigned"', false)->assertDontSee('value="unfilled"', false)->assertDontSee('value="pending"', false);
        $this->assertSame(7, $response->viewData('supervisedLecturers')->total());
        $response->assertSee('Supervisor Alpha')->assertSee('Supervisor Beta');
        $mixed = $response->viewData('supervisedLecturers')->firstWhere('name', 'Target mixed');
        $this->assertCount(2, $mixed->assignmentsAsTarget);
        $response->assertDontSee('Target excluded')->assertDontSee('Target other period');
        $this->assertSame(2, substr_count($response->getContent(), 'Unduh PDF supervisi Target completed Tahap'));
        $response->assertSee(route('lpm.supervisions.pdf', $scenario['completedSupervision']), false);
    }

    #[TestWith(['not_supervised', '1', ['unassigned', 'cancelled', 'unfilled', 'empty']])]
    #[TestWith(['not_supervised', 'all', ['unassigned', 'cancelled', 'unfilled', 'empty', 'draft']])]
    #[TestWith(['draft', 'all', ['draft', 'mixed']])]
    #[TestWith(['submitted', 'all', ['completed', 'mixed']])]
    #[TestWith(['submitted', '2', ['completed']])]
    #[TestWith(['not_supervised', '2', ['unassigned', 'cancelled', 'unfilled', 'empty', 'draft']])]
    public function test_filters_progress_on_the_selected_stage(string $status, string $stage, array $expectedTargets): void
    {
        $scenario = $this->createScenario();

        $response = $this->actingAs($scenario['admin'])->get(route('lpm.supervisions.monitoring', [
            'period_id' => $scenario['period']->id, 'status' => $status, 'stage' => $stage,
        ]));

        $response->assertOk();
        $expectedNames = array_map(fn (string $name): string => 'Target '.$name, $expectedTargets);
        sort($expectedNames);
        $this->assertSame($expectedNames, $response->viewData('supervisedLecturers')->pluck('name')->sort()->values()->all());
    }

    public function test_supervisor_and_status_must_match_the_same_stage(): void
    {
        $scenario = $this->createScenario();

        $response = $this->actingAs($scenario['admin'])->get(route('lpm.supervisions.monitoring', [
            'period_id' => $scenario['period']->id, 'supervisor_id' => $scenario['supervisorA']->id, 'status' => 'draft',
        ]));

        $response->assertOk();
        $this->assertSame(['Target draft'], $response->viewData('supervisedLecturers')->pluck('name')->all());
        $this->get(route('lpm.supervisions.monitoring', [
            'period_id' => $scenario['period']->id, 'supervisor_id' => $scenario['supervisorB']->id, 'status' => 'draft',
        ]))->assertViewHas('supervisedLecturers', fn ($lecturers): bool => $lecturers->pluck('name')->all() === ['Target mixed']);
        $this->get(route('lpm.supervisions.monitoring', [
            'period_id' => $scenario['period']->id, 'supervisor_id' => $scenario['supervisorA']->id, 'status' => 'not_supervised',
        ]))->assertViewHas('supervisedLecturers', fn ($lecturers): bool => $lecturers->pluck('name')->all() === ['Target empty', 'Target unfilled']);
    }

    public function test_pending_card_opens_monitoring_for_its_period_and_counts_tasks_instead_of_unique_lecturers(): void
    {
        $scenario = $this->createScenario();

        $response = $this->actingAs($scenario['admin'])->get(route('lpm.supervisors.index', ['period_id' => $scenario['period']->id]));

        $response->assertSee(route('lpm.supervisions.monitoring', ['period_id' => $scenario['period']->id, 'status' => 'pending']));
        $response->assertViewHas('totalAssignments', 7)->assertViewHas('pendingAssignmentsCount', 4)->assertViewHas('totalCompletedAssignments', 3);
        $this->get(route('lpm.supervisions.monitoring', ['period_id' => $scenario['period']->id, 'status' => 'pending']))
            ->assertSee('Menampilkan tugas yang belum dikirim, termasuk Draf.')
            ->assertDontSee('value="pending"', false)
            ->assertViewHas('supervisedLecturers', fn ($lecturers): bool => $lecturers->pluck('name')->all() === ['Target draft', 'Target empty', 'Target mixed', 'Target unfilled']);
    }

    public function test_search_organization_filters_and_pagination_keep_the_selected_filters(): void
    {
        $scenario = $this->createScenario();
        $program = $scenario['supervisorA']->studyProgram;
        foreach (range(1, 16) as $number) {
            $this->createTarget($scenario['period'], $scenario['admin'], 'Pagination '.$number, $program->id);
        }
        $otherProgram = StudyProgram::where('faculty_id', $program->faculty_id)->whereKeyNot($program->id)->firstOrFail();
        $otherFacultyProgram = StudyProgram::where('faculty_id', '<>', $program->faculty_id)->firstOrFail();
        $this->createTarget($scenario['period'], $scenario['admin'], 'Pagination other program', $otherProgram->id);
        $this->createTarget($scenario['period'], $scenario['admin'], 'Pagination other faculty', $otherFacultyProgram->id);
        $parameters = [
            'period_id' => $scenario['period']->id, 'search' => 'Pagination', 'status' => 'not_supervised',
            'faculty_id' => $program->faculty_id, 'study_program_id' => $program->id,
        ];

        $response = $this->actingAs($scenario['admin'])->get(route('lpm.supervisions.monitoring', $parameters));

        $response->assertOk();
        $this->assertSame(16, $response->viewData('supervisedLecturers')->total());
        $response->assertSee('search=Pagination')->assertSee('status=not_supervised')->assertSee('page=2');
        $this->get(route('lpm.supervisions.monitoring', $parameters + ['page' => 2]))
            ->assertViewHas('supervisedLecturers', fn ($lecturers): bool => $lecturers->count() === 1);
    }

    public function test_monitoring_escapes_lecturer_names_and_keeps_the_old_address_available(): void
    {
        $scenario = $this->createScenario();
        $name = '<script>alert("lecturer")</script>';
        $this->createTarget($scenario['period'], $scenario['admin'], $name);

        $response = $this->actingAs($scenario['admin'])->get(route('lpm.supervisions.monitoring', ['period_id' => $scenario['period']->id, 'search' => $name]));

        $response->assertSee($name)->assertDontSee($name, false);
        $this->assertSame(1, $response->viewData('supervisedLecturers')->total());
        $this->get(route('lpm.supervisions.completed', ['period_id' => $scenario['period']->id]))
            ->assertViewHas('supervisedLecturers', fn ($lecturers): bool => $lecturers->pluck('name')->all() === ['Target completed', 'Target mixed']);
    }

    public function test_monitoring_handles_missing_periods_and_unconfigured_stages(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('lpm_admin');

        $this->actingAs($admin)->get(route('lpm.supervisions.monitoring'))
            ->assertSee('Tidak ada dosen yang sesuai');
        $this->get(route('lpm.supervisions.monitoring', ['period_id' => 999999]))->assertNotFound();

        $scenario = $this->createScenario();
        $scenario['period']->stages()->where('stage_no', 2)->firstOrFail()->update(['stage_no' => 3]);
        $this->withSession(['password_hash_web' => null])->actingAs($scenario['admin'])
            ->get(route('lpm.supervisions.monitoring', ['period_id' => $scenario['period']->id, 'stage' => '2', 'status' => 'not_supervised']))
            ->assertViewHas('supervisedLecturers', fn ($lecturers): bool => $lecturers->isEmpty());
        $this->get(route('lpm.supervisions.monitoring', ['period_id' => $scenario['period']->id]))->assertSee('Tahap belum diatur');
    }

    public function test_monitoring_requires_an_admin_account(): void
    {
        $this->get(route('lpm.supervisions.monitoring'))->assertRedirect(route('login'));
        $this->seed(RoleAndPermissionSeeder::class);
        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');

        $this->actingAs($supervisor)->get(route('lpm.supervisions.monitoring'))->assertForbidden();
    }

    /**
     * @return array{admin: User, period: SupervisionPeriod, supervisorA: Lecturer, supervisorB: Lecturer, completedSupervision: Supervision}
     */
    private function createScenario(): array
    {
        $this->seed([DatabaseSeeder::class, UserSeeder::class, DemoAcademicSeeder::class]);
        $admin = User::where('email', 'lpm@teknokrat.ac.id')->firstOrFail();
        $sourcePeriod = SupervisionPeriod::with('stages.instruments')->firstOrFail();
        $period = SupervisionPeriod::create([
            'academic_term_id' => $sourcePeriod->academic_term_id, 'name' => 'Monitoring Test',
            'starts_on' => '2025-09-01', 'ends_on' => '2026-01-31', 'status' => 'CLOSED', 'created_by' => $admin->id,
        ]);
        foreach ($sourcePeriod->stages as $sourceStage) {
            $stage = $sourceStage->replicate();
            $stage->supervision_period_id = $period->id;
            $stage->save();
            foreach ($sourceStage->instruments as $sourceInstrument) {
                $instrument = $sourceInstrument->replicate();
                $instrument->supervision_stage_id = $stage->id;
                $instrument->save();
            }
        }
        $supervisorA = Lecturer::where('external_code', 'DSN001')->firstOrFail();
        $supervisorB = Lecturer::where('external_code', 'DSN002')->firstOrFail();
        $supervisorA->update(['name' => 'Supervisor Alpha']);
        $supervisorB->update(['name' => 'Supervisor Beta']);
        $targets = [];
        foreach (['unassigned', 'unfilled', 'empty', 'draft', 'completed', 'mixed', 'cancelled', 'excluded'] as $name) {
            $targets[$name] = $this->createTarget($period, $admin, 'Target '.$name);
        }
        $period->targets()->where('lecturer_id', $targets['excluded']->id)->update(['scope_status' => 'EXCLUDED']);
        $this->createTarget($sourcePeriod, $admin, 'Target other period');
        $this->createAssignment($period, $targets['unfilled'], $supervisorA, 1);
        $this->createAssignment($period, $targets['empty'], $supervisorA, 1, 'empty');
        $this->createAssignment($period, $targets['draft'], $supervisorA, 1, 'draft');
        $completedSupervision = $this->createAssignment($period, $targets['completed'], $supervisorA, 1, 'submitted')->supervision;
        $this->createAssignment($period, $targets['completed'], $supervisorA, 2, 'submitted');
        $this->createAssignment($period, $targets['mixed'], $supervisorA, 1, 'submitted');
        $this->createAssignment($period, $targets['mixed'], $supervisorB, 2, 'draft');
        $this->createAssignment($period, $targets['cancelled'], $supervisorA, 1, 'submitted')->update(['status' => 'CANCELLED']);
        $this->createAssignment($sourcePeriod, $targets['draft'], $supervisorA, 1, 'submitted');

        return compact('admin', 'period', 'supervisorA', 'supervisorB', 'completedSupervision');
    }

    private function createTarget(SupervisionPeriod $period, User $admin, string $name, ?int $studyProgramId = null): Lecturer
    {
        $lecturer = Lecturer::create(['name' => $name, 'is_active' => true, 'study_program_id' => $studyProgramId]);
        PeriodTarget::create([
            'supervision_period_id' => $period->id, 'lecturer_id' => $lecturer->id,
            'scope_status' => 'INCLUDED', 'selected_by' => $admin->id,
        ]);

        return $lecturer;
    }

    private function createAssignment(SupervisionPeriod $period, Lecturer $target, Lecturer $supervisor, int $stageNumber, ?string $progress = null): SupervisionAssignment
    {
        $stage = $period->stages()->where('stage_no', $stageNumber)->firstOrFail();
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id, 'target_lecturer_id' => $target->id,
            'supervisor_lecturer_id' => $supervisor->id, 'teaching_occurrence_id' => TeachingOccurrence::firstOrFail()->id,
            'assignment_type' => 'MANUAL', 'status' => 'FINAL',
        ]);
        if ($progress !== null) {
            $instrument = StageInstrument::where('supervision_stage_id', $stage->id)->firstOrFail();
            $supervision = Supervision::create([
                'supervision_assignment_id' => $assignment->id, 'stage_instrument_id' => $instrument->id,
                'form_template_id' => $instrument->form_template_id, 'scoring_policy_id' => $instrument->scoring_policy_id,
                'status' => $progress === 'submitted' ? 'SUBMITTED' : 'DRAFT',
                'final_score' => $progress === 'submitted' ? 3.5 : null,
                'submitted_at' => $progress === 'submitted' ? '2026-01-15 03:00:00' : null,
            ]);
            if (in_array($progress, ['draft', 'empty'], true)) {
                $supervision->answers()->create([
                    'question_id' => $instrument->formTemplate->questions()->firstOrFail()->id,
                    'boolean_value' => $progress === 'draft' ? false : null, 'text_value' => '',
                ]);
            }
        }

        return $assignment;
    }
}
