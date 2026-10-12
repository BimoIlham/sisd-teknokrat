<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\Faculty;
use App\Models\GeneratedDocument;
use App\Models\Lecturer;
use App\Models\PeriodSupervisor;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Models\SupervisionStage;
use App\Models\SystemAudit;
use App\Models\User;
use App\Services\CredentialSessionRevoker;
use App\Services\Reporting\SupervisorAssignmentLetterService;
use App\Services\Scheduling\SchedulingLockService;
use App\Services\UnexpectedErrorReporter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SupervisorDirectoryController extends Controller
{
    private const string DEFAULT_INITIAL_PASSWORD = 'Teknokrat123@';

    /**
     * Display supervisors assigned to the selected period.
     */
    public function index(Request $request): Response
    {
        $defaultInitialPassword = self::DEFAULT_INITIAL_PASSWORD;
        $rotatedCredential = $request->session()->pull('supervisor_rotated_credential');
        try {
            $rotatedCredential = is_string($rotatedCredential) ? json_decode(Crypt::decryptString($rotatedCredential), true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (DecryptException|\JsonException $exception) {
            $rotatedCredential = null;
        }
        $search = $request->input('search');
        $facultyId = $request->input('faculty_id');
        $status = $request->input('status', 'all');

        $periods = SupervisionPeriod::with('academicTerm')
            ->orderByDesc('starts_on')
            ->get();

        $activePeriod = SupervisionPeriod::where('status', 'ACTIVE')
            ->latest('starts_on')
            ->first();
        $selectedPeriod = $request->filled('period_id')
            ? $periods->firstWhere('id', $request->integer('period_id'))
            : ($activePeriod ?? $periods->first());
        abort_if($request->filled('period_id') && ! $selectedPeriod, 404);
        $periodId = $selectedPeriod?->id;

        $faculties = Faculty::query()
            ->where('is_active', true)
            ->with(['studyPrograms' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('name')
            ->get();

        $query = Lecturer::query()
            ->whereHas('periodSupervisors', function ($query) use ($periodId): void {
                $query->where('supervision_period_id', $periodId)->where('is_active', true);
            })
            ->with([
                'faculty',
                'studyProgram',
                'user.roles',
                'periodSupervisors' => fn ($query) => $query
                    ->where('supervision_period_id', $periodId)
                    ->with('period.academicTerm'),
            ])
            ->withCount([
                'assignmentsAsSupervisor as total_assignments_count' => function ($q) use ($periodId): void {
                    $q->where('status', '<>', 'CANCELLED')
                        ->whereHas('supervisionStage', fn ($stageQuery) => $stageQuery->where('supervision_period_id', $periodId));
                },
                'assignmentsAsSupervisor as completed_assignments_count' => function ($q) use ($periodId): void {
                    $q->where('status', '<>', 'CANCELLED')
                        ->whereHas('supervisionStage', fn ($stageQuery) => $stageQuery->where('supervision_period_id', $periodId))
                        ->whereHas('supervision', fn ($supervisionQuery) => $supervisionQuery->where('status', 'SUBMITTED'));
                },
                'teachingSchedules as teaching_schedules_count' => function ($q) use ($selectedPeriod): void {
                    $q->where('is_active', true)
                        ->where('academic_term_id', $selectedPeriod?->academic_term_id);
                },
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('external_code', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($facultyId) {
            $query->where('faculty_id', $facultyId);
        }

        if ($status === 'active') {
            $query->where(function ($q) {
                $q->whereHas('user', function ($uq) {
                    $uq->where('is_active', true);
                })->orWhere('is_active', true);
            });
        } elseif ($status === 'inactive') {
            $query->where(function ($q) {
                $q->whereHas('user', function ($uq) {
                    $uq->where('is_active', false);
                })->where('is_active', false);
            });
        }

        $supervisors = $query->orderBy('name')->orderBy('id')->paginate(10)->withQueryString();
        $rotationSelections = [];
        foreach ($supervisors as $supervisor) {
            $user = $supervisor->user;
            if ($user && $user->hasRole('supervisor') && ! $user->hasRole('superadmin') && $user->id !== $request->user()->id) {
                $rotationSelections[$supervisor->id] = Crypt::encryptString(json_encode([
                    'user_id' => $user->id,
                    'actor_id' => $request->user()->id,
                    'period_id' => $periodId,
                    'password_hash' => $user->password,
                    'expires_at' => now()->addMinutes(15)->timestamp,
                ], JSON_THROW_ON_ERROR));
            }
        }
        $assignmentLetters = GeneratedDocument::where('document_type', SupervisorAssignmentLetterService::DOCUMENT_TYPE)
            ->where('supervision_period_id', $periodId)
            ->where('status', 'READY')
            ->whereIn('supervisor_lecturer_id', $supervisors->pluck('id'))
            ->whereHas('stage', fn ($query) => $query->where('status', 'FINALIZED'))
            ->with('stage')->orderBy('supervision_stage_id')->get()->groupBy('supervisor_lecturer_id');

        $totalSupervisors = PeriodSupervisor::where('supervision_period_id', $periodId)
            ->where('is_active', true)
            ->count();

        $periodAssignments = SupervisionAssignment::query()
            ->whereHas('supervisionStage', fn ($query) => $query->where('supervision_period_id', $periodId))
            ->where('status', '<>', 'CANCELLED');
        $totalAssignments = (clone $periodAssignments)->count();
        $totalCompletedAssignments = (clone $periodAssignments)
            ->whereHas('supervision', fn ($query) => $query->where('status', 'SUBMITTED'))
            ->count();
        $pendingAssignmentsCount = (clone $periodAssignments)
            ->whereDoesntHave('supervision', fn ($query) => $query->where('status', 'SUBMITTED'))
            ->count();

        return response()->view('lpm.supervisors.index', compact(
            'supervisors',
            'assignmentLetters',
            'periods',
            'activePeriod',
            'selectedPeriod',
            'faculties',
            'totalSupervisors',
            'pendingAssignmentsCount',
            'totalAssignments',
            'totalCompletedAssignments',
            'search',
            'facultyId',
            'periodId',
            'status',
            'defaultInitialPassword',
            'rotatedCredential',
            'rotationSelections'
        ))->header('Cache-Control', 'no-store, private');
    }

    public function candidates(SupervisionPeriod $period): JsonResponse
    {
        $usedEmails = User::pluck('email')
            ->mapWithKeys(fn (string $email): array => [Str::lower($email) => true])
            ->all();

        $lecturers = Lecturer::where('is_active', true)
            ->whereDoesntHave('periodSupervisors', fn ($query) => $query->where('supervision_period_id', $period->id)->where('is_active', true))
            ->with(['studyProgram:id,name', 'user:id,lecturer_id,email'])->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'external_code', 'study_program_id'])
            ->map(fn (Lecturer $lecturer): array => [
                'id' => $lecturer->id,
                'name' => $lecturer->name,
                'nidn' => $lecturer->nidn,
                'program' => $lecturer->studyProgram?->name,
                'email' => $lecturer->user?->email ?? $this->availableSupervisorEmail($lecturer, $usedEmails),
                'has_account' => $lecturer->user !== null,
            ]);

        return response()->json(['data' => $lecturers])->header('Cache-Control', 'no-store, private');
    }

    public function downloadAssignmentLetter(
        Lecturer $supervisor,
        SupervisionStage $stage,
        SupervisorAssignmentLetterService $letterService
    ): Response {
        abort_unless($stage->is_finalized, 404);
        $document = GeneratedDocument::where('document_type', SupervisorAssignmentLetterService::DOCUMENT_TYPE)
            ->where('supervision_period_id', $stage->supervision_period_id)
            ->where('supervision_stage_id', $stage->id)
            ->where('supervisor_lecturer_id', $supervisor->id)
            ->where('status', 'READY')->latest('id')->firstOrFail();
        $letter = $document->filter_snapshot ?? [];
        $letter['issued_on'] = now('Asia/Jakarta')->locale('id')->translatedFormat('j F Y');
        $name = Str::slug($letter['supervisor_name'] ?? $supervisor->name);

        return $letterService->render($letter)
            ->download('surat-tugas-'.$name.'-tahap-'.$stage->stage_no.'.pdf');
    }

    /**
     * Assign or register a new supervisor from the lecturer pool.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'lecturer_id' => 'required|exists:lecturers,id',
            'email' => 'nullable|email|max:255',
            'password' => 'prohibited',
            'supervision_period_id' => 'required|exists:supervision_periods,id',
        ]);

        $lecturer = Lecturer::findOrFail($validated['lecturer_id']);

        DB::transaction(function () use ($lecturer, $validated, $request): void {
            $locks = app(SchedulingLockService::class);
            $state = $locks->state();
            if (! empty($validated['supervision_period_id'])) {
                $locks->period((int) $validated['supervision_period_id']);
            }
            $lecturer = Lecturer::whereKey($lecturer->id)->lockForUpdate()->firstOrFail();
            $user = $lecturer->user;

            if (! $user) {
                $email = $validated['email'] ?? null;
                if (empty($email)) {
                    $usedEmails = User::pluck('email')
                        ->mapWithKeys(fn (string $email): array => [Str::lower($email) => true])
                        ->all();
                    $email = $this->availableSupervisorEmail($lecturer, $usedEmails);
                }

                Validator::make(['email' => $email], [
                    'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
                ], [
                    'email.unique' => 'Email ini sudah digunakan akun lain. Gunakan alamat email berbeda.',
                ])->validate();

                $user = User::create([
                    'lecturer_id' => $lecturer->id,
                    'name' => $lecturer->name,
                    'email' => $email,
                    'password' => Hash::make(self::DEFAULT_INITIAL_PASSWORD),
                    'is_active' => true,
                    'must_change_password' => true,
                ]);
            } elseif (! empty($validated['email']) && strcasecmp($validated['email'], $user->email) !== 0) {
                throw ValidationException::withMessages([
                    'email' => 'Dosen ini sudah memiliki akun login. Kosongkan email atau gunakan email akun yang sudah terhubung.',
                ]);
            }

            if (! $user->hasRole('supervisor')) {
                $user->assignRole('supervisor');
                $this->recordSupervisorChange($request, 'ROLE_GRANTED', $user, ['role' => 'supervisor']);
            }

            if (! empty($validated['supervision_period_id'])) {
                $membership = PeriodSupervisor::updateOrCreate(
                    [
                        'supervision_period_id' => $validated['supervision_period_id'],
                        'lecturer_id' => $lecturer->id,
                    ],
                    [
                        'selected_by' => auth()->id(),
                        'is_active' => true,
                    ]
                );
                if ($membership->wasRecentlyCreated || $membership->wasChanged('is_active')) {
                    $locks->bump($state);
                    $this->recordSupervisorChange($request, 'SUPERVISOR_ACTIVATED', $membership, [
                        'lecturer_id' => $lecturer->id,
                        'period_id' => (int) $validated['supervision_period_id'],
                        'is_active' => true,
                    ]);
                }
            }
        }, 3);

        return redirect()
            ->route('lpm.supervisors.index', ['period_id' => $validated['supervision_period_id']])
            ->with('success', "Supervisor {$lecturer->name} berhasil ditambahkan dan diaktifkan.");
    }

    public function rotateLegacyCredential(Request $request, Lecturer $supervisor): RedirectResponse
    {
        $actor = $request->user()->fresh();
        abort_unless($actor->is_active && $actor->hasRole('superadmin'), 403);
        $validated = $request->validate([
            'period_id' => ['required', 'integer', 'exists:supervision_periods,id'],
            'confirm_email' => ['required', 'email'],
            'confirm_legacy' => ['required', 'accepted'],
            'credential_state' => ['required', 'string', 'max:4096'],
        ]);
        try {
            $selection = json_decode(Crypt::decryptString($validated['credential_state']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $exception) {
            throw ValidationException::withMessages(['credential_state' => 'Konfirmasi akun tidak valid. Muat ulang daftar supervisor.']);
        }

        $temporaryPassword = Str::password(24);
        try {
            $encryptedCredential = DB::transaction(function () use ($request, $supervisor, $validated, $selection, $temporaryPassword): string {
                $user = User::where('lecturer_id', $supervisor->id)->lockForUpdate()->firstOrFail();
                abort_unless($user->hasRole('supervisor') && ! $user->hasRole('superadmin') && $user->id !== $request->user()->id, 403);
                abort_unless(PeriodSupervisor::where('lecturer_id', $supervisor->id)
                    ->where('supervision_period_id', $validated['period_id'])->exists(), 404);
                if (! is_array($selection)
                    || ($selection['user_id'] ?? null) !== $user->id
                    || ($selection['actor_id'] ?? null) !== $request->user()->id
                    || ($selection['period_id'] ?? null) !== (int) $validated['period_id']
                    || ! is_int($selection['expires_at'] ?? null)
                    || $selection['expires_at'] < now()->timestamp
                    || ! is_string($selection['password_hash'] ?? null)
                    || ! hash_equals($user->password, $selection['password_hash'])) {
                    throw ValidationException::withMessages(['credential_state' => 'Akun telah berubah atau konfirmasi kedaluwarsa. Muat ulang dan periksa akun sebelum melakukan rotasi.']);
                }
                if (strcasecmp($user->email, $validated['confirm_email']) !== 0) {
                    throw ValidationException::withMessages(['confirm_email' => 'Email konfirmasi harus sama dengan akun yang dipilih.']);
                }
                $user->update(['password' => Hash::make($temporaryPassword), 'must_change_password' => true]);
                app(CredentialSessionRevoker::class)->revoke($user);
                $this->recordSupervisorChange($request, 'LEGACY_CREDENTIAL_ROTATED', $user, [
                    'source' => 'admin_selected_legacy',
                    'period_id' => (int) $validated['period_id'],
                    'must_change_password' => true,
                ]);

                return Crypt::encryptString(json_encode(['email' => $user->email, 'password' => $temporaryPassword], JSON_THROW_ON_ERROR));
            });
        } catch (ValidationException|ModelNotFoundException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $error = app(UnexpectedErrorReporter::class)->report($exception, 'supervisor.legacy_credential_rotation');

            return redirect()->route('lpm.supervisors.index', ['period_id' => $validated['period_id']])
                ->with('error', $error['message'])->with('error_reference', $error['reference']);
        }

        return redirect()->route('lpm.supervisors.index', ['period_id' => $validated['period_id']])
            ->with('success', 'Kredensial supervisor terpilih telah dirotasi. Sesi lama dicabut; pemilik akun wajib mengganti kata sandi sementara.')
            ->with('supervisor_rotated_credential', $encryptedCredential);
    }

    /**
     * Revoke the selected period assignment while preserving other periods.
     */
    public function destroy(Request $request, Lecturer $supervisor): RedirectResponse
    {
        $validated = $request->validate([
            'period_id' => 'required|exists:supervision_periods,id',
        ]);
        $periodSupervisor = PeriodSupervisor::where('lecturer_id', $supervisor->id)
            ->where('supervision_period_id', $validated['period_id'])
            ->firstOrFail();
        DB::transaction(function () use ($periodSupervisor, $request, $supervisor): void {
            $locks = app(SchedulingLockService::class);
            $state = $locks->state();
            $locks->period((int) $periodSupervisor->supervision_period_id);
            $periodSupervisor = PeriodSupervisor::query()->lockForUpdate()->findOrFail($periodSupervisor->id);
            $wasActive = (bool) $periodSupervisor->is_active;
            $periodSupervisor->update(['is_active' => false]);
            if ($wasActive) {
                $locks->bump($state);
                $this->recordSupervisorChange($request, 'SUPERVISOR_REVOKED', $periodSupervisor, [
                    'lecturer_id' => $supervisor->id,
                    'period_id' => $periodSupervisor->supervision_period_id,
                    'is_active' => false,
                ], ['is_active' => true]);
            }
        }, 3);

        return redirect()
            ->route('lpm.supervisors.index', ['period_id' => $validated['period_id']])
            ->with('success', "Supervisor {$supervisor->name} dihapus dari periode terpilih.");
    }

    /**
     * @param  array<string, true>  $usedEmails
     */
    private function availableSupervisorEmail(Lecturer $lecturer, array $usedEmails): string
    {
        $accountName = trim(Str::before($lecturer->name, ','));
        $accountName = preg_replace('/^(?:(?:prof|drs|dra|dr(?:\.\s*sc)?|ir|hj|h)(?:\.\s*|\s+))+/iu', '', $accountName) ?? $accountName;
        $emailName = Str::slug($accountName, '.');
        $emailName = rtrim(substr($emailName !== '' ? $emailName : 'supervisor', 0, 48), '.');
        $email = $emailName.'@teknokrat.ac.id';
        $suffix = 2;

        while (isset($usedEmails[Str::lower($email)])) {
            $email = $emailName.$suffix.'@teknokrat.ac.id';
            $suffix++;
        }

        return $email;
    }

    /**
     * @param  array<string, int|string|bool>  $newValues
     * @param  array<string, int|string|bool>|null  $oldValues
     */
    private function recordSupervisorChange(Request $request, string $event, Model $entity, array $newValues, ?array $oldValues = null): void
    {
        SystemAudit::create([
            'user_id' => $request->user()->id,
            'event' => $event,
            'entity_type' => $entity::class,
            'entity_id' => $entity->getKey(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
