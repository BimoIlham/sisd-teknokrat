<?php

namespace Tests\Feature;

use App\Models\PeriodSupervisor;
use App\Models\PeriodTarget;
use App\Models\SchedulingState;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AssignmentWorkspaceTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected User $supervisorUser;

    protected function setUp(): void
    {
        parent::setUp();

        if (User::count() === 0) {
            $this->seed(DatabaseSeeder::class);
        }

        if (SupervisionStage::count() === 0 || TeachingOccurrence::count() === 0) {
            $this->seed(UserSeeder::class);
            $this->seed(DemoAcademicSeeder::class);
        }

        $this->admin = User::firstOrCreate([
            'email' => 'lpm@teknokrat.ac.id',
        ], [
            'name' => 'Administrator LPM',
            'password' => bcrypt('Password123!'),
        ]);

        if (! $this->admin->hasRole('superadmin')) {
            $this->seed(RoleAndPermissionSeeder::class);
            $this->admin->assignRole('superadmin');
        }

        $this->supervisorUser = User::firstOrCreate([
            'email' => 'supervisor_test@teknokrat.ac.id',
        ], [
            'name' => 'Dosen Supervisor Test',
            'password' => bcrypt('Password123!'),
        ]);

        if (! $this->supervisorUser->hasRole('supervisor')) {
            $this->supervisorUser->assignRole('supervisor');
        }
    }

    public function test_guest_cannot_access_assignment_workspace(): void
    {
        $response = $this->get(route('lpm.assignments.index'));
        $response->assertRedirect(route('login'));
    }

    public function test_supervisor_cannot_access_lpm_assignment_workspace(): void
    {
        $response = $this->actingAs($this->supervisorUser)->get(route('lpm.assignments.index'));
        $response->assertStatus(403);
    }

    public function test_superadmin_can_access_assignment_workspace_and_sees_stats(): void
    {
        $response = $this->actingAs($this->admin)->get(route('lpm.assignments.index'));
        $response->assertStatus(200);
        $response->assertViewHas(['stats', 'stagesSummary', 'targets', 'assignments', 'supervisors']);
        $response->assertSee('Workspace Penugasan Supervisi');
        $response->assertViewHas('stats', fn ($stats) => array_key_exists('coverage_rate', $stats));
    }

    public function test_superadmin_can_unassign_an_assignment(): void
    {
        $stage = SupervisionStage::first();
        $target = PeriodTarget::where('supervision_period_id', $stage->supervision_period_id)->first();
        $supervisor = PeriodSupervisor::where('supervision_period_id', $stage->supervision_period_id)->first();
        $occurrence = TeachingOccurrence::first();

        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id,
            'target_lecturer_id' => $target->lecturer_id,
            'supervisor_lecturer_id' => $supervisor->lecturer_id,
            'teaching_occurrence_id' => $occurrence->id,
            'assignment_type' => 'MANUAL',
            'status' => 'DRAFT',
        ]);

        $initialRevision = SchedulingState::firstOrCreate(['id' => 1], ['revision' => 1])->revision;

        $response = $this->actingAs($this->admin)
            ->delete(route('lpm.assignments.unassign', [$stage, $assignment]));

        $response->assertRedirect();
        $this->assertEquals('CANCELLED', $assignment->fresh()->status);

        $this->assertDatabaseHas('assignment_audits', [
            'supervision_assignment_id' => $assignment->id,
            'action' => 'PERIOD_PAIR_CANCEL',
        ]);

        $this->assertEquals($initialRevision + 1, SchedulingState::find(1)->revision);
    }

    public function test_superadmin_can_reset_auto_assignments(): void
    {
        $stage = SupervisionStage::first();
        $targets = PeriodTarget::where('supervision_period_id', $stage->supervision_period_id)->take(2)->get();
        $supervisor = PeriodSupervisor::where('supervision_period_id', $stage->supervision_period_id)->first();
        $occurrence = TeachingOccurrence::first();

        // 1 AUTO and 1 MANUAL assignment
        $autoAssignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id,
            'target_lecturer_id' => $targets[0]->lecturer_id,
            'supervisor_lecturer_id' => $supervisor->lecturer_id,
            'teaching_occurrence_id' => $occurrence->id,
            'assignment_type' => 'AUTO',
            'status' => 'DRAFT',
        ]);

        $manualAssignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id,
            'target_lecturer_id' => $targets[1]->lecturer_id,
            'supervisor_lecturer_id' => $supervisor->lecturer_id,
            'teaching_occurrence_id' => $occurrence->id,
            'assignment_type' => 'MANUAL',
            'status' => 'DRAFT',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('lpm.assignments.reset_auto', $stage));

        $response->assertRedirect();
        $this->assertEquals('CANCELLED', $autoAssignment->fresh()->status);
        $this->assertEquals('DRAFT', $manualAssignment->fresh()->status);
    }
}
