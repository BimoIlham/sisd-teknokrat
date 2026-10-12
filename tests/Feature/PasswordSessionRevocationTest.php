<?php

namespace Tests\Feature;

use App\Models\SystemAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PasswordSessionRevocationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database', 'session.connection' => null, 'session.table' => 'sessions']);
        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
    }

    public function test_password_change_revokes_another_browser_preserves_current_browser_and_rotates_credentials(): void
    {
        $user = $this->createUser();
        $sessionA = $this->loginBrowser($user);
        $tokenA = session()->token();
        $sessionB = $this->loginBrowser($user);
        $stalePayloadB = DB::table('sessions')->where('id', $sessionB)->value('payload');
        $otherUser = $this->createUser();
        $otherSession = $this->loginBrowser($otherUser);
        $oldRememberToken = $user->fresh()->getRememberToken();
        $oldRecaller = $user->id.'|'.$oldRememberToken.'|'.Auth::guard()->hashPasswordForCookie($user->password);

        $this->beginBrowser($sessionA);
        $this->post(route('password.update'), $this->passwordChangePayload())
            ->assertRedirect(route('lpm.dashboard'))->assertSessionHasNoErrors();
        $newSessionA = session()->getId();
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionA, $newSessionA);
        $this->assertNotSame($tokenA, session()->token());
        $this->assertTrue(Hash::check('NewSecure456!', $user->fresh()->password));
        $this->assertFalse(Hash::check('OldSecure123!', $user->fresh()->password));
        $this->assertNotSame($oldRememberToken, $user->fresh()->getRememberToken());
        $this->assertDatabaseMissing('sessions', ['id' => $sessionB]);
        $this->assertDatabaseMissing('sessions', ['id' => $sessionA]);
        $this->assertDatabaseHas('sessions', ['id' => $newSessionA, 'user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => $otherSession, 'user_id' => $otherUser->id]);

        $this->beginBrowser($newSessionA);
        $this->get(route('password.change'))->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->beginBrowser($sessionB);
        $this->get(route('password.change'))->assertRedirect(route('login'));
        $this->assertGuest();

        DB::table('sessions')->updateOrInsert(['id' => $sessionB], [
            'user_id' => $user->id, 'payload' => $stalePayloadB, 'last_activity' => time(),
        ]);
        $this->beginBrowser($sessionB);
        $this->get(route('password.change'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->beginBrowser();
        $this->withCookie(Auth::guard()->getRecallerName(), $oldRecaller)
            ->get(route('password.change'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->beginBrowser();
        $this->post('/login', ['email' => $user->email, 'password' => 'OldSecure123!'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->beginBrowser();
        $this->post('/login', ['email' => $user->email, 'password' => 'NewSecure456!'])
            ->assertRedirect(route('lpm.dashboard'))->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_required_first_password_change_keeps_current_session_and_clears_the_flag(): void
    {
        $user = $this->createUser();
        $user->update(['must_change_password' => true]);
        $this->beginBrowser();
        $this->post('/login', ['email' => $user->email, 'password' => 'OldSecure123!'])
            ->assertRedirect(route('password.change'));
        $sessionId = session()->getId();
        $this->beginBrowser($sessionId);
        $this->post(route('password.update'), $this->passwordChangePayload())
            ->assertRedirect(route('lpm.dashboard'))->assertSessionHasNoErrors();
        $newSessionId = session()->getId();
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $this->beginBrowser($newSessionId);
        $this->get(route('password.change'))->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->beginBrowser($sessionId);
        $this->get(route('password.change'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_wrong_current_password_does_not_change_credentials_or_revoke_sessions(): void
    {
        $user = $this->createUser();
        $sessionA = $this->loginBrowser($user);
        $sessionB = $this->loginBrowser($user);
        $passwordHash = $user->fresh()->password;
        $rememberToken = $user->fresh()->getRememberToken();
        $this->beginBrowser($sessionA);
        $this->post(route('password.update'), [...$this->passwordChangePayload(), 'current_password' => 'WrongSecure123!'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame($passwordHash, $user->fresh()->password);
        $this->assertSame($rememberToken, $user->fresh()->getRememberToken());
        $this->assertSame($sessionA, session()->getId());
        $this->assertDatabaseHas('sessions', ['id' => $sessionB, 'user_id' => $user->id]);
    }

    public function test_database_failure_rolls_back_password_token_and_session_revocation(): void
    {
        $user = $this->createUser();
        $sessionA = $this->loginBrowser($user);
        $sessionB = $this->loginBrowser($user);
        $passwordHash = $user->fresh()->password;
        $rememberToken = $user->fresh()->getRememberToken();
        $original = Model::getEventDispatcher();
        $dispatcher = clone $original;
        $dispatcher->listen('eloquent.creating: '.SystemAudit::class, fn () => throw new RuntimeException('Simulasi kegagalan database.'));
        Model::setEventDispatcher($dispatcher);
        try {
            $this->beginBrowser($sessionA);
            $this->withoutExceptionHandling();
            try {
                $this->post(route('password.update'), $this->passwordChangePayload());
                $this->fail('Kegagalan database harus membatalkan perubahan.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Simulasi kegagalan database.', $exception->getMessage());
            }
        } finally {
            Model::setEventDispatcher($original);
        }
        $this->assertSame($passwordHash, $user->fresh()->password);
        $this->assertSame($rememberToken, $user->fresh()->getRememberToken());
        $this->assertDatabaseHas('sessions', ['id' => $sessionB, 'user_id' => $user->id]);
    }

    private function createUser(): User
    {
        $user = User::factory()->create(['password' => Hash::make('OldSecure123!'), 'is_active' => true, 'must_change_password' => false]);
        $user->assignRole('superadmin');

        return $user;
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
        $this->post('/login', ['email' => $user->email, 'password' => 'OldSecure123!', 'remember' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $sessionId = session()->getId();
        $this->beginBrowser($sessionId);
        $this->get(route('password.change'))->assertOk();
        $this->assertAuthenticatedAs($user);

        return session()->getId();
    }

    /** @return array{current_password: string, password: string, password_confirmation: string} */
    private function passwordChangePayload(): array
    {
        return [
            'current_password' => 'OldSecure123!', 'password' => 'NewSecure456!', 'password_confirmation' => 'NewSecure456!',
        ];

    }
}
