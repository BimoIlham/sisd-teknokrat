<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CredentialSessionRevoker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SuperadminUserController extends Controller
{
    public function index(): View
    {
        $users = User::role('lpm_admin')->orderBy('name')->paginate(20);

        return view('lpm.superadmin.users', compact('users'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
        ]);
        $password = Str::password(24);
        DB::transaction(function () use ($data, $password): void {
            $user = User::create([...$data, 'password' => $password, 'is_active' => true, 'must_change_password' => true]);
            $user->assignRole('lpm_admin');
        });

        return back()->with('success', 'Akun LPM dibuat. Kata sandi sementara: '.$password);
    }

    public function update(Request $request, User $user, CredentialSessionRevoker $revoker): RedirectResponse
    {
        abort_unless($user->hasRole('lpm_admin') && ! $user->hasRole('superadmin'), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'is_active' => ['required', 'boolean'],
        ]);
        DB::transaction(function () use ($user, $data, $revoker): void {
            $user->update($data);
            if (! $user->is_active) {
                $revoker->revoke($user);
            }
        });

        return back()->with('success', 'Akun LPM diperbarui.');
    }

    public function resetPassword(User $user, CredentialSessionRevoker $revoker): RedirectResponse
    {
        abort_unless($user->hasRole('lpm_admin') && ! $user->hasRole('superadmin'), 404);
        $password = Str::password(24);
        DB::transaction(function () use ($user, $password, $revoker): void {
            $user->update(['password' => $password, 'must_change_password' => true]);
            $revoker->revoke($user);
        });

        return back()->with('success', 'Kata sandi sementara baru: '.$password);
    }
}
