<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Lecturer;
use App\Models\PeriodSupervisor;
use App\Models\SupervisionPeriod;
use App\Models\SystemAudit;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class SupervisorPasswordResetTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $supervisorUser;

    private Lecturer $supervisor;

    private SupervisionPeriod $period;

    private string $oldPassword;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sisd_teknokrat_test', DB::connection()->getDatabaseName());
        config(['session.driver' => 'database']);
        $this->seed(RoleAndPermissionSeeder::class);
        $this->oldPassword = Str::password(24);
        $this->admin = User::factory()->create(['password' => Hash::make($this->oldPassword), 'is_active' => true, 'must_change_password' => false]);
        $this->admin->assignRole('superadmin');
        $this->supervisor = Lecturer::create(['name' => 'Supervisor Uji Reset Password', 'is_active' => true]);
        $this->supervisorUser = User::factory()->create(['lecturer_id' => $this->supervisor->id, 'password' => Hash::make($this->oldPassword), 'is_active' => true, 'must_change_password' => false]);
        $this->supervisorUser->assignRole('supervisor');
        $term = AcademicTerm::create(['academic_year' => '2042/2043', 'semester' => 'GANJIL', 'starts_on' => '2042-09-01', 'ends_on' => '2043-01-31']);
        $this->period = SupervisionPeriod::create(['academic_term_id' => $term->id, 'name' => 'Periode Uji Reset Password', 'starts_on' => $term->starts_on, 'ends_on' => $term->ends_on, 'status' => 'ACTIVE', 'created_by' => $this->admin->id]);
        PeriodSupervisor::create(['supervision_period_id' => $this->period->id, 'lecturer_id' => $this->supervisor->id, 'is_active' => true, 'selected_by' => $this->admin->id]);
        $this->actingAs($this->admin);
    }

    public function test_admin_can_reset_only_the_selected_supervisor_to_a_hashed_default_password(): void
    {
        $adminHash = $this->admin->password;
        $other = User::factory()->create();
        $otherHash = $other->password;
        $payload = $this->selection();
        $this->post($this->resetUrl(), $payload)->assertRedirect($this->directoryUrl())->assertSessionHas('success');
        $fresh = $this->supervisorUser->fresh();
        $this->assertTrue(Hash::check('Teknokrat123@', $fresh->password));
        $this->assertFalse(Hash::check($this->oldPassword, $fresh->password));
        $this->assertTrue($fresh->must_change_password);
        $this->assertTrue($fresh->is_active);
        $this->assertSame($adminHash, $this->admin->fresh()->password);
        $this->assertSame($otherHash, $other->fresh()->password);
        $this->assertAuthenticatedAs($this->admin);
        $audit = SystemAudit::where('event', 'SUPERVISOR_PASSWORD_RESET')->sole();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame($fresh->id, $audit->entity_id);
        $this->assertStringNotContainsString('Teknokrat123@', $audit->toJson());
        $this->assertStringNotContainsString($fresh->password, $audit->toJson());
        $this->post($this->resetUrl(), $payload)->assertSessionHas('error');
        $this->assertSame(1, SystemAudit::where('event', 'SUPERVISOR_PASSWORD_RESET')->count());
    }

    #[TestWith(['tampered'])]
    #[TestWith(['expired'])]
    #[TestWith(['actor'])]
    #[TestWith(['changed_password'])]
    #[TestWith(['email'])]
    public function test_invalid_or_stale_confirmations_do_not_reset_passwords(string $case): void
    {
        $payload = $this->selection();
        if ($case === 'tampered') {
            $payload['credential_state'] = 'invalid-encrypted-selection';
        } elseif ($case === 'email') {
            $payload['confirm_email'] = 'different@example.test';
        } elseif ($case === 'changed_password') {
            $this->supervisorUser->update(['password' => Hash::make(Str::password(24))]);
        } else {
            $selection = json_decode(Crypt::decryptString($payload['credential_state']), true);
            if ($case === 'expired') {
                $selection['expires_at'] = now()->subMinute()->timestamp;
            } else {
                $selection['actor_id'] = $this->admin->id + 1;
            }
            $payload['credential_state'] = Crypt::encryptString(json_encode($selection));
        }
        $hash = $this->supervisorUser->fresh()->password;
        $this->post($this->resetUrl(), $payload)->assertSessionHas('error');
        $this->assertSame($hash, $this->supervisorUser->fresh()->password);
        $this->assertDatabaseMissing('system_audits', ['event' => 'SUPERVISOR_PASSWORD_RESET']);
    }

    public function test_reset_requires_confirmation_and_never_accepts_an_arbitrary_password(): void
    {
        $payload = $this->selection();
        $this->post($this->resetUrl(), array_replace($payload, ['confirm_reset' => '0']))->assertSessionHasErrors('confirm_reset');
        $this->post($this->resetUrl(), [...$payload, 'password' => Str::password(24)])->assertSessionHasErrors('password');
        $this->get($this->resetUrl())->assertStatus(405);
        $this->assertTrue(Hash::check($this->oldPassword, $this->supervisorUser->fresh()->password));
    }

    public function test_reset_preserves_inactive_account_status_and_rejects_revoked_period_membership(): void
    {
        $payload = $this->selection();
        $this->supervisorUser->update(['is_active' => false]);
        $this->post($this->resetUrl(), $payload)->assertSessionHas('success');
        $this->assertFalse($this->supervisorUser->fresh()->is_active);
        $payload = $this->selection();
        PeriodSupervisor::where('lecturer_id', $this->supervisor->id)->update(['is_active' => false]);
        $hash = $this->supervisorUser->fresh()->password;
        $this->post($this->resetUrl(), $payload)->assertNotFound();
        $this->assertSame($hash, $this->supervisorUser->fresh()->password);
    }

    public function test_guest_supervisor_and_dual_role_admin_accounts_cannot_be_reset(): void
    {
        $payload = $this->selection();
        auth()->logout();
        $this->post($this->resetUrl(), $payload)->assertRedirect(route('login'));
        $this->withSession(['password_hash_web' => null])->actingAs($this->supervisorUser)
            ->post($this->resetUrl(), $payload)->assertForbidden();
        $this->supervisorUser->assignRole('superadmin');
        $this->withSession(['password_hash_web' => null])->actingAs($this->admin)
            ->post($this->resetUrl(), $payload)->assertForbidden();
        $this->assertTrue(Hash::check($this->oldPassword, $this->supervisorUser->fresh()->password));
    }

    public function test_reset_revokes_old_sessions_and_remember_cookie_and_forces_password_onboarding(): void
    {
        $sessionA = $this->loginBrowser($this->supervisorUser);
        $sessionB = $this->loginBrowser($this->supervisorUser);
        $stalePayload = DB::table('sessions')->where('id', $sessionB)->value('payload');
        $oldToken = $this->supervisorUser->fresh()->getRememberToken();
        $oldCookie = $this->supervisorUser->id.'|'.$oldToken.'|'.Auth::guard()->hashPasswordForCookie($this->supervisorUser->fresh()->password);
        $adminSession = $this->loginBrowser($this->admin);
        $this->post($this->resetUrl(), $this->selection())->assertSessionHas('success');
        $this->assertDatabaseMissing('sessions', ['id' => $sessionA]);
        $this->assertDatabaseMissing('sessions', ['id' => $sessionB]);
        $this->assertDatabaseHas('sessions', ['id' => $adminSession, 'user_id' => $this->admin->id]);
        $this->assertNotSame($oldToken, $this->supervisorUser->fresh()->getRememberToken());
        $this->beginBrowser();
        $this->withCookie(Auth::guard()->getRecallerName(), $oldCookie)->get(route('supervisor.dashboard'))->assertRedirect(route('login'));
        DB::table('sessions')->insert(['id' => $sessionB, 'user_id' => $this->supervisorUser->id, 'payload' => $stalePayload, 'last_activity' => now()->timestamp]);
        $this->beginBrowser($sessionB);
        $this->get(route('supervisor.dashboard'))->assertRedirect(route('login'));
        $this->beginBrowser();
        $this->post('/login', ['email' => $this->supervisorUser->email, 'password' => $this->oldPassword])->assertSessionHasErrors('email');
        $this->beginBrowser();
        $this->post('/login', ['email' => $this->supervisorUser->email, 'password' => 'Teknokrat123@'])->assertRedirect(route('password.change'));
        $this->get(route('supervisor.dashboard'))->assertRedirect(route('password.change'));
        $this->post(route('password.update'), ['current_password' => 'Teknokrat123@', 'password' => 'Teknokrat123@', 'password_confirmation' => 'Teknokrat123@'])->assertSessionHasErrors('password');
        $newPassword = Str::password(24);
        $this->post(route('password.update'), ['current_password' => 'Teknokrat123@', 'password' => $newPassword, 'password_confirmation' => $newPassword])->assertRedirect(route('supervisor.dashboard'));
        $this->assertFalse($this->supervisorUser->fresh()->must_change_password);
    }

    public function test_audit_failure_rolls_back_password_and_session_changes(): void
    {
        $session = $this->loginBrowser($this->supervisorUser);
        $this->loginBrowser($this->admin);
        $payload = $this->selection();
        $hash = $this->supervisorUser->fresh()->password;
        $token = $this->supervisorUser->fresh()->getRememberToken();
        $original = Model::getEventDispatcher();
        $dispatcher = clone $original;
        $dispatcher->listen('eloquent.creating: '.SystemAudit::class, function (SystemAudit $audit): void {
            if ($audit->event === 'SUPERVISOR_PASSWORD_RESET') {
                throw new RuntimeException('Synthetic reset audit failure');
            }
        });
        Model::setEventDispatcher($dispatcher);
        try {
            $this->post($this->resetUrl(), $payload)->assertSessionHas('error')->assertSessionHas('error_reference');
        } finally {
            Model::setEventDispatcher($original);
        }
        $this->assertSame($hash, $this->supervisorUser->fresh()->password);
        $this->assertSame($token, $this->supervisorUser->fresh()->getRememberToken());
        $this->assertDatabaseHas('sessions', ['id' => $session]);
    }

    /** @return array{period_id: int, confirm_email: string, confirm_reset: string, credential_state: string} */
    private function selection(): array
    {
        $response = $this->get($this->directoryUrl())->assertOk()->assertSee('Reset Password');

        return ['period_id' => $this->period->id, 'confirm_email' => $this->supervisorUser->email, 'confirm_reset' => '1', 'credential_state' => $response->viewData('rotationSelections')[$this->supervisor->id]];
    }

    private function directoryUrl(): string
    {
        return route('lpm.supervisors.index', ['period_id' => $this->period->id]);
    }

    private function resetUrl(): string
    {
        return route('lpm.supervisors.reset_password', $this->supervisor);
    }

    private function beginBrowser(?string $sessionId = null): void
    {
        $this->defaultCookies = [];
        foreach ($this->app['cookie']->getQueuedCookies() as $cookie) {
            $this->app['cookie']->unqueue($cookie->getName());
        }
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app['redirect']->setSession($this->app['session.store']);
        if ($sessionId !== null) {
            $this->withCookie(config('session.cookie'), $sessionId);
        }
    }

    private function loginBrowser(User $user): string
    {
        $this->beginBrowser();
        $this->post('/login', ['email' => $user->email, 'password' => $this->oldPassword, 'remember' => '1'])->assertSessionHasNoErrors();
        $id = session()->getId();
        $this->beginBrowser($id);
        $this->get(route('password.change'))->assertOk();
        $this->assertAuthenticatedAs($user);

        return session()->getId();
    }
}
