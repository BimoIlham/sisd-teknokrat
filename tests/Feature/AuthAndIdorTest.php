<?php

namespace Tests\Feature;

use App\Models\FormTemplate;
use App\Models\ScoringPolicy;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AuthAndIdorTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(DemoAcademicSeeder::class);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $response = $this->post('/login', [
            'email' => 'lpm@teknokrat.ac.id',
            'password' => 'Password123!',
        ]);

        $response->assertRedirect(route('lpm.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::where('email', 'lpm@teknokrat.ac.id')->first();
        $user->update(['is_active' => false]);

        $response = $this->post('/login', [
            'email' => 'lpm@teknokrat.ac.id',
            'password' => 'Password123!',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_user_with_must_change_password_flag_is_redirected(): void
    {
        $user = User::where('email', 'lpm@teknokrat.ac.id')->first();
        $user->update(['must_change_password' => true]);

        $response = $this->actingAs($user)->get(route('lpm.dashboard'));

        $response->assertRedirect(route('password.change'));
    }

    #[TestWith(['supervisor1@teknokrat.ac.id', 'supervisor.dashboard', false])]
    #[TestWith(['supervisor1@teknokrat.ac.id', 'supervisor.dashboard', true])]
    #[TestWith(['lpm@teknokrat.ac.id', 'lpm.dashboard', false])]
    public function test_existing_session_is_invalidated_on_the_next_request_after_account_deactivation(string $email, string $route, bool $json): void
    {
        $user = User::where('email', $email)->firstOrFail();
        $this->actingAs($user)->withSession(['inactive_test_marker' => 'old-session']);
        $oldSessionId = session()->getId();
        $oldToken = session()->token();
        User::whereKey($user->id)->update(['is_active' => false]);

        $response = $json ? $this->getJson(route($route)) : $this->get(route($route));
        if ($json) {
            $response->assertUnauthorized();
        } else {
            $response->assertRedirect(route('login'))->assertSessionHasErrors('email');
        }

        $this->assertGuest();
        $this->assertNull(session('inactive_test_marker'));
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
    }

    public function test_active_supervisor_can_login_and_access_its_dashboard(): void
    {
        $this->post('/login', [
            'email' => 'supervisor1@teknokrat.ac.id',
            'password' => 'Password123!',
        ])->assertRedirect(route('supervisor.dashboard'));
        $this->assertAuthenticated();
        $this->get(route('supervisor.dashboard'))->assertOk();
    }

    public function test_supervisor_cannot_access_lpm_dashboard(): void
    {
        $supervisor = User::where('email', 'supervisor1@teknokrat.ac.id')->first();

        $response = $this->actingAs($supervisor)->get(route('lpm.dashboard'));

        $response->assertStatus(403);
    }

    public function test_supervisor_cannot_access_or_edit_another_supervisors_assignment(): void
    {
        $supervisor1 = User::where('email', 'supervisor1@teknokrat.ac.id')->first();
        $supervisor2 = User::where('email', 'supervisor2@teknokrat.ac.id')->first();

        // Find assignment for supervisor 2
        $assignment = SupervisionAssignment::where('supervisor_lecturer_id', $supervisor2->lecturer_id)
            ->where('status', 'FINAL')
            ->first();

        if (! $assignment) {
            // Assign one for testing
            $stage = SupervisionStage::first();
            $occurrence = TeachingOccurrence::with('schedule')->first();
            $assignment = SupervisionAssignment::create([
                'supervision_stage_id' => $stage->id,
                'target_lecturer_id' => $occurrence->schedule->lecturer_id,
                'supervisor_lecturer_id' => $supervisor2->lecturer_id,
                'teaching_occurrence_id' => $occurrence->id,
                'assignment_type' => 'MANUAL',
                'status' => 'FINAL',
            ]);
        }

        // Supervisor 1 tries to access Supervisor 2's form
        $response = $this->actingAs($supervisor1)->get(route('supervisor.supervisions.form', $assignment));

        $response->assertStatus(403);
    }

    public function test_invariant_5_lecturer_cannot_view_their_own_supervision_evaluation(): void
    {
        // Find assignment where target lecturer also happens to be a supervisor
        $supervisor1 = User::where('email', 'supervisor1@teknokrat.ac.id')->first();
        $supervisor2 = User::where('email', 'supervisor2@teknokrat.ac.id')->first();

        $stage = SupervisionStage::first();
        $occurrence = TeachingOccurrence::first();
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id,
            'target_lecturer_id' => $supervisor1->lecturer_id, // Target is supervisor 1!
            'supervisor_lecturer_id' => $supervisor2->lecturer_id, // Evaluator is supervisor 2
            'teaching_occurrence_id' => $occurrence->id,
            'assignment_type' => 'MANUAL',
            'status' => 'FINAL',
        ]);

        $supervision = Supervision::create([
            'supervision_assignment_id' => $assignment->id,
            'form_template_id' => FormTemplate::first()->id,
            'scoring_policy_id' => ScoringPolicy::first()->id,
            'status' => 'SUBMITTED',
            'row_version' => 1,
            'final_score' => 3.85,
        ]);

        // Supervisor 1 (who was evaluated) tries to view their own evaluation
        $response = $this->actingAs($supervisor1)->get(route('supervisor.supervisions.show', $supervision));

        // Strict Invariant 5: Must be 403 Forbidden!
        $response->assertStatus(403);
    }
}
