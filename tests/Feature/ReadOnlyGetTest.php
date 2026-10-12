<?php

namespace Tests\Feature;

use App\Models\PeriodSupervisor;
use App\Models\SchedulingState;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\User;
use App\Services\Supervision\SubmitService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ReadOnlyGetTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    protected array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
        DB::listen(function (QueryExecuted $query): void {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|create|drop)\b/i', $query->sql)) {
                $this->writes[] = $query->sql;
            }
        });
    }

    private function assignment(): SupervisionAssignment
    {
        $this->stage->update(['status' => 'FINALIZED']);

        return SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'status' => 'FINAL', 'assignment_type' => 'MANUAL',
        ]);
    }

    #[TestWith(['admin', 'lpm.dashboard'])]
    #[TestWith(['supervisorUser', 'supervisor.dashboard'])]
    public function test_authenticated_login_get_preserves_session_and_role_redirect(string $userProperty, string $destination): void
    {
        $this->actingAs($this->{$userProperty})->withSession(['marker' => 'keep', 'url.intended' => '/keep-intended']);
        session()->save();
        $sessionId = session()->getId();
        $token = session()->token();
        $this->withCookie(config('session.cookie'), $sessionId);
        $this->get(route('login'))->assertRedirect(route($destination))->assertSessionHas('marker', 'keep')
            ->assertSessionHas('url.intended', '/keep-intended');
        $this->assertAuthenticatedAs($this->{$userProperty});
        $this->assertSame($sessionId, session()->getId());
        $this->assertSame($token, session()->token());
    }

    public function test_guest_login_get_and_explicit_logout_work(): void
    {
        auth()->logout();
        $this->get(route('login'))->assertOk();
        $this->actingAs($this->admin)->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_authenticated_account_without_role_gets_403_without_logout_loop(): void
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actingAs($user)->get(route('login'))->assertForbidden();
        $this->assertAuthenticatedAs($user);
    }

    public function test_form_get_is_read_only_and_post_start_is_idempotent(): void
    {
        $assignment = $this->assignment();
        SchedulingState::whereKey(1)->delete();
        $this->actingAs($this->supervisorUser);
        $this->writes = [];
        $this->get(route('supervisor.supervisions.form', $assignment))->assertOk()
            ->assertViewIs('supervisor.supervisions.start')->assertSee('Mulai Penilaian');
        $this->assertSame([], $this->writes);
        $this->assertDatabaseMissing('supervisions', ['supervision_assignment_id' => $assignment->id]);
        $this->assertDatabaseMissing('scheduling_state', ['id' => 1]);
        $this->post(route('supervisor.supervisions.start', $assignment))->assertRedirect(route('supervisor.supervisions.form', $assignment));
        $supervision = Supervision::where('supervision_assignment_id', $assignment->id)->firstOrFail();
        $before = $supervision->getRawOriginal();
        $this->post(route('supervisor.supervisions.start', $assignment))->assertRedirect(route('supervisor.supervisions.form', $assignment));
        $this->assertSame($before, $supervision->fresh()->getRawOriginal());
        $this->assertSame(1, Supervision::where('supervision_assignment_id', $assignment->id)->count());
        $this->writes = [];
        $this->get(route('supervisor.supervisions.form', $assignment))->assertOk()->assertViewIs('supervisor.supervisions.form');
        $this->assertSame([], $this->writes);
    }

    public function test_review_get_without_state_does_not_initialize_it(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($this->supervisorUser)->post(route('supervisor.supervisions.start', $assignment));
        $supervision = Supervision::where('supervision_assignment_id', $assignment->id)->firstOrFail();
        $this->answerSupervision($supervision);
        SchedulingState::whereKey(1)->delete();
        $before = $supervision->getRawOriginal();
        $this->writes = [];
        $this->get(route('supervisor.supervisions.review', $supervision))->assertRedirect(route('supervisor.supervisions.form', $assignment))
            ->assertSessionHasErrors('preparation');
        $this->assertSame([], $this->writes);
        $this->assertDatabaseMissing('scheduling_state', ['id' => 1]);
        $this->assertSame($before, $supervision->fresh()->getRawOriginal());
        $this->post(route('supervisor.supervisions.start', $assignment))->assertRedirect();
        $this->writes = [];
        $this->get(route('supervisor.supervisions.review', $supervision))->assertOk();
        $this->assertSame([], $this->writes);
    }

    public function test_start_without_instrument_rolls_back_preparation(): void
    {
        $assignment = $this->assignment();
        $this->stage->instruments()->delete();
        SchedulingState::whereKey(1)->delete();
        $this->actingAs($this->supervisorUser)->post(route('supervisor.supervisions.start', $assignment))
            ->assertRedirect(route('supervisor.supervisions.form', $assignment))->assertSessionHasErrors('instrument');
        $this->assertDatabaseMissing('supervisions', ['supervision_assignment_id' => $assignment->id]);
        $this->assertDatabaseMissing('scheduling_state', ['id' => 1]);
    }

    public function test_existing_draft_and_answers_survive_repeated_start_and_submitted_result_redirects(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($this->supervisorUser)->post(route('supervisor.supervisions.start', $assignment))->assertRedirect();
        $supervision = Supervision::where('supervision_assignment_id', $assignment->id)->firstOrFail();
        $this->answerSupervision($supervision);
        $supervision->update(['row_version' => 7]);
        $before = $supervision->fresh()->getRawOriginal();
        $answers = $supervision->answers()->get()->toArray();
        $this->post(route('supervisor.supervisions.start', $assignment))->assertRedirect(route('supervisor.supervisions.form', $assignment));
        $this->assertSame($before, $supervision->fresh()->getRawOriginal());
        $this->assertSame($answers, $supervision->answers()->get()->toArray());
        app(SubmitService::class)->submit($supervision, $this->supervisorUser, 7);
        $before = $supervision->fresh()->getRawOriginal();
        $this->post(route('supervisor.supervisions.start', $assignment))->assertRedirect(route('supervisor.supervisions.show', $supervision));
        $this->assertSame($before, $supervision->fresh()->getRawOriginal());
    }

    #[TestWith(['ownership'])]
    #[TestWith(['membership'])]
    #[TestWith(['assignment_draft'])]
    #[TestWith(['cancelled'])]
    #[TestWith(['stage_draft'])]
    #[TestWith(['window'])]
    public function test_post_start_preserves_authorization_rules(string $condition): void
    {
        $assignment = $this->assignment();
        match ($condition) {
            'ownership' => $assignment->update(['supervisor_lecturer_id' => $this->target->id]),
            'membership' => PeriodSupervisor::where('supervision_period_id', $this->period->id)->update(['is_active' => false]),
            'assignment_draft' => $assignment->update(['status' => 'DRAFT']),
            'cancelled' => $assignment->update(['status' => 'CANCELLED']),
            'stage_draft' => $this->stage->update(['status' => 'DRAFT']),
            'window' => $this->travelTo(now()->addMonths(3)),
        };
        $this->actingAs($this->supervisorUser)->post(route('supervisor.supervisions.start', $assignment))->assertForbidden();
        $this->assertDatabaseMissing('supervisions', ['supervision_assignment_id' => $assignment->id]);
    }

    public function test_workspace_get_is_read_only_and_explicit_preparation_works(): void
    {
        $this->period->update(['active_scheduling_rule_id' => null]);
        $this->period->stages->each(fn ($stage) => $stage->instruments()->delete());
        SchedulingState::whereKey(1)->delete();
        $before = $this->period->fresh()->getRawOriginal();
        $this->writes = [];
        $this->get(route('lpm.assignments.index'))->assertOk()->assertSee('Siapkan Workspace');
        $this->assertSame([], $this->writes);
        $this->assertSame($before, $this->period->fresh()->getRawOriginal());
        $this->assertDatabaseMissing('scheduling_state', ['id' => 1]);
        $this->post(route('lpm.assignments.prepare', $this->period))->assertRedirect(route('lpm.assignments.index'));
        $this->assertNotNull($this->period->fresh()->active_scheduling_rule_id);
        $this->assertDatabaseHas('scheduling_state', ['id' => 1]);
        $this->assertSame(4, $this->period->stages->sum(fn ($stage) => $stage->instruments()->count()));
        $revision = SchedulingState::findOrFail(1)->revision;
        $this->post(route('lpm.assignments.prepare', $this->period))->assertRedirect();
        $this->assertSame($revision, SchedulingState::findOrFail(1)->revision);
        $this->writes = [];
        $this->get(route('lpm.assignments.index'))->assertOk()->assertDontSee('Siapkan Workspace');
        $this->assertSame([], $this->writes);
    }

    public function test_workspace_get_without_active_period_does_not_create_defaults(): void
    {
        $this->period->update(['status' => 'CLOSED']);
        SchedulingState::whereKey(1)->delete();
        $this->writes = [];
        $this->get(route('lpm.assignments.index'))->assertOk();
        $this->assertSame([], $this->writes);
    }

    public function test_preparation_requires_auth_role_and_completed_onboarding(): void
    {
        $assignment = $this->assignment();
        auth()->logout();
        $this->post(route('supervisor.supervisions.start', $assignment))->assertRedirect(route('login'));
        $this->post(route('lpm.assignments.prepare', $this->period))->assertRedirect(route('login'));
        $this->actingAs($this->supervisorUser)->post(route('lpm.assignments.prepare', $this->period))->assertForbidden();
        $this->supervisorUser->update(['must_change_password' => true]);
        $this->post(route('supervisor.supervisions.start', $assignment))->assertRedirect(route('password.change'));
        $this->actingAs($this->admin)->post(route('supervisor.supervisions.start', $assignment))->assertForbidden();
        $this->period->update(['status' => 'CLOSED']);
        $this->post(route('lpm.assignments.prepare', $this->period))->assertNotFound();
    }

    #[TestWith(['start'])]
    #[TestWith(['workspace'])]
    public function test_preparation_posts_require_csrf(string $endpoint): void
    {
        $assignment = $this->assignment();
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->actingAs($endpoint === 'start' ? $this->supervisorUser : $this->admin)->withSession(['_token' => 'csrf-test-token']);
        $url = $endpoint === 'start' ? route('supervisor.supervisions.start', $assignment) : route('lpm.assignments.prepare', $this->period);
        $this->post($url)->assertStatus(419);
        $this->post($url, ['_token' => 'csrf-test-token'])->assertRedirect();
    }
}
