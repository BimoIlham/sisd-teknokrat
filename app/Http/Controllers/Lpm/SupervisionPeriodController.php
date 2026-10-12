<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\FormTemplate;
use App\Models\PeriodSupervisor;
use App\Models\PeriodTarget;
use App\Models\SchedulingRule;
use App\Models\ScoringPolicy;
use App\Models\StageInstrument;
use App\Models\SupervisionPeriod;
use App\Models\SupervisionStage;
use App\Services\Scheduling\SchedulingLockService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SupervisionPeriodController extends Controller
{
    public function index(Request $request): View
    {
        $query = SupervisionPeriod::with(['academicTerm', 'stages'])
            ->withCount(['targets', 'supervisors', 'assignments']);

        // Search by period name
        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        // Filter by status
        if ($status = $request->query('status')) {
            if ($status === 'ACTIVE') {
                $query->where('status', 'ACTIVE');
            } elseif ($status === 'CLOSED') {
                $query->where('status', '<>', 'ACTIVE');
            }
        }

        $perPage = (int) $request->query('per_page', 10);
        if (! in_array($perPage, [5, 10, 25, 50])) {
            $perPage = 10;
        }

        $periods = $query->latest('starts_on')->paginate($perPage)->withQueryString();
        $currentAcademicYear = now()->month >= 7 ? now()->year : now()->year - 1;
        $defaultAcademicYear = $currentAcademicYear.'/'.($currentAcademicYear + 1);

        return view('lpm.periods.index', compact('periods', 'perPage', 'defaultAcademicYear'));
    }

    public function store(Request $request): RedirectResponse
    {
        if ($request->has('stage_no')) {
            return $this->storeStage($request);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'academic_year' => ['required', 'regex:/^\d{4}\/\d{4}$/'],
            'semester' => ['required', 'in:GANJIL,GENAP'],
            'stage_1_starts_on' => ['required', 'date'],
            'stage_1_ends_on' => ['required', 'date', 'after_or_equal:stage_1_starts_on'],
            'stage_2_starts_on' => ['required', 'date', 'after:stage_1_ends_on'],
            'stage_2_ends_on' => ['required', 'date', 'after_or_equal:stage_2_starts_on'],
            'status' => ['required', 'in:ACTIVE,CLOSED'],
        ], [
            'stage_1_starts_on.required' => 'Tanggal mulai Tahap 1 wajib diisi.',
            'stage_1_ends_on.required' => 'Tanggal selesai Tahap 1 wajib diisi.',
            'stage_2_starts_on.required' => 'Tanggal mulai Tahap 2 wajib diisi.',
            'stage_2_starts_on.after' => 'Tahap 2 harus dimulai setelah Tahap 1 selesai.',
            'stage_2_ends_on.required' => 'Tanggal selesai Tahap 2 wajib diisi.',
        ]);

        return DB::transaction(function () use ($validated): RedirectResponse {
            $locks = app(SchedulingLockService::class);
            $state = $locks->state();
            $term = $this->resolveSelectedSemester($validated['academic_year'], $validated['semester'], $validated['stage_1_starts_on'], $validated['stage_2_ends_on']);

            // Enforce business rule: only ONE period can be active at any given time
            if ($validated['status'] === 'ACTIVE') {
                SupervisionPeriod::where('status', 'ACTIVE')->update(['status' => 'CLOSED']);
            }

            $period = SupervisionPeriod::create([
                'academic_term_id' => $term->id,
                'name' => isset($validated['name']) ? trim($validated['name']) : $this->semesterName($validated['semester']),
                'starts_on' => $validated['stage_1_starts_on'],
                'ends_on' => $validated['stage_2_ends_on'],
                'status' => $validated['status'],
                'created_by' => auth()->id(),
            ]);

            // 1. Create and activate default SchedulingRule
            $adminId = auth()->id() ?? 1;
            $rule = SchedulingRule::firstOrCreate(
                ['supervision_period_id' => $period->id, 'version' => 1],
                [
                    'schema_version' => '1.0',
                    'config' => [
                        'schema_version' => '1.0',
                        'block_supervision_when_teaching_count_gte' => 3,
                        'buffer_minutes' => 0,
                        'max_supervisions_per_day' => null,
                        'max_supervisions_per_week' => null,
                        'max_daily_span_minutes' => null,
                        'cross_unit_policy' => 'ANY',
                        'stage_supervisor_policy' => 'ANY',
                        'min_stage_gap_days' => 0,
                        'one_observation_per_target_per_stage' => true,
                        'require_t2_after_t1' => true,
                    ],
                    'status' => 'APPROVED',
                    'is_demo' => false,
                    'created_by' => $adminId,
                    'approved_by' => $adminId,
                    'approved_at' => now(),
                    'approval_note' => 'Aturan jadwal default otomatis',
                ]
            );
            $period->update(['active_scheduling_rule_id' => $rule->id]);

            // Two supervision stages belong to the same academic semester.
            $stage1 = SupervisionStage::firstOrCreate([
                'supervision_period_id' => $period->id,
                'stage_no' => 1,
            ], [
                'name' => 'Tahap 1',
                'starts_on' => $validated['stage_1_starts_on'],
                'ends_on' => $validated['stage_1_ends_on'],
            ]);

            $stage2 = SupervisionStage::firstOrCreate([
                'supervision_period_id' => $period->id,
                'stage_no' => 2,
            ], [
                'name' => 'Tahap 2',
                'starts_on' => $validated['stage_2_starts_on'],
                'ends_on' => $validated['stage_2_ends_on'],
            ]);

            foreach ([$stage1, $stage2] as $stage) {
                $this->bindDefaultInstruments($stage);
            }
            $locks->bump($state);

            return redirect()->route('lpm.periods.index')
                ->with('success', "Periode '{$period->name}' berhasil ditambahkan.");
        }, 3);
    }

    public function show(SupervisionPeriod $period): View
    {
        $period->load(['academicTerm', 'stages.instrument.formTemplate', 'stages.instrument.scoringPolicy']);

        $targets = PeriodTarget::where('supervision_period_id', $period->id)
            ->with(['lecturer.studyProgram.faculty'])
            ->paginate(20, ['*'], 'targets_page');

        $supervisors = PeriodSupervisor::where('supervision_period_id', $period->id)
            ->with(['lecturer.studyProgram.faculty'])
            ->get();

        return view('lpm.periods.show', compact('period', 'targets', 'supervisors'));
    }

    public function update(Request $request, SupervisionPeriod $period): RedirectResponse
    {
        if ($request->has('stage_no')) {
            return $this->updateStage($request, $period);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'academic_year' => ['required', 'regex:/^\d{4}\/\d{4}$/'],
            'semester' => ['required', 'in:GANJIL,GENAP'],
            'stage_1_starts_on' => ['required', 'date'],
            'stage_1_ends_on' => ['required', 'date', 'after_or_equal:stage_1_starts_on'],
            'stage_2_starts_on' => ['required', 'date', 'after:stage_1_ends_on'],
            'stage_2_ends_on' => ['required', 'date', 'after_or_equal:stage_2_starts_on'],
            'status' => ['required', 'in:ACTIVE,CLOSED'],
        ], [
            'stage_2_starts_on.after' => 'Tahap 2 harus dimulai setelah Tahap 1 selesai.',
        ]);

        return DB::transaction(function () use ($validated, $period): RedirectResponse {
            $locks = app(SchedulingLockService::class);
            $state = $locks->state();
            $period = $locks->period($period->id);
            $term = $this->resolveSelectedSemester($validated['academic_year'], $validated['semester'], $validated['stage_1_starts_on'], $validated['stage_2_ends_on']);

            if ($validated['status'] === 'ACTIVE' && $period->status !== 'ACTIVE') {
                SupervisionPeriod::where('status', 'ACTIVE')->update(['status' => 'CLOSED']);
            }

            $period->update([
                'academic_term_id' => $term->id,
                'name' => isset($validated['name']) ? trim($validated['name']) : $this->semesterName($validated['semester']),
                'starts_on' => $validated['stage_1_starts_on'],
                'ends_on' => $validated['stage_2_ends_on'],
                'status' => $validated['status'],
            ]);

            foreach ([1, 2] as $stageNumber) {
                $stage = $period->stages()->updateOrCreate(['stage_no' => $stageNumber], [
                    'name' => 'Tahap '.$stageNumber,
                    'starts_on' => $validated['stage_'.$stageNumber.'_starts_on'],
                    'ends_on' => $validated['stage_'.$stageNumber.'_ends_on'],
                ]);
                $this->bindDefaultInstruments($stage);
            }
            $locks->bump($state);

            return redirect()->route('lpm.periods.index')
                ->with('success', "Periode '{$period->name}' berhasil diperbarui.");
        }, 3);
    }

    private function bindDefaultInstruments(SupervisionStage $stage): void
    {
        $offlineTemplate = FormTemplate::where('code', 'DEMO_OFFLINE')->where('status', 'PUBLISHED')->latest('version')->first()
            ?? FormTemplate::where('delivery_mode', 'OFFLINE')->where('status', 'PUBLISHED')->latest('version')->first();
        $onlineTemplate = FormTemplate::where('code', 'DEMO_ONLINE')->where('status', 'PUBLISHED')->latest('version')->first()
            ?? FormTemplate::where('delivery_mode', 'ONLINE')->where('status', 'PUBLISHED')->latest('version')->first();
        $policy = ScoringPolicy::where('code', 'DEMO_POLICY_MEAN')->first() ?? ScoringPolicy::first();

        foreach (['OFFLINE' => $offlineTemplate, 'ONLINE' => $onlineTemplate] as $mode => $template) {
            if ($template && $policy) {
                StageInstrument::firstOrCreate(
                    ['supervision_stage_id' => $stage->id, 'delivery_mode' => $mode],
                    ['form_template_id' => $template->id, 'scoring_policy_id' => $policy->id, 'bound_by' => auth()->id()]
                );
            }
        }
    }

    private function semesterName(string $semester): string
    {
        return 'Supervisi Semester '.ucfirst(strtolower($semester));
    }

    private function storeStage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'academic_year' => ['required', 'regex:/^\d{4}\/\d{4}$/'],
            'semester' => ['required', 'in:GANJIL,GENAP'],
            'stage_no' => ['required', 'integer', 'in:1,2'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'status' => ['required', 'in:ACTIVE,CLOSED'],
            'period_id' => ['nullable', 'integer', 'exists:supervision_periods,id'],
        ]);

        return DB::transaction(function () use ($validated): RedirectResponse {
            $locks = app(SchedulingLockService::class);
            $state = $locks->state();
            $period = isset($validated['period_id']) ? $locks->period((int) $validated['period_id']) : null;
            if ($period && ($period->academicTerm?->academic_year !== $validated['academic_year'] || $period->academicTerm?->semester !== $validated['semester'])) {
                throw ValidationException::withMessages(['academic_year' => 'Tahun ajaran dan semester tahap harus sama dengan periode supervisi.']);
            }

            $term = $this->resolveSelectedSemester($validated['academic_year'], $validated['semester'], $validated['starts_on'], $validated['ends_on']);
            if (! $period) {
                $duplicateStage = SupervisionPeriod::where('academic_term_id', $term->id)
                    ->where('name', trim($validated['name']))
                    ->whereHas('stages', fn ($query) => $query->where('stage_no', $validated['stage_no']))
                    ->exists();
                if ($duplicateStage) {
                    throw ValidationException::withMessages(['stage_no' => 'Tahap ini sudah ada pada periode supervisi.']);
                }

                $matchingPeriods = SupervisionPeriod::where('academic_term_id', $term->id)
                    ->whereHas('stages')
                    ->whereDoesntHave('stages', fn ($query) => $query->where('stage_no', $validated['stage_no']))
                    ->get();
                $period = $matchingPeriods->firstWhere('name', trim($validated['name']))
                    ?? ($matchingPeriods->count() === 1 ? $matchingPeriods->first() : null);
            }

            if ($period && $period->academic_term_id !== $term->id) {
                throw ValidationException::withMessages(['semester' => 'Semester tahap harus sama dengan periode supervisi.']);
            }

            if ($period?->stages()->where('stage_no', $validated['stage_no'])->exists()) {
                throw ValidationException::withMessages(['stage_no' => 'Tahap ini sudah ada pada periode supervisi.']);
            }

            $otherStage = $period?->stages()->first();
            if ($otherStage && (($validated['stage_no'] === 2 && $validated['starts_on'] <= $otherStage->ends_on->toDateString())
                || ($validated['stage_no'] === 1 && $validated['ends_on'] >= $otherStage->starts_on->toDateString()))) {
                throw ValidationException::withMessages(['starts_on' => 'Tahap 2 harus dimulai setelah Tahap 1 selesai.']);
            }

            if ($period) {
                $period = $locks->period($period->id);
            }
            if ($validated['status'] === 'ACTIVE') {
                SupervisionPeriod::where('status', 'ACTIVE')->when($period, fn ($query) => $query->where('id', '<>', $period->id))->update(['status' => 'CLOSED']);
            }

            if ($period) {
                $period->update([
                    'name' => trim($validated['name']),
                    'starts_on' => min($period->starts_on->toDateString(), $validated['starts_on']),
                    'ends_on' => max($period->ends_on->toDateString(), $validated['ends_on']),
                    'status' => $validated['status'],
                ]);
            } else {
                $period = SupervisionPeriod::create([
                    'academic_term_id' => $term->id,
                    'name' => trim($validated['name']),
                    'starts_on' => $validated['starts_on'],
                    'ends_on' => $validated['ends_on'],
                    'status' => $validated['status'],
                    'created_by' => auth()->id(),
                ]);
            }

            $term->update([
                'starts_on' => min($term->starts_on->toDateString(), $period->starts_on->toDateString()),
                'ends_on' => max($term->ends_on->toDateString(), $period->ends_on->toDateString()),
            ]);

            $stage = $period->stages()->create([
                'stage_no' => $validated['stage_no'],
                'name' => 'Tahap '.$validated['stage_no'],
                'starts_on' => $validated['starts_on'],
                'ends_on' => $validated['ends_on'],
            ]);

            $adminId = auth()->id();
            $rule = SchedulingRule::firstOrCreate(['supervision_period_id' => $period->id, 'version' => 1], [
                'schema_version' => '1.0',
                'config' => [
                    'schema_version' => '1.0',
                    'block_supervision_when_teaching_count_gte' => 3,
                    'buffer_minutes' => 0,
                    'max_supervisions_per_day' => null,
                    'max_supervisions_per_week' => null,
                    'max_daily_span_minutes' => null,
                    'cross_unit_policy' => 'ANY',
                    'stage_supervisor_policy' => 'ANY',
                    'min_stage_gap_days' => 0,
                    'one_observation_per_target_per_stage' => true,
                    'require_t2_after_t1' => true,
                ],
                'status' => 'APPROVED',
                'is_demo' => false,
                'created_by' => $adminId,
                'approved_by' => $adminId,
                'approved_at' => now(),
                'approval_note' => 'Aturan jadwal default otomatis',
            ]);
            $period->update(['active_scheduling_rule_id' => $rule->id]);

            $offTemplate = FormTemplate::where('code', 'DEMO_OFFLINE')->where('status', 'PUBLISHED')->latest('version')->first()
            ?? FormTemplate::where('delivery_mode', 'OFFLINE')->where('status', 'PUBLISHED')->latest('version')->first();
            $onTemplate = FormTemplate::where('code', 'DEMO_ONLINE')->where('status', 'PUBLISHED')->latest('version')->first()
            ?? FormTemplate::where('delivery_mode', 'ONLINE')->where('status', 'PUBLISHED')->latest('version')->first();
            $policy = ScoringPolicy::where('code', 'DEMO_POLICY_MEAN')->first() ?? ScoringPolicy::first();
            foreach (['OFFLINE' => $offTemplate, 'ONLINE' => $onTemplate] as $mode => $template) {
                if ($template && $policy) {
                    StageInstrument::firstOrCreate(
                        ['supervision_stage_id' => $stage->id, 'delivery_mode' => $mode],
                        ['form_template_id' => $template->id, 'scoring_policy_id' => $policy->id, 'bound_by' => $adminId]
                    );
                }
            }
            $locks->bump($state);

            return redirect()->route('lpm.periods.index')
                ->with('success', "{$period->name} Tahap {$validated['stage_no']} berhasil disimpan.");
        }, 3);
    }

    private function updateStage(Request $request, SupervisionPeriod $period): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'academic_year' => ['required', 'regex:/^\d{4}\/\d{4}$/'],
            'semester' => ['required', 'in:GANJIL,GENAP'],
            'stage_no' => ['required', 'integer', 'in:1,2'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'status' => ['required', 'in:ACTIVE,CLOSED'],
        ]);

        DB::transaction(function () use ($period, $validated): void {
            $locks = app(SchedulingLockService::class);
            $state = $locks->state();
            $period = $locks->period($period->id);
            if (! $period->stages()->where('stage_no', $validated['stage_no'])->exists()) {
                throw ValidationException::withMessages(['stage_no' => 'Tahap ini belum ada. Tambahkan tahap dari daftar periode terlebih dahulu.']);
            }

            $otherStage = $period->stages()->where('stage_no', '<>', $validated['stage_no'])->first();
            if ($otherStage && ($period->academicTerm?->academic_year !== $validated['academic_year'] || $period->academicTerm?->semester !== $validated['semester'])) {
                throw ValidationException::withMessages(['academic_year' => 'Kedua tahap harus berada dalam tahun ajaran dan semester yang sama.']);
            }

            $term = $this->resolveSelectedSemester($validated['academic_year'], $validated['semester'], $validated['starts_on'], $validated['ends_on']);
            if ($otherStage && $term->id !== $period->academic_term_id) {
                throw ValidationException::withMessages(['semester' => 'Kedua tahap harus berada dalam semester yang sama.']);
            }
            if ($otherStage && (($validated['stage_no'] === 2 && $validated['starts_on'] <= $otherStage->ends_on->toDateString())
                || ($validated['stage_no'] === 1 && $validated['ends_on'] >= $otherStage->starts_on->toDateString()))) {
                throw ValidationException::withMessages(['starts_on' => 'Tahap 2 harus dimulai setelah Tahap 1 selesai.']);
            }

            if ($validated['status'] === 'ACTIVE') {
                SupervisionPeriod::where('status', 'ACTIVE')->where('id', '<>', $period->id)->update(['status' => 'CLOSED']);
            }
            $period->stages()->where('stage_no', $validated['stage_no'])->update([
                'name' => 'Tahap '.$validated['stage_no'],
                'starts_on' => $validated['starts_on'],
                'ends_on' => $validated['ends_on'],
            ]);
            $dates = $period->stages()->get();
            $period->update([
                'academic_term_id' => $term->id,
                'name' => trim($validated['name']),
                'starts_on' => $dates->min('starts_on'),
                'ends_on' => $dates->max('ends_on'),
                'status' => $validated['status'],
            ]);
            $term->update([
                'starts_on' => min($term->starts_on->toDateString(), $period->starts_on->toDateString()),
                'ends_on' => max($term->ends_on->toDateString(), $period->ends_on->toDateString()),
            ]);
            $locks->bump($state);
        }, 3);

        return redirect()->route('lpm.periods.index')->with('success', trim($validated['name']).' berhasil diperbarui.');
    }

    private function resolveSelectedSemester(string $academicYear, string $semester, string $startsOn, string $endsOn): AcademicTerm
    {
        [$firstYear, $secondYear] = array_map('intval', explode('/', $academicYear));
        if ($secondYear !== $firstYear + 1) {
            throw ValidationException::withMessages(['academic_year' => 'Tahun ajaran harus berurutan, misalnya 2026/2027.']);
        }

        $startYear = Carbon::parse($startsOn)->year;
        $endYear = Carbon::parse($endsOn)->year;
        if ($startYear < $firstYear || $startYear > $secondYear || $endYear < $firstYear || $endYear > $secondYear) {
            throw ValidationException::withMessages(['starts_on' => 'Tanggal tahap harus berada dalam tahun ajaran yang dipilih.']);
        }

        return AcademicTerm::firstOrCreate([
            'academic_year' => $academicYear,
            'semester' => $semester,
        ], [
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);
    }

    public function toggleStatus(SupervisionPeriod $period): RedirectResponse
    {
        return DB::transaction(function () use ($period): RedirectResponse {
            $locks = app(SchedulingLockService::class);
            $state = $locks->state();
            $period = $locks->period($period->id);
            if ($period->status === 'ACTIVE') {
                $period->update(['status' => 'CLOSED']);
                $msg = "Periode '{$period->name}' berhasil ditutup.";
            } else {
                SupervisionPeriod::where('status', 'ACTIVE')->update(['status' => 'CLOSED']);
                $period->update(['status' => 'ACTIVE']);
                $msg = "Periode '{$period->name}' sekarang aktif sebagai periode utama.";
            }
            $locks->bump($state);

            return redirect()->route('lpm.periods.index')->with('success', $msg);
        }, 3);
    }

    public function destroy(SupervisionPeriod $period): RedirectResponse
    {
        return DB::transaction(function () use ($period): RedirectResponse {
            $locks = app(SchedulingLockService::class);
            $state = $locks->state();
            $period = $locks->period($period->id);
            if ($period->is_active) {
                return back()->with('error', 'Periode aktif tidak dapat dihapus. Silakan tutup atau aktifkan periode lain terlebih dahulu.');
            }

            $name = $period->name;
            $period->delete();
            $locks->bump($state);

            return redirect()->route('lpm.periods.index')
                ->with('success', "Periode '{$name}' berhasil dihapus.");
        }, 3);
    }
}
