<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\AssignmentAudit;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\GeneratedDocument;
use App\Models\Lecturer;
use App\Models\PeriodTarget;
use App\Models\ScheduleImportRow;
use App\Models\StudyProgram;
use App\Models\Supervision;
use App\Models\SupervisionAnswer;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Models\SystemAudit;
use App\Models\TeachingOccurrence;
use App\Models\TeachingSchedule;
use App\Services\Academic\OccurrenceGeneratorService;
use App\Services\Reporting\SupervisionResultPdfService;
use App\Services\Scheduling\SchedulingLockService;
use App\Services\Scheduling\WeeklySupervisionScheduleGrouper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LecturerDirectoryController extends Controller
{
    /**
     * Display the full list of lecturers with summary metrics.
     */
    public function index(Request $request): View
    {
        $periods = SupervisionPeriod::with('academicTerm')->latest('starts_on')->get();
        $selectedPeriod = $request->filled('period_id')
            ? $periods->firstWhere('id', $request->integer('period_id'))
            : ($periods->firstWhere('status', 'ACTIVE') ?? $periods->first());
        abort_if($request->filled('period_id') && ! $selectedPeriod, 404);
        $requestedCompletion = (string) $request->input('completion', 'all');
        $completionFilter = in_array($requestedCompletion, ['complete', 'incomplete'], true) ? $requestedCompletion : 'all';

        $query = Lecturer::query()->with(['faculty', 'studyProgram'])
            ->whereHas('periodTargets', function ($targetQuery) use ($selectedPeriod): void {
                $targetQuery->where('supervision_period_id', $selectedPeriod?->id)
                    ->where('scope_status', 'INCLUDED');
            });

        // Search filter
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('external_code', 'like', "%{$search}%");
            });
        }

        // Filter by faculty
        if ($facultyFilter = $request->input('faculty')) {
            $query->where('faculty_id', $facultyFilter);
        }

        $completeProfileExpression = 'external_code REGEXP ? AND name REGEXP ? AND faculty_id IS NOT NULL AND study_program_id IS NOT NULL';
        $completeProfileBindings = ['^[0-9]{10}$', '[^ \t\r\n\x{0000}\x{000B}]'];
        $profileCounts = (clone $query)->toBase()
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN {$completeProfileExpression} THEN 1 ELSE 0 END) as complete", $completeProfileBindings)
            ->first();
        $completenessCounts = [
            'complete' => (int) $profileCounts->complete,
            'incomplete' => (int) $profileCounts->total - (int) $profileCounts->complete,
        ];

        if ($completionFilter !== 'all') {
            $query->whereRaw("COALESCE(({$completeProfileExpression}), 0) = ?", [...$completeProfileBindings, $completionFilter === 'complete' ? 1 : 0]);
        }

        $lecturers = $query->orderBy('name')->paginate(10)->withQueryString();
        $lecturers->getCollection()->each(function (Lecturer $lecturer): void {
            $missingFields = collect([
                'Nama' => trim((string) $lecturer->name) === '',
                'NIDN' => preg_match('/^\d{10}$/', (string) $lecturer->nidn) !== 1,
                'Fakultas' => $lecturer->faculty_id === null,
                'Prodi' => $lecturer->study_program_id === null,
            ])->filter()->keys()->values();
            $lecturer->setAttribute('profile_complete', $missingFields->isEmpty());
            $lecturer->setAttribute('missing_profile_fields', $missingFields);
        });

        // Faculty & study programs for cascading dropdown
        $faculties = Faculty::where('is_active', true)->orderBy('code')->get();
        $studyProgramsByFaculty = StudyProgram::where('is_active', true)
            ->whereHas('faculty', fn ($query) => $query->where('is_active', true))
            ->orderBy('faculty_id')
            ->orderBy('name')
            ->get()
            ->groupBy('faculty_id');

        $selectedFaculty = $facultyFilter;

        return view('lpm.lecturers.index', compact(
            'lecturers',
            'periods',
            'selectedPeriod',
            'completionFilter',
            'faculties',
            'studyProgramsByFaculty',
            'selectedFaculty',
            'completenessCounts'
        ));
    }

    /**
     * Create a lecturer manually from the LPM directory.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'nidn' => ['nullable', 'string', 'regex:/^\d{10}$/', Rule::unique('lecturers', 'external_code')],
            'faculty_id' => ['required', 'integer', Rule::exists('faculties', 'id')->where('is_active', true)],
            'study_program_id' => [
                'nullable', 'integer',
                Rule::exists('study_programs', 'id')
                    ->where('faculty_id', $request->input('faculty_id'))
                    ->where('is_active', true),
            ],
            'period_id' => ['nullable', 'integer', 'exists:supervision_periods,id'],
        ], [
            'nidn.regex' => 'NIDN harus berisi tepat 10 angka.',
            'nidn.unique' => 'NIDN tersebut sudah digunakan dosen lain.',
            'study_program_id.exists' => 'Program studi harus berasal dari fakultas yang dipilih.',
        ]);

        $name = trim($validated['name']);
        $period = ! empty($validated['period_id'])
            ? SupervisionPeriod::findOrFail($validated['period_id'])
            : (SupervisionPeriod::where('status', 'ACTIVE')->latest('starts_on')->first()
                ?? SupervisionPeriod::latest('starts_on')->first());

        $lecturer = DB::transaction(function () use ($request, $validated, $name, $period): Lecturer {
            $locks = app(SchedulingLockService::class);
            $locks->state();
            if ($period) {
                $period = $locks->period($period->id);
            }
            $now = now()->toIso8601String();
            $lecturer = Lecturer::create([
                'name' => $name,
                'normalized_name' => mb_strtolower($name),
                'external_code' => $validated['nidn'] ?? null,
                'faculty_id' => $validated['faculty_id'],
                'study_program_id' => $validated['study_program_id'] ?? null,
                'identity_sources' => [
                    'name' => ['source' => 'manual', 'value' => $name, 'updated_by' => $request->user()->id, 'updated_at' => $now],
                    'nidn' => ['source' => 'manual', 'value' => $validated['nidn'] ?? null, 'updated_by' => $request->user()->id, 'updated_at' => $now],
                    'affiliation' => [
                        'source' => 'manual',
                        'faculty_id' => $validated['faculty_id'],
                        'study_program_id' => $validated['study_program_id'] ?? null,
                        'updated_by' => $request->user()->id,
                        'updated_at' => $now,
                    ],
                ],
                'is_active' => true,
            ]);

            if ($period) {
                PeriodTarget::firstOrCreate([
                    'supervision_period_id' => $period->id,
                    'lecturer_id' => $lecturer->id,
                ], [
                    'scope_status' => 'INCLUDED',
                    'selected_by' => $request->user()->id,
                ]);
            }

            $this->incrementSchedulingRevision();

            return $lecturer;
        });

        $lecturer->load(['faculty', 'studyProgram']);
        $redirectUrl = route('lpm.lecturers.index', array_filter([
            'period_id' => $period?->id,
            'search' => $lecturer->name,
        ]));

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'redirect_url' => $redirectUrl,
                'lecturer' => [
                    'id' => $lecturer->id,
                    'name' => $lecturer->name,
                    'nidn' => $lecturer->nidn,
                ],
            ], 201);
        }

        return redirect()->to($redirectUrl)->with('success', 'Dosen baru berhasil ditambahkan.');
    }

    public function destroy(Request $request, Lecturer $lecturer): RedirectResponse
    {
        $validated = $request->validate([
            'row_version' => ['required', 'integer', 'min:1'],
            'period_id' => ['nullable', 'integer', 'exists:supervision_periods,id'],
        ]);
        $redirect = route('lpm.lecturers.index', array_filter(['period_id' => $validated['period_id'] ?? null]));

        try {
            DB::transaction(function () use ($lecturer, $validated, $request): void {
                $locks = app(SchedulingLockService::class);
                $state = $locks->state();
                $lecturer = Lecturer::whereKey($lecturer->id)->lockForUpdate()->firstOrFail();
                if ($lecturer->row_version !== (int) $validated['row_version']) {
                    throw ValidationException::withMessages(['lecturer' => 'Data dosen sudah berubah. Muat ulang halaman sebelum menghapus.']);
                }
                if ($lecturer->user()->exists() || $lecturer->periodSupervisors()->exists()) {
                    throw ValidationException::withMessages(['lecturer' => 'Dosen memiliki akun atau riwayat penetapan supervisor sehingga tidak dapat dihapus dari direktori.']);
                }

                $hasProtectedAssignments = $lecturer->assignmentsAsTarget()
                    ->where(function ($q) {
                        $q->where('status', 'FINAL')
                            ->orWhereHas('supervision', fn ($sq) => $sq->where('status', 'SUBMITTED'))
                            ->orWhereHas('supervisionStage', fn ($sq) => $sq->where('status', 'FINALIZED'));
                    })->exists()
                    || $lecturer->assignmentsAsSupervisor()
                        ->where(function ($q) {
                            $q->where('status', 'FINAL')
                                ->orWhereHas('supervision', fn ($sq) => $sq->where('status', 'SUBMITTED'))
                                ->orWhereHas('supervisionStage', fn ($sq) => $sq->where('status', 'FINALIZED'));
                        })->exists()
                    || GeneratedDocument::where('supervisor_lecturer_id', $lecturer->id)->exists();

                if ($hasProtectedAssignments) {
                    throw ValidationException::withMessages(['lecturer' => 'Dosen memiliki penugasan atau riwayat supervisi sehingga tidak dapat dihapus.']);
                }

                $scheduleIds = $lecturer->teachingSchedules()->orderBy('id')->lockForUpdate()->pluck('id');
                $occurrenceIds = TeachingOccurrence::whereIn('teaching_schedule_id', $scheduleIds)->pluck('id');
                if ($occurrenceIds->isNotEmpty()) {
                    $hasProtectedOccurrenceAssignments = SupervisionAssignment::whereIn('teaching_occurrence_id', $occurrenceIds)
                        ->where(function ($q) {
                            $q->where('status', 'FINAL')
                                ->orWhereHas('supervision', fn ($sq) => $sq->where('status', 'SUBMITTED'))
                                ->orWhereHas('supervisionStage', fn ($sq) => $sq->where('status', 'FINALIZED'));
                        })->exists();

                    if ($hasProtectedOccurrenceAssignments) {
                        throw ValidationException::withMessages(['lecturer' => 'Jadwal dosen masih terkait penugasan supervisi sehingga tidak dapat dihapus.']);
                    }
                }

                // Bersihkan penugasan draf/batal yang belum pernah disahkan sebelum menghapus dosen
                $unfinalizedAssignmentIds = $lecturer->assignmentsAsTarget()->pluck('id')
                    ->merge($lecturer->assignmentsAsSupervisor()->pluck('id'))
                    ->merge($occurrenceIds->isNotEmpty() ? SupervisionAssignment::whereIn('teaching_occurrence_id', $occurrenceIds)->pluck('id') : collect())
                    ->unique();

                if ($unfinalizedAssignmentIds->isNotEmpty()) {
                    Supervision::whereIn('supervision_assignment_id', $unfinalizedAssignmentIds)->delete();
                    AssignmentAudit::whereIn('supervision_assignment_id', $unfinalizedAssignmentIds)->delete();
                    SupervisionAssignment::whereIn('id', $unfinalizedAssignmentIds)->delete();
                }

                ScheduleImportRow::where('matched_lecturer_id', $lecturer->id)->update([
                    'matched_lecturer_id' => null, 'committed_schedule_id' => null,
                    'validation_status' => 'SKIPPED', 'is_approved' => false,
                    'skip_reason' => 'Dosen dihapus manual dari Direktori Dosen.',
                    'row_version' => DB::raw('row_version + 1'),
                ]);
                TeachingOccurrence::whereIn('teaching_schedule_id', $scheduleIds)->delete();
                $lecturer->teachingSchedules()->delete();
                $lecturer->periodTargets()->delete();
                SystemAudit::create([
                    'user_id' => $request->user()->id, 'event' => 'LECTURER_DELETED',
                    'entity_type' => Lecturer::class, 'entity_id' => $lecturer->id,
                    'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
                    'old_values' => ['name' => $lecturer->name, 'schedule_count' => $scheduleIds->count()],
                ]);
                $lecturer->delete();
                $locks->bump($state);
            }, 3);
        } catch (ValidationException $exception) {
            return redirect()->to($redirect)->with('error', collect($exception->errors())->flatten()->first());
        }

        return redirect()->to($redirect)->with('success', 'Dosen dan jadwal mengajarnya berhasil dihapus dari direktori. Referensi identitas tetap tersimpan.');
    }

    /**
     * Show detailed profile of a single lecturer:
     * - Teaching schedules
     * - T1 & T2 results
     * - T1 vs T2 comparison (delta)
     * - Common findings from supervision
     */
    public function show(Request $request, Lecturer $lecturer, WeeklySupervisionScheduleGrouper $scheduleGrouper): View
    {
        $lecturer->load(['faculty', 'studyProgram']);
        $periods = SupervisionPeriod::with('academicTerm')->latest('starts_on')->get();
        $selectedPeriod = $request->filled('period_id')
            ? $periods->firstWhere('id', $request->integer('period_id'))
            : ($periods->firstWhere('status', 'ACTIVE') ?? $periods->first());
        abort_if($request->filled('period_id') && ! $selectedPeriod, 404);

        // ─── Teaching Schedules ──────────────────────────────────────
        $schedulePeriodIsActive = $selectedPeriod?->status === 'ACTIVE';
        $rawSchedules = $schedulePeriodIsActive
            ? TeachingSchedule::with(['course', 'faculty', 'studyProgram'])
                ->where('lecturer_id', $lecturer->id)
                ->where('academic_term_id', $selectedPeriod->academic_term_id)
                ->where('is_active', true)
                ->orderBy('day_of_week')
                ->orderBy('starts_local')
                ->get()
            : collect();

        $isStagingSchedule = false;
        if ($schedulePeriodIsActive && $rawSchedules->isEmpty()) {
            $stagingRows = ScheduleImportRow::where('matched_lecturer_id', $lecturer->id)
                ->where('validation_status', 'VALID')
                ->where('is_approved', true)
                ->when($selectedPeriod, fn ($query) => $query->whereHas('import', fn ($import) => $import->where('academic_term_id', $selectedPeriod->academic_term_id)))
                ->orderBy('row_no')
                ->get();

            if ($stagingRows->isNotEmpty()) {
                $isStagingSchedule = true;
                $rawSchedules = $stagingRows->map(function ($row) use ($lecturer) {
                    return (object) [
                        'id' => $row->id,
                        'day_of_week' => (int) $row->parsed_day_of_week,
                        'starts_local' => $row->parsed_start_time,
                        'ends_local' => $row->parsed_end_time,
                        'class_name' => $row->parsed_class_name,
                        'room_name' => $row->parsed_room,
                        'delivery_mode' => $row->parsed_delivery_mode,
                        'course' => (object) [
                            'name' => $row->parsed_course_name,
                            'code' => $row->parsed_course_code,
                        ],
                        'faculty' => $lecturer->faculty,
                        'studyProgram' => $lecturer->studyProgram,
                        'is_staging' => true,
                    ];
                });
            }
        }

        // Deduplicate schedules by day, start_time, class_name, and course_name
        $schedules = $rawSchedules->unique(function ($s) {
            $day = $s->day_of_week;
            $start = substr((string) $s->starts_local, 0, 5);
            $class = trim($s->class_name ?? '');
            $course = trim($s->course?->name ?? $s->course_name ?? '');

            return "{$day}_{$start}_{$class}_{$course}";
        })->values();

        // ─── Supervision assignments where this lecturer is supervisor ───────
        $rawSupervisorAssignments = SupervisionAssignment::with([
            'stage.period.academicTerm',
            'targetLecturer',
            'teachingOccurrence.teachingSchedule.course',
            'teachingOccurrence.teachingSchedule.studyProgram',
            'supervision',
        ])
            ->where('supervisor_lecturer_id', $lecturer->id)
            ->when($selectedPeriod, fn ($query) => $query->whereHas('stage', fn ($stageQuery) => $stageQuery->where('supervision_period_id', $selectedPeriod->id)))
            ->where('status', '<>', 'CANCELLED')
            ->orderBy('starts_at')
            ->get();

        $supervisionSchedules = collect();
        foreach ($rawSupervisorAssignments as $assign) {
            $occ = $assign->teachingOccurrence;
            $sched = $occ?->teachingSchedule;

            $dayOfWeek = (int) ($occ?->day_of_week
                ?? $sched?->day_of_week
                ?? ($occ?->occurs_on ? $occ->occurs_on->format('N') : null)
                ?? ($assign->starts_at ? $assign->starts_at->timezone('Asia/Jakarta')->format('N') : 1));

            $startTime = substr((string) ($occ?->start_time_local
                ?? $sched?->starts_local
                ?? ($assign->starts_at ? $assign->starts_at->timezone('Asia/Jakarta')->format('H:i') : '08:00')), 0, 5);

            $endTime = substr((string) ($occ?->end_time_local
                ?? $sched?->ends_local
                ?? ($assign->ends_at ? $assign->ends_at->timezone('Asia/Jakarta')->format('H:i') : '09:40')), 0, 5);

            $className = $sched?->class_name ?? 'Kelas -';
            $courseName = $sched?->course?->name ?? $sched?->course_name ?? '-';
            $room = $sched?->room_display ?? '-';
            $deliveryMode = strtoupper($sched?->delivery_mode ?? 'OFFLINE');
            $targetLecturerName = $assign->targetLecturer?->name ?? 'Dosen Tidak Diketahui';
            $stageName = $assign->stage?->name ?? $assign->supervisionStage?->name ?? 'Supervisi';

            $supervisionSchedules->push((object) [
                'id' => 'sup_'.$assign->id,
                'assignment_id' => $assign->id,
                'period_id' => $assign->stage?->supervision_period_id,
                'target_lecturer_id' => $assign->target_lecturer_id,
                'course_id' => $sched?->course_id,
                'stage_no' => $assign->stage?->stage_no,
                'is_supervision' => true,
                'day_of_week' => $dayOfWeek,
                'starts_local' => $startTime,
                'ends_local' => $endTime,
                'class_name' => $className,
                'course_name' => $courseName,
                'room_name' => $room,
                'room' => $room,
                'delivery_mode' => $deliveryMode,
                'target_lecturer_name' => $targetLecturerName,
                'stage_name' => $stageName,
                'occurrence_date' => $occ?->occurrence_date ?? ($assign->starts_at ? $assign->starts_at->format('Y-m-d') : null),
                'course' => (object) [
                    'name' => $courseName,
                ],
            ]);
        }

        $supervisionSchedules = $scheduleGrouper->group($supervisionSchedules);

        if (! $schedulePeriodIsActive) {
            $supervisionSchedules = collect();
        }

        $isSupervisor = $lecturer->user?->hasRole('supervisor')
            || $lecturer->periodSupervisors()->exists()
            || $supervisionSchedules->isNotEmpty();

        // Merged list of all schedule events for the detailed list view
        $allScheduleEvents = collect();
        foreach ($schedules as $s) {
            $allScheduleEvents->push((object) [
                'is_supervision' => false,
                'day_of_week' => (int) $s->day_of_week,
                'starts_local' => $s->starts_local,
                'ends_local' => $s->ends_local,
                'class_name' => $s->class_name,
                'course' => $s->course,
                'course_name' => $s->course?->name ?? $s->course_name ?? '-',
                'room_name' => $s->room_display ?? $s->room_name ?? '-',
                'room' => $s->room_display ?? $s->room_name ?? '-',
                'delivery_mode' => $s->delivery_mode ?? 'OFFLINE',
                'target_lecturer_name' => null,
                'stage_name' => null,
                'schedule' => $s instanceof TeachingSchedule ? $s : null,
                'schedule_source' => $s instanceof TeachingSchedule && $s->source_import_id ? 'Impor otomatis' : 'Manual',
            ]);
        }
        foreach ($supervisionSchedules as $sup) {
            $allScheduleEvents->push($sup);
        }
        $allScheduleEvents = $allScheduleEvents->sortBy([
            ['day_of_week', 'asc'],
            ['starts_local', 'asc'],
        ])->values();

        // ─── Construct Timetable Matrix Grid (FET Weekly Layout) ───────
        $days = [
            1 => 'SENIN',
            2 => 'SELASA',
            3 => 'RABU',
            4 => 'KAMIS',
            5 => 'JUMAT',
            6 => 'SABTU',
        ];

        $undergraduateSlots = collect(['07:00', '09:00', '11:00', '13:00', '15:00', '17:00', '19:00']);
        $scheduleStartTimes = $schedules->pluck('starts_local')
            ->concat($supervisionSchedules->pluck('starts_local'))
            ->map(fn ($t) => substr((string) $t, 0, 5))
            ->unique()
            ->filter()
            ->values();

        $graduateSlots = $scheduleStartTimes->reject(
            fn (string $time) => $undergraduateSlots->contains($time)
        );
        $timeSlots = $undergraduateSlots
            ->concat($graduateSlots)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $scheduleGrid = [];
        foreach ($timeSlots as $timeSlot) {
            $scheduleGrid[$timeSlot] = [];
            foreach ($days as $dNum => $dName) {
                $matchingTeachings = $schedules->filter(function ($s) use ($dNum, $timeSlot) {
                    $sStart = substr((string) $s->starts_local, 0, 5);

                    return (int) $s->day_of_week === $dNum && $sStart === $timeSlot;
                })->values();

                $matchingSupervisions = $supervisionSchedules->filter(function ($sup) use ($dNum, $timeSlot) {
                    $supStart = substr((string) $sup->starts_local, 0, 5);

                    return (int) $sup->day_of_week === $dNum && $supStart === $timeSlot;
                })->values();

                $scheduleGrid[$timeSlot][$dNum] = [
                    'teachings' => $matchingTeachings,
                    'supervisions' => $matchingSupervisions,
                    'teaching' => $matchingTeachings->first(),
                ];
            }
        }

        // ─── Supervision assignments as target ───────────────────────
        $assignments = SupervisionAssignment::with([
            'stage.period.academicTerm',
            'supervisorLecturer',
            'teachingOccurrence.teachingSchedule.course',
            'supervision',
        ])
            ->where('target_lecturer_id', $lecturer->id)
            ->when($selectedPeriod, fn ($query) => $query->whereHas('stage', fn ($stageQuery) => $stageQuery->where('supervision_period_id', $selectedPeriod->id)))
            ->where('status', '<>', 'CANCELLED')
            ->orderByDesc('starts_at')
            ->get();

        // Separate T1 and T2 results
        $t1Assignments = $assignments->filter(fn ($a) => $a->stage && $a->stage->stage_order === 1);
        $t2Assignments = $assignments->filter(fn ($a) => $a->stage && $a->stage->stage_order === 2);

        // ─── Compute T1/T2 comparison per period ─────────────────────
        $comparisons = [];
        $periodIds = $assignments->pluck('stage.period.id')->unique()->filter();

        foreach ($periodIds as $periodId) {
            $t1 = $t1Assignments->first(fn ($a) => $a->stage->period->id === $periodId);
            $t2 = $t2Assignments->first(fn ($a) => $a->stage->period->id === $periodId);

            $t1Score = $t1?->supervision?->final_score;
            $t2Score = $t2?->supervision?->final_score;
            $periodName = $t1?->stage->period->name ?? $t2?->stage->period->name ?? '-';
            $termName = $t1?->stage->period->academicTerm->name ?? $t2?->stage->period->academicTerm->name ?? '-';

            $delta = null;
            $direction = 'N/A';
            if ($t1Score !== null && $t2Score !== null) {
                $delta = round((float) $t2Score - (float) $t1Score, 2);
                if ($delta > 0) {
                    $direction = 'INCREASE';
                } elseif ($delta < 0) {
                    $direction = 'DECREASE';
                } else {
                    $direction = 'STABLE';
                }
            }

            $comparisons[] = [
                'period_name' => $periodName,
                'term_name' => $termName,
                't1_score' => $t1Score !== null ? number_format((float) $t1Score, 2) : null,
                't2_score' => $t2Score !== null ? number_format((float) $t2Score, 2) : null,
                't1_supervisor' => $t1?->supervisorLecturer?->name,
                't2_supervisor' => $t2?->supervisorLecturer?->name,
                't1_date' => $t1?->supervision?->supervision_date?->format('d/m/Y'),
                't2_date' => $t2?->supervision?->supervision_date?->format('d/m/Y'),
                't1_delivery' => $t1?->supervision?->delivery_mode,
                't2_delivery' => $t2?->supervision?->delivery_mode,
                'delta' => $delta,
                'direction' => $direction,
            ];
        }

        // ─── Aggregate scores for the lecturer ───────────────────────
        $allSubmittedScores = $assignments
            ->map(fn ($a) => $a->supervision)
            ->filter(fn ($s) => $s && $s->status === 'SUBMITTED' && $s->final_score !== null)
            ->pluck('final_score')
            ->map(fn ($s) => (float) $s);

        $avgScore = $allSubmittedScores->count() > 0
            ? round($allSubmittedScores->avg(), 2)
            : null;

        // ─── Common findings (from TEXTAREA answers in supervisions) ─
        $supervisionIds = $assignments
            ->map(fn ($a) => $a->supervision?->id)
            ->filter();

        $findings = [];
        if ($supervisionIds->count() > 0) {
            $findings = SupervisionAnswer::whereIn('supervision_id', $supervisionIds)
                ->whereHas('question', function ($q) {
                    $q->where('question_type', 'TEXTAREA')
                        ->where('is_scored', false);
                })
                ->with(['question', 'supervision.assignment.stage'])
                ->whereNotNull('text_value')
                ->where('text_value', '<>', '')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($a) => [
                    'question' => $a->question->question_text ?? 'Catatan',
                    'answer' => $a->text_value,
                    'stage' => $a->supervision?->assignment?->stage?->name ?? '-',
                    'date' => $a->supervision?->supervision_date?->format('d/m/Y') ?? '-',
                ]);
        }

        return view('lpm.lecturers.show', compact(
            'lecturer',
            'periods',
            'selectedPeriod',
            'schedules',
            'supervisionSchedules',
            'allScheduleEvents',
            'isSupervisor',
            'scheduleGrid',
            'timeSlots',
            'days',
            'isStagingSchedule',
            'schedulePeriodIsActive',
            'assignments',
            't1Assignments',
            't2Assignments',
            'comparisons',
            'avgScore',
            'findings'
        ));
    }

    /**
     * Update the study program (prodi) for a lecturer.
     */
    public function updateStudyProgram(Request $request, Lecturer $lecturer)
    {
        $validated = $request->validate([
            'study_program_id' => [
                'nullable', 'integer',
                Rule::exists('study_programs', 'id')->where('is_active', true),
            ],
        ]);

        $lecturer = DB::transaction(function () use ($validated, $request, $lecturer): Lecturer {
            $locks = app(SchedulingLockService::class);
            $state = $locks->state();
            $lecturer = Lecturer::whereKey($lecturer->id)->lockForUpdate()->firstOrFail();
            $studyProgramId = $validated['study_program_id'] ?: null;
            $studyProgram = $studyProgramId ? StudyProgram::findOrFail($studyProgramId) : null;
            $facultyId = $studyProgram?->faculty_id ?? $lecturer->faculty_id;
            $identitySources = $lecturer->identity_sources ?? [];
            $identitySources['affiliation'] = [
                'source' => 'manual', 'faculty_id' => $facultyId,
                'study_program_id' => $studyProgramId,
                'updated_by' => $request->user()->id, 'updated_at' => now()->toIso8601String(),
            ];

            // If a study program is selected, auto-set the faculty from the study program
            if ($studyProgramId) {
                $lecturer->update([
                    'faculty_id' => $facultyId,
                    'study_program_id' => $studyProgramId,
                    'identity_sources' => $identitySources,
                ]);
            } else {
                $lecturer->update([
                    'study_program_id' => null,
                    'identity_sources' => $identitySources,
                ]);
            }

            $locks->bump($state);

            return $lecturer;
        }, 3);

        if ($request->wantsJson()) {
            $lecturer->load(['faculty', 'studyProgram']);

            return response()->json([
                'success' => true,
                'faculty_name' => $lecturer->faculty?->name,
                'faculty_code' => $lecturer->faculty?->code,
                'study_program_name' => $lecturer->studyProgram?->name,
            ]);
        }

        return back()->with('success', 'Program studi berhasil diperbarui.');
    }

    public function createSchedule(Lecturer $lecturer, SupervisionPeriod $period): View
    {
        $this->ensureActiveSchedulePeriod($period);
        $period->loadMissing('academicTerm');
        $courses = Course::where('is_active', true)->orderBy('name')->get();

        return view('lpm.lecturers.schedule-form', [
            'lecturer' => $lecturer,
            'period' => $period,
            'courses' => $courses,
            'schedule' => null,
        ]);
    }

    public function storeSchedule(
        Request $request,
        Lecturer $lecturer,
        SupervisionPeriod $period,
        OccurrenceGeneratorService $occurrenceGenerator
    ): RedirectResponse {
        $this->ensureActiveSchedulePeriod($period);
        $period->loadMissing('academicTerm');
        $validated = $this->validateSchedule($request);

        DB::transaction(function () use ($lecturer, $period, $validated, $occurrenceGenerator): void {
            $locks = app(SchedulingLockService::class);
            $locks->state();
            $period = $locks->period($period->id);
            $this->ensureActiveSchedulePeriod($period);
            $course = $this->resolveCourse($validated['course_name']);
            $schedule = TeachingSchedule::create($this->scheduleAttributes($lecturer, $period, $course, $validated));
            $occurrenceGenerator->generateForSchedule($schedule);
            $this->incrementSchedulingRevision();
        });

        return redirect()->route('lpm.lecturers.show', ['lecturer' => $lecturer, 'period_id' => $period->id])
            ->with('success', 'Jadwal perkuliahan manual berhasil ditambahkan.');
    }

    public function editSchedule(
        Lecturer $lecturer,
        SupervisionPeriod $period,
        TeachingSchedule $schedule
    ): View {
        $this->ensureActiveSchedulePeriod($period);
        abort_unless(
            $schedule->lecturer_id === $lecturer->id
                && $schedule->academic_term_id === $period->academic_term_id
                && $schedule->is_active,
            404
        );

        $courses = Course::where('is_active', true)
            ->orWhereKey($schedule->course_id)
            ->orderBy('name')
            ->get();

        $schedule->loadMissing('course');

        return view('lpm.lecturers.schedule-form', [
            'lecturer' => $lecturer,
            'period' => $period->loadMissing('academicTerm'),
            'courses' => $courses,
            'schedule' => $schedule,
        ]);
    }

    public function updateSchedule(
        Request $request,
        Lecturer $lecturer,
        SupervisionPeriod $period,
        TeachingSchedule $schedule,
        OccurrenceGeneratorService $occurrenceGenerator
    ): RedirectResponse {
        $this->ensureActiveSchedulePeriod($period);
        abort_unless(
            $schedule->lecturer_id === $lecturer->id
                && $schedule->academic_term_id === $period->academic_term_id
                && $schedule->is_active,
            404
        );

        $period->loadMissing('academicTerm');
        $validated = $this->validateSchedule($request);

        DB::transaction(function () use ($lecturer, $period, $validated, $schedule, $occurrenceGenerator): void {
            $locks = app(SchedulingLockService::class);
            $locks->state();
            $period = $locks->period($period->id);
            $this->ensureActiveSchedulePeriod($period);
            $schedule = TeachingSchedule::query()->lockForUpdate()->findOrFail($schedule->id);
            abort_unless($schedule->is_active && $schedule->lecturer_id === $lecturer->id
                && $schedule->academic_term_id === $period->academic_term_id, 404);
            $sourceRow = $schedule->source_row_id
                ? ScheduleImportRow::whereKey($schedule->source_row_id)->lockForUpdate()->first()
                : null;
            $course = $this->resolveCourse($validated['course_name']);
            $attributes = $this->scheduleAttributes($lecturer, $period, $course, $validated, $schedule, $sourceRow);

            $schedule->update([
                'is_active' => false,
                'row_version' => $schedule->row_version + 1,
            ]);
            $schedule->occurrences()
                ->whereDoesntHave('assignments')
                ->where('status', 'SCHEDULED')
                ->update([
                    'status' => 'CANCELLED',
                    'exception_reason' => 'Jadwal diperbarui dari Direktori Dosen.',
                ]);

            $replacementSchedule = TeachingSchedule::create($attributes);

            if ($sourceRow) {
                $sourceRow->update([
                    'normalized_payload' => $replacementSchedule->source_payload,
                    'validation_status' => 'VALID',
                    'validation_issues' => [],
                    'is_approved' => true,
                    'skip_reason' => null,
                    'matched_course_id' => $course->id,
                    'committed_schedule_id' => $replacementSchedule->id,
                    'row_version' => $sourceRow->row_version + 1,
                ]);
            }

            $occurrenceGenerator->generateForSchedule($replacementSchedule);
            $this->incrementSchedulingRevision();
        });

        return redirect()->route('lpm.lecturers.show', ['lecturer' => $lecturer, 'period_id' => $period->id])
            ->with('success', 'Jadwal berhasil diperbarui. Versi sebelumnya tetap tersimpan untuk menjaga riwayat supervisi.');
    }

    public function destroySchedule(
        Lecturer $lecturer,
        SupervisionPeriod $period,
        TeachingSchedule $schedule
    ): RedirectResponse {
        $this->ensureActiveSchedulePeriod($period);
        abort_unless(
            $schedule->lecturer_id === $lecturer->id
                && $schedule->academic_term_id === $period->academic_term_id
                && $schedule->is_active,
            404
        );

        $hasAssignments = DB::transaction(function () use ($schedule, $period, $lecturer): bool {
            $locks = app(SchedulingLockService::class);
            $locks->state();
            $period = $locks->period($period->id);
            $this->ensureActiveSchedulePeriod($period);
            $lockedSchedule = TeachingSchedule::whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedSchedule->is_active && $lockedSchedule->lecturer_id === $lecturer->id
                && $lockedSchedule->academic_term_id === $period->academic_term_id, 404);
            $lockedSchedule->occurrences()->lockForUpdate()->get(['id']);
            $hasAssignments = $lockedSchedule->occurrences()->whereHas('assignments')->exists();

            if ($lockedSchedule->source_row_id) {
                $sourceRow = ScheduleImportRow::whereKey($lockedSchedule->source_row_id)->lockForUpdate()->first();
                if ($sourceRow) {
                    $sourceRow->update([
                        'validation_status' => 'SKIPPED',
                        'is_approved' => false,
                        'skip_reason' => 'Jadwal dihapus manual dari Direktori Dosen.',
                        'committed_schedule_id' => null,
                        'row_version' => $sourceRow->row_version + 1,
                    ]);
                }
            }

            if ($hasAssignments) {
                $lockedSchedule->update([
                    'is_active' => false,
                    'row_version' => $lockedSchedule->row_version + 1,
                ]);

                $lockedSchedule->occurrences()
                    ->whereDoesntHave('assignments')
                    ->where('status', 'SCHEDULED')
                    ->update([
                        'status' => 'CANCELLED',
                        'exception_reason' => 'Jadwal dihapus manual dari Direktori Dosen.',
                    ]);
            } else {
                $lockedSchedule->occurrences()->delete();
                $lockedSchedule->delete();
            }

            $this->incrementSchedulingRevision();

            return $hasAssignments;
        });

        $message = $hasAssignments
            ? 'Jadwal dinonaktifkan. Riwayat penugasan supervisi tetap tersimpan.'
            : 'Jadwal berhasil dihapus.';

        return redirect()->route('lpm.lecturers.show', ['lecturer' => $lecturer, 'period_id' => $period->id])
            ->with('success', $message);
    }

    /**
     * Update lecturer identity and affiliation from the LPM directory.
     */
    public function updateProfile(Request $request, Lecturer $lecturer): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'nidn' => ['nullable', 'string', 'regex:/^\d{10}$/', Rule::unique('lecturers', 'external_code')->ignore($lecturer->id)],
            'faculty_id' => ['required', 'integer', Rule::exists('faculties', 'id')->where('is_active', true)],
            'study_program_id' => [
                'nullable', 'integer',
                Rule::exists('study_programs', 'id')
                    ->where('faculty_id', $request->input('faculty_id'))
                    ->where('is_active', true),
            ],
        ], [
            'nidn.regex' => 'NIDN harus berisi tepat 10 angka.',
            'nidn.unique' => 'NIDN tersebut sudah digunakan dosen lain.',
            'study_program_id.exists' => 'Program studi harus berasal dari fakultas yang dipilih.',
        ]);

        $lecturer = DB::transaction(function () use ($validated, $request, $lecturer): Lecturer {
            $locks = app(SchedulingLockService::class);
            $state = $locks->state();
            $lecturer = Lecturer::whereKey($lecturer->id)->lockForUpdate()->firstOrFail();
            $identitySources = $lecturer->identity_sources ?? [];
            $identitySources['name'] = [
                'source' => 'manual', 'value' => trim($validated['name']),
                'updated_by' => $request->user()->id, 'updated_at' => now()->toIso8601String(),
            ];
            $identitySources['nidn'] = [
                'source' => 'manual', 'value' => $validated['nidn'] ?? null,
                'updated_by' => $request->user()->id, 'updated_at' => now()->toIso8601String(),
            ];
            $identitySources['affiliation'] = [
                'source' => 'manual',
                'faculty_id' => $validated['faculty_id'],
                'study_program_id' => $validated['study_program_id'] ?? null,
                'updated_by' => $request->user()->id, 'updated_at' => now()->toIso8601String(),
            ];

            $lecturer->update([
                'name' => trim($validated['name']),
                'normalized_name' => mb_strtolower(trim($validated['name'])),
                'external_code' => $validated['nidn'] ?? null,
                'faculty_id' => $validated['faculty_id'],
                'study_program_id' => $validated['study_program_id'] ?? null,
                'identity_sources' => $identitySources,
            ]);
            $locks->bump($state);

            return $lecturer;
        }, 3);
        $lecturer->load(['faculty', 'studyProgram']);

        return response()->json([
            'success' => true,
            'lecturer' => [
                'id' => $lecturer->id,
                'name' => $lecturer->name,
                'nidn' => $lecturer->nidn,
                'faculty_code' => $lecturer->faculty?->code,
                'faculty_name' => $lecturer->faculty?->name,
                'study_program_id' => $lecturer->study_program_id,
                'study_program_name' => $lecturer->studyProgram?->name,
            ],
        ]);
    }

    /**
     * Return study programs for a given faculty (JSON, for cascading dropdown).
     */
    public function studyProgramsByFaculty(Faculty $faculty)
    {
        abort_unless($faculty->is_active, 404);

        $programs = $faculty->studyPrograms()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return response()->json($programs);
    }

    /**
     * Validate the editable fields shared by manual and imported schedules.
     *
     * @return array{course_name: string, class_name: string, room_name: ?string, delivery_mode: string, day_of_week: int, starts_local: string, ends_local: string}
     */
    private function validateSchedule(Request $request): array
    {
        return $request->validate([
            'course_name' => ['required', 'string', 'max:255'],
            'class_name' => ['required', 'string', 'max:100'],
            'room_name' => ['nullable', 'string', 'max:100', 'required_if:delivery_mode,OFFLINE'],
            'delivery_mode' => ['required', Rule::in(['OFFLINE', 'ONLINE'])],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'starts_local' => ['required', 'date_format:H:i', 'before:ends_local'],
            'ends_local' => ['required', 'date_format:H:i', 'after:starts_local'],
        ], [
            'course_name.required' => 'Nama mata kuliah wajib diisi.',
            'course_name.max' => 'Nama mata kuliah maksimal 255 karakter.',
            'room_name.required_if' => 'Ruang wajib diisi untuk perkuliahan Offline.',
            'starts_local.before' => 'Jam mulai harus lebih awal daripada jam selesai.',
            'ends_local.after' => 'Jam selesai harus lebih akhir daripada jam mulai.',
        ]);
    }

    /**
     * Build a new schedule row, retaining PDF provenance when an imported row is edited.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function scheduleAttributes(
        Lecturer $lecturer,
        SupervisionPeriod $period,
        Course $course,
        array $validated,
        ?TeachingSchedule $previousSchedule = null,
        ?ScheduleImportRow $sourceRow = null
    ): array {
        $sourcePayload = $previousSchedule?->source_payload;
        if ($sourceRow) {
            $sourcePayload = array_merge($sourcePayload ?? [], [
                'lecturer_name' => $lecturer->name,
                'course_name' => $course->name,
                'course_code' => $course->code,
                'class_name' => $validated['class_name'],
                'day_of_week' => (int) $validated['day_of_week'],
                'start_time' => $validated['starts_local'],
                'end_time' => $validated['ends_local'],
                'room_name' => $validated['room_name'] ?? null,
                'delivery_mode' => $validated['delivery_mode'],
            ]);
        }

        return [
            'academic_term_id' => $period->academic_term_id,
            'lecturer_id' => $lecturer->id,
            'course_id' => $course->id,
            'faculty_id' => $lecturer->faculty_id,
            'study_program_id' => $lecturer->study_program_id,
            'class_name' => trim($validated['class_name']),
            'room_name' => isset($validated['room_name']) ? trim($validated['room_name']) : null,
            'delivery_mode' => $validated['delivery_mode'],
            'day_of_week' => (int) $validated['day_of_week'],
            'starts_local' => $validated['starts_local'],
            'ends_local' => $validated['ends_local'],
            'effective_from' => $period->academicTerm->starts_on->toDateString(),
            'effective_to' => $period->academicTerm->ends_on->toDateString(),
            'source_import_id' => $previousSchedule?->source_import_id,
            'source_row_id' => $sourceRow?->id ?? $previousSchedule?->source_row_id,
            'source_payload' => $sourcePayload,
            'is_active' => true,
            'row_version' => $previousSchedule ? $previousSchedule->row_version + 1 : 1,
        ];
    }

    private function resolveCourse(string $courseName): Course
    {
        $name = trim(preg_replace('/\s+/u', ' ', $courseName) ?? $courseName);
        $normalizedName = mb_strtolower($name);

        $course = Course::query()
            ->where('normalized_name', $normalizedName)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();

        if ($course) {
            if (! $course->is_active) {
                $course->update(['is_active' => true]);
            }

            return $course;
        }

        return Course::create([
            'name' => $name,
            'normalized_name' => $normalizedName,
            'is_active' => true,
        ]);
    }

    private function incrementSchedulingRevision(): void
    {
        $locks = app(SchedulingLockService::class);
        $locks->bump($locks->state());
    }

    private function ensureActiveSchedulePeriod(SupervisionPeriod $period): void
    {
        abort_unless($period->status === 'ACTIVE', 404, 'Jadwal hanya tersedia selama periode supervisi aktif.');
    }

    /**
     * Download official supervision report PDF for a specific stage.
     */
    public function downloadStagePdf(Request $request, Lecturer $lecturer, int $stageNo, SupervisionResultPdfService $pdfService): Response|RedirectResponse
    {
        abort_unless(in_array($stageNo, [1, 2], true), 404);
        $periods = SupervisionPeriod::latest('starts_on')->get();
        $selectedPeriod = $request->filled('period_id')
            ? $periods->firstWhere('id', $request->integer('period_id'))
            : ($periods->firstWhere('status', 'ACTIVE') ?? $periods->first());
        abort_if($request->filled('period_id') && ! $selectedPeriod, 404);
        $assignment = SupervisionAssignment::with(['supervision'])
            ->where('target_lecturer_id', $lecturer->id)
            ->where('status', '<>', 'CANCELLED')
            ->whereHas('stage', fn ($stage) => $stage->where('stage_no', $stageNo)->where('supervision_period_id', $selectedPeriod?->id))
            ->whereHas('supervision', fn ($s) => $s->where('status', 'SUBMITTED'))
            ->latest()
            ->first();

        if (! $assignment || ! $assignment->supervision) {
            return back()->with('error', "Belum ada dokumen hasil supervisi yang disubmit untuk Tahap {$stageNo} pada dosen {$lecturer->name}.");
        }

        Gate::authorize('downloadPdf', $assignment->supervision);
        $safeName = preg_replace('/[^A-Za-z0-9_]/', '_', $lecturer->name);
        $filename = "Laporan_Supervisi_{$safeName}_Tahap{$stageNo}.pdf";

        return $pdfService->generate($assignment->supervision)->download($filename);
    }

    /**
     * Download official supervision report PDF directly from supervision ID.
     */
    public function downloadSupervisionPdf(Supervision $supervision, SupervisionResultPdfService $pdfService): Response
    {
        Gate::authorize('downloadPdf', $supervision);
        abort_unless($supervision->status === 'SUBMITTED', 404, 'Laporan PDF hanya tersedia setelah supervisi disubmit.');

        $supervision->loadMissing(['assignment.targetLecturer', 'assignment.stage']);
        $lecturerName = $supervision->assignment?->targetLecturer?->name ?? 'Dosen';
        $stageNo = $supervision->assignment?->stage?->stage_no ?? 1;

        $safeName = preg_replace('/[^A-Za-z0-9_]/', '_', $lecturerName);
        $filename = "Laporan_Supervisi_{$safeName}_Tahap{$stageNo}.pdf";

        return $pdfService->generate($supervision)->download($filename);
    }
}
