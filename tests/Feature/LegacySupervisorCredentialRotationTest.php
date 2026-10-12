<?php

namespace Tests\Feature;

use App\Models\Lecturer;
use App\Models\PeriodSupervisor;
use App\Models\SystemAudit;
use App\Models\User;
use App\Services\LegacySupervisorCredentialInspector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class LegacySupervisorCredentialRotationTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    private const FIXTURE_PASSWORD = 'SyntheticLegacyFixture789!';

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database', 'session.connection' => null, 'session.table' => 'sessions']);
        $this->buildSchedulingFixture();
        $this->supervisorUser->update(['password' => Hash::make(self::FIXTURE_PASSWORD)]);
        $this->admin->update(['password' => Hash::make(self::FIXTURE_PASSWORD)]);
    }

    public function test_known_historical_credential_identifies_a_candidate_without_mutating_it(): void
    {
        $inspector = app(LegacySupervisorCredentialInspector::class);
        $hash = $this->supervisorUser->password;
        $this->assertTrue($inspector->matchesKnownHistoricalPassword($this->supervisorUser, self::FIXTURE_PASSWORD));
        $this->assertSame($hash, $this->supervisorUser->fresh()->password);
        $this->assertFalse($inspector->matchesKnownHistoricalPassword($this->admin, self::FIXTURE_PASSWORD));
        $this->assertFalse($inspector->matchesKnownHistoricalPassword($this->supervisorUser, ''));
    }

    public function test_personal_password_is_not_a_candidate_for_known_historical_credential(): void
    {
        $this->supervisorUser->update(['password' => Hash::make(Str::password(24))]);
        $hash = $this->supervisorUser->password;
        $this->assertFalse(app(LegacySupervisorCredentialInspector::class)
            ->matchesKnownHistoricalPassword($this->supervisorUser->fresh(), self::FIXTURE_PASSWORD));
        $this->assertSame($hash, $this->supervisorUser->fresh()->password);
    }

    public function test_directory_requires_manual_identification_and_does_not_reset_any_account(): void
    {
        $oldHash = $this->supervisorUser->password;
        $response = $this->get($this->directoryUrl())->assertOk()
            ->assertSee('Status password lama belum dapat dipastikan.')
            ->assertSee('Rotasi password supervisor');
        $this->assertStringNotContainsString($oldHash, $response->getContent());
        $this->assertSame($oldHash, $this->supervisorUser->fresh()->password);
        $this->supervisorUser->update(['password' => Hash::make(Str::password(24))]);
        $changedHash = $this->supervisorUser->password;
        $this->get($this->directoryUrl())->assertOk();
        $this->assertSame($changedHash, $this->supervisorUser->fresh()->password);
        $this->assertDatabaseMissing('system_audits', ['event' => 'LEGACY_CREDENTIAL_ROTATED']);
    }

    public function test_admin_rotation_is_per_account_hashed_audited_and_displayed_once(): void
    {
        $adminHash = $this->admin->password;
        $other = User::factory()->create(['must_change_password' => false]);
        $otherHash = $other->password;
        $payload = $this->selection();
        $bindings = [];
        DB::listen(function (QueryExecuted $query) use (&$bindings): void {
            array_push($bindings, ...$query->bindings);
        });
        $this->post($this->rotationUrl(), $payload)->assertRedirect($this->directoryUrl())->assertSessionHasNoErrors();
        $encrypted = session('supervisor_rotated_credential');
        $credential = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        $temporary = $credential['password'];
        $this->assertSame(24, strlen($temporary));
        $this->assertTrue(Hash::check($temporary, $this->supervisorUser->fresh()->password));
        $this->assertFalse(Hash::check(self::FIXTURE_PASSWORD, $this->supervisorUser->fresh()->password));
        $this->assertTrue($this->supervisorUser->fresh()->must_change_password);
        $this->assertTrue($this->supervisorUser->fresh()->is_active);
        $this->assertSame($otherHash, $other->fresh()->password);
        $this->assertSame($adminHash, $this->admin->fresh()->password);
        $this->assertAuthenticatedAs($this->admin);
        $this->assertNotSame($temporary, $encrypted);
        foreach (DB::table('sessions')->pluck('payload') as $stored) {
            $this->assertStringNotContainsString($temporary, base64_decode($stored));
        }
        foreach ($bindings as $binding) {
            if (is_string($binding)) {
                $this->assertStringNotContainsString($temporary, $binding);
            }
        }
        $audit = SystemAudit::where('event', 'LEGACY_CREDENTIAL_ROTATED')->sole();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame(User::class, $audit->entity_type);
        $this->assertSame($this->supervisorUser->id, $audit->entity_id);
        $this->assertNotNull($audit->created_at);
        $this->assertSame('admin_selected_legacy', $audit->new_values['source']);
        foreach (SystemAudit::all() as $entry) {
            $this->assertStringNotContainsString($temporary, $entry->toJson());
            $this->assertStringNotContainsString($this->supervisorUser->fresh()->password, $entry->toJson());
        }
        if (is_file(storage_path('logs/laravel.log'))) {
            $this->assertStringNotContainsString($temporary, file_get_contents(storage_path('logs/laravel.log')));
        }
        $this->get($this->directoryUrl())->assertOk()->assertSee(e($temporary), false)
            ->assertSee($credential['email'])->assertHeader('Cache-Control', 'no-store, private');
        $this->get($this->directoryUrl())->assertOk()->assertDontSee(e($temporary), false);
        $this->assertNull(session('supervisor_rotated_credential'));
        $this->post($this->rotationUrl(), $payload)->assertSessionHasErrors('credential_state');
        $this->assertTrue(Hash::check($temporary, $this->supervisorUser->fresh()->password));
    }

    public function test_rotation_revokes_real_browser_sessions_stale_session_and_remember_cookie(): void
    {
        $supervisorSession = $this->loginBrowser($this->supervisorUser);
        $stalePayload = DB::table('sessions')->where('id', $supervisorSession)->value('payload');
        $oldToken = $this->supervisorUser->fresh()->getRememberToken();
        $oldRecaller = $this->supervisorUser->id.'|'.$oldToken.'|'.Auth::guard()->hashPasswordForCookie($this->supervisorUser->password);
        $adminSession = $this->loginBrowser($this->admin);
        $this->post($this->rotationUrl(), $this->selection())->assertSessionHasNoErrors();
        $temporary = json_decode(Crypt::decryptString(session('supervisor_rotated_credential')), true)['password'];
        $this->assertDatabaseMissing('sessions', ['id' => $supervisorSession]);
        $this->assertDatabaseHas('sessions', ['id' => $adminSession, 'user_id' => $this->admin->id]);
        $this->assertNotSame($oldToken, $this->supervisorUser->fresh()->getRememberToken());
        $this->beginBrowser($supervisorSession);
        $this->get(route('password.change'))->assertRedirect(route('login'));
        $this->assertGuest();
        DB::table('sessions')->updateOrInsert(['id' => $supervisorSession], ['user_id' => $this->supervisorUser->id,
            'payload' => $stalePayload, 'last_activity' => time()]);
        $this->beginBrowser($supervisorSession);
        $this->get(route('password.change'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->beginBrowser();
        $this->withCookie(Auth::guard()->getRecallerName(), $oldRecaller)
            ->get(route('password.change'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->beginBrowser();
        $this->post('/login', ['email' => $this->supervisorUser->email, 'password' => self::FIXTURE_PASSWORD])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->beginBrowser();
        $this->post('/login', ['email' => $this->supervisorUser->email, 'password' => $temporary])
            ->assertRedirect(route('password.change'));
        $sessionId = session()->getId();
        $this->beginBrowser($sessionId);
        $this->get(route('supervisor.dashboard'))->assertRedirect(route('password.change'));
        $newPassword = 'SyntheticPersonalPassword123!';
        $this->post(route('password.update'), ['current_password' => $temporary,
            'password' => $newPassword, 'password_confirmation' => $newPassword])->assertSessionHasNoErrors();
        $this->assertFalse($this->supervisorUser->fresh()->must_change_password);
        $this->assertFalse(Hash::check($temporary, $this->supervisorUser->fresh()->password));
        $this->assertTrue(Hash::check($newPassword, $this->supervisorUser->fresh()->password));
        $this->get(route('supervisor.dashboard'))->assertOk();
    }

    public function test_password_changed_after_selection_is_preserved(): void
    {
        $payload = $this->selection();
        $this->supervisorUser->update(['password' => Hash::make(Str::password(24))]);
        $hash = $this->supervisorUser->password;
        $this->post($this->rotationUrl(), $payload)->assertSessionHasErrors('credential_state');
        $this->assertSame($hash, $this->supervisorUser->fresh()->password);
        $this->assertFalse($this->supervisorUser->fresh()->must_change_password);
        $this->assertDatabaseMissing('system_audits', ['event' => 'LEGACY_CREDENTIAL_ROTATED']);
    }

    #[TestWith(['email'])]
    #[TestWith(['confirmation'])]
    #[TestWith(['tamper'])]
    #[TestWith(['expired'])]
    #[TestWith(['actor'])]
    #[TestWith(['target'])]
    public function test_invalid_selection_never_rotates_password(string $failure): void
    {
        $payload = $this->selection();
        if ($failure === 'email') {
            $payload['confirm_email'] = 'incorrect@example.test';
        } elseif ($failure === 'confirmation') {
            unset($payload['confirm_legacy']);
        } elseif ($failure === 'tamper') {
            $payload['credential_state'] = 'invalid-ciphertext';
        } else {
            $state = json_decode(Crypt::decryptString($payload['credential_state']), true);
            $field = ['expired' => 'expires_at', 'actor' => 'actor_id', 'target' => 'user_id'][$failure];
            $state[$field] = 0;
            $payload['credential_state'] = Crypt::encryptString(json_encode($state));
        }
        $hash = $this->supervisorUser->password;
        $this->post($this->rotationUrl(), $payload)->assertSessionHasErrors();
        $this->assertSame($hash, $this->supervisorUser->fresh()->password);
        $this->assertNull(session('supervisor_rotated_credential'));
    }

    public function test_only_authorized_admin_can_rotate_a_linked_supervisor_account(): void
    {
        $payload = $this->selection();
        $this->beginBrowser();
        $this->post($this->rotationUrl(), $payload)->assertRedirect(route('login'));
        $this->beginBrowser();
        $this->actingAs($this->supervisorUser)->post($this->rotationUrl(), $payload)->assertForbidden();
        $this->beginBrowser();
        $this->actingAs($this->admin);
        $this->supervisorUser->assignRole('superadmin');
        $this->post($this->rotationUrl(), $payload)->assertForbidden();
        $this->supervisorUser->removeRole('superadmin');
        $this->supervisorUser->removeRole('supervisor');
        $this->post($this->rotationUrl(), $payload)->assertForbidden();
        $this->supervisorUser->assignRole('supervisor');
        PeriodSupervisor::where('lecturer_id', $this->supervisor->id)->delete();
        $this->post($this->rotationUrl(), $payload)->assertNotFound();
        $this->assertTrue(Hash::check(self::FIXTURE_PASSWORD, $this->supervisorUser->fresh()->password));
    }

    public function test_rotation_requires_csrf_and_is_not_a_get_operation(): void
    {
        $payload = $this->selection();
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->withSession(['_token' => 'rotation-csrf-test']);
        $this->post($this->rotationUrl(), $payload)->assertStatus(419);
        $this->get($this->rotationUrl())->assertStatus(405);
        $this->assertTrue(Hash::check(self::FIXTURE_PASSWORD, $this->supervisorUser->fresh()->password));
        $this->post($this->rotationUrl(), [...$payload, '_token' => 'rotation-csrf-test'])->assertSessionHasNoErrors();
    }

    public function test_rotation_is_rate_limited_without_changing_the_account(): void
    {
        $payload = $this->selection();
        $payload['confirm_email'] = 'incorrect@example.test';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post($this->rotationUrl(), $payload)->assertSessionHasErrors('confirm_email');
        }
        $this->post($this->rotationUrl(), $payload)->assertStatus(429);
        $this->assertTrue(Hash::check(self::FIXTURE_PASSWORD, $this->supervisorUser->fresh()->password));
        $this->assertDatabaseMissing('system_audits', ['event' => 'LEGACY_CREDENTIAL_ROTATED']);
    }

    public function test_other_period_or_missing_account_cannot_be_rotated(): void
    {
        $payload = $this->selection();
        $payload['period_id'] = 0;
        $this->post($this->rotationUrl(), $payload)->assertSessionHasErrors('period_id');
        $lecturer = Lecturer::create(['name' => 'No Linked Account', 'external_code' => 'F02-'.Str::random(10)]);
        $this->post(route('lpm.supervisors.rotate_legacy_credential', $lecturer), $this->selection())->assertNotFound();
        $this->assertTrue(Hash::check(self::FIXTURE_PASSWORD, $this->supervisorUser->fresh()->password));
    }

    public function test_lost_credential_requires_a_fresh_rotation_and_generates_a_unique_password(): void
    {
        $this->post($this->rotationUrl(), $this->selection())->assertSessionHasNoErrors();
        $first = json_decode(Crypt::decryptString(session('supervisor_rotated_credential')), true)['password'];
        $this->post($this->rotationUrl(), $this->selection())->assertSessionHasNoErrors();
        $second = json_decode(Crypt::decryptString(session('supervisor_rotated_credential')), true)['password'];
        $this->assertNotSame($first, $second);
        $this->assertFalse(Hash::check($first, $this->supervisorUser->fresh()->password));
        $this->assertTrue(Hash::check($second, $this->supervisorUser->fresh()->password));
        $this->assertSame(2, SystemAudit::where('event', 'LEGACY_CREDENTIAL_ROTATED')->count());
    }

    public function test_new_account_onboarding_uses_encrypted_one_time_flash_and_hash_only(): void
    {
        $lecturer = Lecturer::create(['name' => 'New Rotation Test Supervisor', 'external_code' => 'F02-'.Str::random(10), 'is_active' => true]);
        $this->post(route('lpm.supervisors.store'), ['lecturer_id' => $lecturer->id, 'supervision_period_id' => $this->period->id])
            ->assertSessionHasNoErrors();
        $encrypted = session('supervisor_initial_password');
        $temporary = Crypt::decryptString($encrypted);
        $user = $lecturer->fresh()->user;
        $this->assertSame(24, strlen($temporary));
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check($temporary, $user->password));
        foreach (DB::table('sessions')->pluck('payload') as $stored) {
            $this->assertStringNotContainsString($temporary, base64_decode($stored));
        }
        $this->get($this->directoryUrl())->assertSee(e($temporary), false);
        $this->get($this->directoryUrl())->assertDontSee(e($temporary), false);
    }

    public function test_audit_failure_rolls_back_credentials_and_session_revocation(): void
    {
        $supervisorSession = $this->loginBrowser($this->supervisorUser);
        $this->loginBrowser($this->admin);
        $payload = $this->selection();
        $hash = $this->supervisorUser->fresh()->password;
        $token = $this->supervisorUser->fresh()->getRememberToken();
        $original = Model::getEventDispatcher();
        $dispatcher = clone $original;
        $dispatcher->listen('eloquent.creating: '.SystemAudit::class, function (SystemAudit $audit): void {
            if ($audit->event === 'LEGACY_CREDENTIAL_ROTATED') {
                throw new RuntimeException('Synthetic audit failure');
            }
        });
        Model::setEventDispatcher($dispatcher);
        try {
            $response = $this->post($this->rotationUrl(), $payload);
            $response->assertRedirect($this->directoryUrl())->assertSessionHas('error')->assertSessionHas('error_reference');
            $this->assertStringNotContainsString('Synthetic audit failure', session('error'));
        } finally {
            Model::setEventDispatcher($original);
        }
        $this->assertSame($hash, $this->supervisorUser->fresh()->password);
        $this->assertSame($token, $this->supervisorUser->fresh()->getRememberToken());
        $this->assertDatabaseHas('sessions', ['id' => $supervisorSession]);
        $this->assertNull(session('supervisor_rotated_credential'));
    }

    public function test_rotation_does_not_reactivate_revoked_or_inactive_accounts(): void
    {
        $payload = $this->selection();
        $this->supervisorUser->update(['is_active' => false]);
        PeriodSupervisor::where('lecturer_id', $this->supervisor->id)->update(['is_active' => false]);
        $this->post($this->rotationUrl(), $payload)->assertSessionHasNoErrors();
        $this->assertFalse($this->supervisorUser->fresh()->is_active);
        $this->assertDatabaseHas('period_supervisors', ['lecturer_id' => $this->supervisor->id, 'is_active' => false]);
    }

    /** @return array{period_id: int, confirm_email: string, confirm_legacy: string, credential_state: string} */
    private function selection(): array
    {
        $response = $this->get($this->directoryUrl())->assertOk();
        $states = $response->viewData('rotationSelections');

        return ['period_id' => $this->period->id, 'confirm_email' => $this->supervisorUser->email,
            'confirm_legacy' => '1', 'credential_state' => $states[$this->supervisor->id]];
    }

    private function directoryUrl(): string
    {
        return route('lpm.supervisors.index', ['period_id' => $this->period->id]);
    }

    private function rotationUrl(): string
    {
        return route('lpm.supervisors.rotate_legacy_credential', $this->supervisor);
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
        $this->post('/login', ['email' => $user->email, 'password' => self::FIXTURE_PASSWORD, 'remember' => '1'])
            ->assertSessionHasNoErrors();
        $id = session()->getId();
        $this->beginBrowser($id);
        $this->get(route('password.change'))->assertOk();
        $this->assertAuthenticatedAs($user);

        return session()->getId();
    }
}
