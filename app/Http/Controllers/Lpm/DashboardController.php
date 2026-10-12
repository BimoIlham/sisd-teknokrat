<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\Faculty;
use App\Models\Lecturer;
use App\Models\PeriodSupervisor;
use App\Models\PeriodTarget;
use App\Models\ScheduleImport;
use App\Models\StudyProgram;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Services\Analytics\SupervisionAnalyticsService;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        protected SupervisionAnalyticsService $analyticsService
    ) {}

    public function index(): View
    {
        $activePeriod = SupervisionPeriod::active()->with('stages')->first()
            ?? SupervisionPeriod::latest('starts_on')->with('stages')->first();

        $totalUnits = StudyProgram::where('is_active', true)
            ->whereHas('faculty', fn ($query) => $query->where('is_active', true))
            ->count();
        $totalFaculties = Faculty::where('is_active', true)->count();
        $totalLecturers = Lecturer::where('is_active', true)->count();
        $totalSupervisors = $activePeriod
            ? PeriodSupervisor::where('supervision_period_id', $activePeriod->id)->where('is_active', true)->count()
            : 0;

        $totalAssignments = $activePeriod
            ? SupervisionAssignment::whereHas('supervisionStage', fn ($q) => $q->where('supervision_period_id', $activePeriod->id))
                ->where('status', '<>', 'CANCELLED')
                ->count()
            : SupervisionAssignment::where('status', '<>', 'CANCELLED')->count();

        $periodSupervisions = Supervision::query()->whereHas('assignment', function ($query) use ($activePeriod) {
            $query->where('status', '<>', 'CANCELLED')
                ->when($activePeriod, fn ($assignmentQuery) => $assignmentQuery->whereHas('supervisionStage', fn ($stageQuery) => $stageQuery->where('supervision_period_id', $activePeriod->id)));
        });
        $pendingImports = ScheduleImport::where('status', 'PENDING_REVIEW')->count();

        // Recent Assignments with full relationship tree
        $recentAssignments = SupervisionAssignment::with([
            'targetLecturer.studyProgram.faculty',
            'supervisorLecturer',
            'supervision',
            'occurrence.schedule.course',
            'supervisionStage',
        ])
            ->where('status', '<>', 'CANCELLED')
            ->when($activePeriod, fn ($query) => $query->whereHas('supervisionStage', fn ($stageQuery) => $stageQuery->where('supervision_period_id', $activePeriod->id)))
            ->latest('updated_at')
            ->take(6)
            ->get();

        $scoreExpression = 'COALESCE(final_score, raw_score, 0)';
        $qualityMetrics = (clone $periodSupervisions)
            ->selectRaw("SUM(CASE WHEN status = 'SUBMITTED' THEN 1 ELSE 0 END) as total_submitted")
            ->selectRaw("SUM(CASE WHEN status = 'DRAFT' THEN 1 ELSE 0 END) as total_drafts")
            ->selectRaw("COALESCE(AVG(CASE WHEN status = 'SUBMITTED' THEN {$scoreExpression} END), 0) as average_score")
            ->selectRaw("SUM(CASE WHEN status = 'SUBMITTED' AND {$scoreExpression} >= 3.50 THEN 1 ELSE 0 END) as sangat_lengkap")
            ->selectRaw("SUM(CASE WHEN status = 'SUBMITTED' AND {$scoreExpression} >= 3.00 AND {$scoreExpression} < 3.50 THEN 1 ELSE 0 END) as lengkap")
            ->selectRaw("SUM(CASE WHEN status = 'SUBMITTED' AND {$scoreExpression} >= 2.50 AND {$scoreExpression} < 3.00 THEN 1 ELSE 0 END) as cukup")
            ->selectRaw("SUM(CASE WHEN status = 'SUBMITTED' AND {$scoreExpression} >= 2.00 AND {$scoreExpression} < 2.50 THEN 1 ELSE 0 END) as kurang")
            ->selectRaw("SUM(CASE WHEN status = 'SUBMITTED' AND {$scoreExpression} < 2.00 THEN 1 ELSE 0 END) as kritis")
            ->first();

        $totalSubmitted = (int) $qualityMetrics->total_submitted;
        $completedCount = $totalSubmitted;
        $inProgressCount = (int) $qualityMetrics->total_drafts;
        $pendingCount = max(0, $totalAssignments - ($completedCount + $inProgressCount));
        $includedTargetsCount = $activePeriod
            ? PeriodTarget::where('supervision_period_id', $activePeriod->id)->where('scope_status', 'INCLUDED')->count()
            : 0;
        $stagesCount = $activePeriod?->stages->count() ?? 0;
        $totalRequired = $includedTargetsCount * $stagesCount;
        $progressData = [
            'included_targets_count' => $includedTargetsCount,
            'stages_count' => $stagesCount,
            'submitted_count' => $activePeriod ? $completedCount : 0,
            'total_required' => $totalRequired,
            'percentage' => $totalRequired > 0 ? round(($completedCount / $totalRequired) * 100, 1) : 0,
        ];
        $avgScore = round((float) $qualityMetrics->average_score, 2);
        $tierCounts = [
            'sangat_lengkap' => (int) $qualityMetrics->sangat_lengkap,
            'lengkap' => (int) $qualityMetrics->lengkap,
            'cukup' => (int) $qualityMetrics->cukup,
            'kurang' => (int) $qualityMetrics->kurang,
            'kritis' => (int) $qualityMetrics->kritis,
        ];
        $overallPercentage = $totalSubmitted > 0 ? min(100, round(($avgScore / 4.0) * 100)) : 0;

        $tierPercentages = [];
        foreach ($tierCounts as $key => $count) {
            $tierPercentages[$key] = $totalSubmitted > 0 ? round(($count / $totalSubmitted) * 100) : 0;
        }

        // Faculty breakdown progress
        $facultyPrograms = StudyProgram::select('id')->whereColumn('faculty_id', 'faculties.id')->where('is_active', true);
        $facultyLecturers = Lecturer::selectRaw('COUNT(*)')->where('is_active', true)
            ->where(fn ($query) => $query->whereColumn('lecturers.faculty_id', 'faculties.id')->orWhereIn('study_program_id', $facultyPrograms))
            ->when($activePeriod, fn ($query) => $query->whereIn('id', PeriodTarget::select('lecturer_id')->where('supervision_period_id', $activePeriod->id)->where('scope_status', 'INCLUDED')));
        $facultySubmissions = (clone $periodSupervisions)->selectRaw('COUNT(*)')->where('status', 'SUBMITTED')
            ->whereHas('assignment.targetLecturer', fn ($query) => $query
                ->where(fn ($query) => $query->whereColumn('lecturers.faculty_id', 'faculties.id')->orWhereIn('study_program_id', $facultyPrograms)));
        $faculties = Faculty::where('is_active', true)->select('faculties.*')
            ->selectSub($facultyLecturers, 'lecturers_count')->selectSub($facultySubmissions, 'submitted_count')->get()
            ->map(function (Faculty $faculty) use ($activePeriod): array {
                $totalInFaculty = (int) $faculty->lecturers_count;
                $completedInFaculty = (int) $faculty->submitted_count;
                $requiredSupervisions = $totalInFaculty * ($activePeriod?->stages->count() ?? 2);
                $percentage = $requiredSupervisions > 0 ? min(100, round(($completedInFaculty / $requiredSupervisions) * 100)) : 0;

                return [
                    'name' => $faculty->name,
                    'code' => $faculty->code,
                    'total' => $totalInFaculty,
                    'completed' => $completedInFaculty,
                    'percentage' => $percentage,
                ];
            });

        // Cohort & Stage 1 vs Stage 2 comparison metrics (PRD & Desain Section 12)
        $cohortAnalytics = $activePeriod
            ? $this->analyticsService->computePairedComparison($activePeriod)
            : ['has_two_stages' => false, 'comparable_pairs' => [], 'incomparable_pairs' => [], 'average_delta' => null, 'mean_t1' => null, 'mean_t2' => null];

        $stageMetrics = Supervision::query()
            ->join('supervision_assignments', 'supervision_assignments.id', '=', 'supervisions.supervision_assignment_id')
            ->join('supervision_stages', 'supervision_stages.id', '=', 'supervision_assignments.supervision_stage_id')
            ->where('supervisions.status', 'SUBMITTED')
            ->where('supervision_assignments.status', '<>', 'CANCELLED')
            ->when($activePeriod, fn ($query) => $query->where('supervision_stages.supervision_period_id', $activePeriod->id))
            ->whereIn('supervision_stages.stage_no', [1, 2])
            ->groupBy('supervision_stages.stage_no')
            ->get([
                'supervision_stages.stage_no',
                DB::raw('COUNT(*) as total'),
                DB::raw('AVG(supervisions.final_score) as average_score'),
            ])
            ->keyBy('stage_no');

        $t1Metrics = $stageMetrics->get(1);
        $t2Metrics = $stageMetrics->get(2);
        $t1Mean = $t1Metrics ? round((float) $t1Metrics->average_score, 2) : null;
        $t2Mean = $t2Metrics ? round((float) $t2Metrics->average_score, 2) : null;

        return view('lpm.dashboard', [
            'activePeriod' => $activePeriod,
            'progressData' => $progressData,
            'totalUnits' => $totalUnits,
            'totalFaculties' => $totalFaculties,
            'totalLecturers' => $totalLecturers,
            'totalSupervisors' => $totalSupervisors,
            'totalAssignments' => $totalAssignments,
            'completedCount' => $completedCount,
            'inProgressCount' => $inProgressCount,
            'pendingCount' => $pendingCount,
            'pendingImports' => $pendingImports,
            'recentAssignments' => $recentAssignments,
            'tierCounts' => $tierCounts,
            'tierPercentages' => $tierPercentages,
            'totalSubmitted' => $totalSubmitted,
            'avgScore' => $avgScore,
            'overallPercentage' => $overallPercentage,
            'faculties' => $faculties,
            'cohortAnalytics' => $cohortAnalytics,
            't1Mean' => $t1Mean,
            't2Mean' => $t2Mean,
            't1Count' => (int) ($t1Metrics->total ?? 0),
            't2Count' => (int) ($t2Metrics->total ?? 0),
        ]);
    }
}
