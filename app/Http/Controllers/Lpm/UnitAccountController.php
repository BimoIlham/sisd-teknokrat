<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\Faculty;
use App\Models\StudyProgram;
use App\Models\SystemAudit;
use App\Models\User;
use App\Services\CredentialSessionRevoker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UnitAccountController extends Controller
{
    public function index(Request $request): View
    {
        $roleFilter = in_array($request->query('role'), ['fakultas', 'prodi'], true)
            ? $request->query('role') : 'all';
        $users = User::role($roleFilter === 'all' ? ['fakultas', 'prodi'] : $roleFilter)
            ->has('roles', '=', 1)->with(['roles', 'faculty', 'studyProgram.faculty'])
            ->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();
        $faculties = Faculty::where('is_active', true)->orderBy('name')->get();
        $studyPrograms = StudyProgram::where('is_active', true)
            ->whereHas('faculty', fn ($query) => $query->where('is_active', true))
            ->with('faculty:id,code,name')->orderBy('name')->get();
        [$facultyCounts, $programCounts] = $this->accountCounts();

        return view('lpm.unit-accounts.index', compact('users', 'faculties', 'studyPrograms', 'roleFilter', 'facultyCounts', 'programCounts'));
    }

    public function show(User $user): View
    {
        $this->ensureUnitAccount($user);
        $user->load(['roles', 'faculty', 'studyProgram.faculty']);
        $faculties = Faculty::where('is_active', true)->orderBy('name')->get();
        $studyPrograms = StudyProgram::where('is_active', true)
            ->whereHas('faculty', fn ($query) => $query->where('is_active', true))
            ->with('faculty:id,code,name')->orderBy('name')->get();
        [$facultyCounts, $programCounts] = $this->accountCounts();

        return view('lpm.unit-accounts.show', compact('user', 'faculties', 'studyPrograms', 'facultyCounts', 'programCounts'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedAccount($request);
        $password = Str::password(24);
        DB::transaction(function () use ($data, $password): void {
            $this->lockUnit($data);
            $this->ensureAvailable($data);
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'password' => $password,
                'faculty_id' => $data['faculty_id'], 'study_program_id' => $data['study_program_id'],
                'is_active' => true, 'must_change_password' => true,
            ]);
            $user->assignRole($data['role']);
        }, 3);

        return back()->withFragment('temporary-password')
            ->with('success', 'Akun unit berhasil dibuat.')
            ->with('temporary_password', ['email' => $data['email'], 'password' => $password]);
    }

    public function update(Request $request, User $user, CredentialSessionRevoker $revoker): RedirectResponse
    {
        $this->ensureUnitAccount($user);
        $data = $this->validatedAccount($request, $user);
        DB::transaction(function () use ($data, $user, $revoker): void {
            $this->lockUnit($data);
            $account = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->ensureUnitAccount($account);
            $this->ensureAvailable($data, $account);
            $scopeChanged = $account->email !== $data['email']
                || (int) $account->faculty_id !== (int) $data['faculty_id']
                || (int) $account->study_program_id !== (int) $data['study_program_id']
                || ! $account->hasRole($data['role'])
                || $account->is_active !== (bool) $data['is_active'];
            $account->update([
                'name' => $data['name'], 'email' => $data['email'],
                'faculty_id' => $data['faculty_id'], 'study_program_id' => $data['study_program_id'],
                'is_active' => $data['is_active'],
            ]);
            $account->syncRoles([$data['role']]);
            if ($scopeChanged) {
                $revoker->revoke($account);
            }
        }, 3);

        return redirect()->route('lpm.unit_accounts.show', $user)->with('success', 'Akun unit diperbarui.');
    }

    public function resetPassword(User $user, CredentialSessionRevoker $revoker): RedirectResponse
    {
        $this->ensureUnitAccount($user);
        $password = Str::password(24);
        DB::transaction(function () use ($user, $password, $revoker): void {
            $user->update(['password' => $password, 'must_change_password' => true]);
            $revoker->revoke($user);
        });

        return redirect()->route('lpm.unit_accounts.show', $user)->withFragment('temporary-password')
            ->with('success', 'Kata sandi sementara akun berhasil direset.')
            ->with('temporary_password', ['email' => $user->email, 'password' => $password]);
    }

    public function destroy(Request $request, User $user, CredentialSessionRevoker $revoker): RedirectResponse
    {
        DB::transaction(function () use ($request, $user, $revoker): void {
            $account = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->ensureUnitAccount($account);

            $revoker->revoke($account);
            DB::table('sessions')->where('user_id', $account->id)->delete();
            DB::table('password_reset_tokens')->where('email', $account->email)->delete();
            SystemAudit::create([
                'user_id' => $request->user()->id,
                'event' => 'UNIT_ACCOUNT_DELETED',
                'entity_type' => User::class,
                'entity_id' => $account->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'old_values' => [
                    'name' => $account->name,
                    'email' => $account->email,
                    'role' => $account->getRoleNames()->first(),
                    'faculty_id' => $account->faculty_id,
                    'study_program_id' => $account->study_program_id,
                ],
            ]);
            $account->delete();
        });

        return redirect()->route('lpm.unit_accounts.index')->with('success', 'Akun fakultas atau prodi berhasil dihapus.');
    }

    /** @return array{name: string, email: string, role: string, faculty_id: ?int, study_program_id: ?int, is_active: bool} */
    private function validatedAccount(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'role' => ['required', Rule::in(['fakultas', 'prodi'])],
            'faculty_id' => ['nullable', 'integer'],
            'study_program_id' => ['nullable', 'integer'],
            'is_active' => [$user ? 'required' : 'sometimes', 'boolean'],
        ]);

        $facultyId = null;
        $studyProgramId = null;
        if ($data['role'] === 'fakultas') {
            $faculty = Faculty::where('is_active', true)->find($data['faculty_id'] ?? null);
            if (! $faculty) {
                throw ValidationException::withMessages(['faculty_id' => 'Pilih fakultas yang aktif.']);
            }
            $facultyId = $faculty->id;
        } else {
            $studyProgram = StudyProgram::where('is_active', true)
                ->whereHas('faculty', fn ($query) => $query->where('is_active', true))
                ->find($data['study_program_id'] ?? null);
            if (! $studyProgram) {
                throw ValidationException::withMessages(['study_program_id' => 'Pilih program studi yang aktif.']);
            }
            $studyProgramId = $studyProgram->id;
            $facultyId = $studyProgram->faculty_id;
        }

        return [
            'name' => $data['name'], 'email' => $data['email'], 'role' => $data['role'],
            'faculty_id' => $facultyId, 'study_program_id' => $studyProgramId,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function ensureUnitAccount(User $user): void
    {
        abort_unless($user->isOrganizationalStaff() && $user->roles()->count() === 1, 404);
    }

    /** @return array{0: Collection, 1: Collection} */
    private function accountCounts(): array
    {
        $facultyCounts = User::role('fakultas')->has('roles', '=', 1)
            ->whereNotNull('faculty_id')->pluck('faculty_id')->countBy();
        $programCounts = User::role('prodi')->has('roles', '=', 1)
            ->whereNotNull('study_program_id')->pluck('study_program_id')->countBy();

        return [$facultyCounts, $programCounts];
    }

    /** @param array{role: string, faculty_id: ?int, study_program_id: ?int} $data */
    private function lockUnit(array $data): void
    {
        if ($data['role'] === 'fakultas') {
            $faculty = Faculty::whereKey($data['faculty_id'])->where('is_active', true)
                ->lockForUpdate()->first(['id']);
            if (! $faculty) {
                throw ValidationException::withMessages(['faculty_id' => 'Pilih fakultas yang aktif.']);
            }

            return;
        }

        $studyProgram = StudyProgram::whereKey($data['study_program_id'])->where('is_active', true)
            ->whereHas('faculty', fn ($query) => $query->where('is_active', true))
            ->lockForUpdate()->first(['id']);
        if (! $studyProgram) {
            throw ValidationException::withMessages(['study_program_id' => 'Pilih program studi yang aktif.']);
        }
    }

    /** @param array{role: string, faculty_id: ?int, study_program_id: ?int} $data */
    private function ensureAvailable(array $data, ?User $account = null): void
    {
        $role = $data['role'];
        $column = $role === 'fakultas' ? 'faculty_id' : 'study_program_id';
        $unitId = $data[$column];
        if ($account && $account->hasRole($role) && (int) $account->{$column} === (int) $unitId) {
            return;
        }

        $occupied = User::role($role)->has('roles', '=', 1)->where($column, $unitId)->exists();
        if ($occupied) {
            $message = $role === 'fakultas'
                ? 'Fakultas ini sudah memiliki akun. Buka detail akun yang ada atau hapus akun tersebut terlebih dahulu.'
                : 'Program studi ini sudah memiliki akun. Buka detail akun yang ada atau hapus akun tersebut terlebih dahulu.';
            throw ValidationException::withMessages([$column => $message]);
        }
    }
}
