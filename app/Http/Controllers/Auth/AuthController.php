<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SystemAudit;
use App\Models\User;
use App\Services\CredentialSessionRevoker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    /**
     * Show login form.
     */
    public function showLogin(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            $user = $request->user();
            abort_unless($user->is_active, 403);
            if ($user->isLpmStaff()) {
                return redirect()->route('lpm.dashboard');
            }
            if ($user->isOrganizationalStaff()) {
                return redirect()->route('unit.dashboard');
            }
            if ($user->hasRole('supervisor')) {
                return redirect()->route('supervisor.dashboard');
            }
            abort(403, 'Akun Anda belum memiliki peran yang dapat mengakses aplikasi.');
        }

        return view('auth.login');
    }

    /**
     * Process login request with rate limiting and audit logging.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::transliterate(Str::lower($request->input('email')).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $this->recordLoginFailure($request, 'LOGIN_LOCKOUT');

            return back()->withErrors([
                'email' => "Terlalu banyak percobaan masuk. Silakan coba lagi dalam {$seconds} detik.",
            ])->onlyInput('email');
        }

        $remember = $request->boolean('remember');

        if (Auth::attempt($credentials, $remember)) {
            $request->session()->regenerate();
            RateLimiter::clear($throttleKey);

            $user = Auth::user();

            if (! $user->is_active) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $this->recordLoginFailure($request, 'LOGIN_DENIED_INACTIVE');

                return back()->withErrors([
                    'email' => 'Akun Anda berstatus non-aktif. Silakan hubungi Lembaga Penjaminan Mutu (LPM).',
                ]);
            }

            $user->update([
                'last_login_at' => now(),
            ]);

            SystemAudit::create([
                'user_id' => $user->id,
                'event' => 'LOGIN',
                'entity_type' => get_class($user),
                'entity_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'new_values' => ['must_change_password' => (bool) $user->must_change_password],
            ]);

            if ($user->must_change_password) {
                return redirect()->route('password.change')
                    ->with('warning', 'Selamat datang! Demi keamanan akun Anda, silakan ubah kata sandi awal terlebih dahulu.');
            }

            return $this->redirectBasedOnRole($user)
                ->with('success', 'Selamat datang kembali, '.$user->name.'.');
        }

        RateLimiter::hit($throttleKey, 300);
        $this->recordLoginFailure($request, 'LOGIN_FAILED');

        return back()->withErrors([
            'email' => 'Kombinasi email dan kata sandi yang Anda masukkan tidak sesuai.',
        ])->onlyInput('email');
    }

    /**
     * Show password change form.
     */
    public function showChangePassword(): View
    {
        return view('auth.change-password');
    }

    /**
     * Handle password change.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'current_password.current_password' => 'Kata sandi saat ini tidak cocok.',
            'password.min' => 'Kata sandi baru minimal harus 8 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi baru tidak cocok.',
        ]);

        $user = $user->getConnection()->transaction(function () use ($user, $validated, $request): User {
            $lockedUser = User::on($user->getConnectionName())->lockForUpdate()->findOrFail($user->id);
            if (! Hash::check($validated['current_password'], $lockedUser->password)) {
                throw ValidationException::withMessages(['current_password' => 'Kata sandi saat ini tidak cocok.']);
            }

            if (Hash::check($validated['password'], $lockedUser->password)) {
                throw ValidationException::withMessages(['password' => 'Kata sandi baru harus berbeda dari kata sandi saat ini.']);
            }

            $lockedUser->update([
                'password' => Hash::make($validated['password']),
                'must_change_password' => false,
            ]);
            app(CredentialSessionRevoker::class)->revoke($lockedUser, $request->session()->getId());

            SystemAudit::create([
                'user_id' => $lockedUser->id,
                'event' => 'CHANGE_PASSWORD',
                'entity_type' => get_class($lockedUser),
                'entity_id' => $lockedUser->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'new_values' => ['must_change_password' => false],
            ]);

            return $lockedUser;
        });

        Auth::setUser($user);
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();

        return $this->redirectBasedOnRole($user)
            ->with('success', 'Kata sandi Anda berhasil diperbarui.');
    }

    /**
     * Handle user logout.
     */
    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if ($user) {
            SystemAudit::create([
                'user_id' => $user->id,
                'event' => 'LOGOUT',
                'entity_type' => get_class($user),
                'entity_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('info', 'Anda telah berhasil keluar dari sistem.');
    }

    private function recordLoginFailure(Request $request, string $event): void
    {
        $email = Str::lower((string) $request->input('email'));

        SystemAudit::create([
            'user_id' => null,
            'event' => $event,
            'entity_type' => User::class,
            'entity_id' => User::where('email', $email)->value('id'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'new_values' => ['email_fingerprint' => hash('sha256', $email)],
        ]);
    }

    /**
     * Redirect user based on their active role.
     */
    protected function redirectBasedOnRole(User $user): RedirectResponse
    {
        if ($user->isLpmStaff()) {
            $rolePrefix = rtrim(request()->getBasePath(), '/').'/lpm';
            $fallbackRoute = 'lpm.dashboard';
        } elseif ($user->isOrganizationalStaff()) {
            $rolePrefix = rtrim(request()->getBasePath(), '/').'/unit';
            $fallbackRoute = 'unit.dashboard';
        } elseif ($user->hasRole('supervisor')) {
            $rolePrefix = rtrim(request()->getBasePath(), '/').'/supervisor';
            $fallbackRoute = 'supervisor.dashboard';
        } else {
            session()->forget('url.intended');

            return redirect('/');
        }

        $intendedPath = parse_url((string) session()->get('url.intended'), PHP_URL_PATH);
        if (is_string($intendedPath)
            && ($intendedPath === $rolePrefix || Str::startsWith($intendedPath, $rolePrefix.'/'))) {
            return redirect()->intended(route($fallbackRoute));
        }

        session()->forget('url.intended');

        return redirect()->route($fallbackRoute);
    }
}
