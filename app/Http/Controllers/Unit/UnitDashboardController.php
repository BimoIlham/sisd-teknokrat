<?php

namespace App\Http\Controllers\Unit;

use App\Http\Controllers\Controller;
use App\Models\Lecturer;
use App\Models\PeriodTarget;
use App\Models\Supervision;
use App\Models\SupervisionPeriod;
use App\Services\Analytics\SupervisionAnalyticsService;
use App\Services\OrganizationalScope;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UnitDashboardController extends Controller
{
    public function index(Request $request, SupervisionAnalyticsService $analyticsService): View
    {
        $scope = new OrganizationalScope($request->user());
        $periods = SupervisionPeriod::with(['academicTerm', 'stages'])->latest('starts_on')->get();
        $period = $request->filled('period_id')
            ? $periods->firstWhere('id', $request->integer('period_id'))
            : ($periods->firstWhere('status', 'ACTIVE') ?? $periods->first());
        abort_if($request->filled('period_id') && ! $period, 404);
        $lecturerCount = $scope->lecturers(Lecturer::query()->where('is_active', true))->count();
        $targetCount = $period
            ? $scope->targets(PeriodTarget::query()->where('supervision_period_id', $period->id)->included())->count()
            : 0;
        $submissions = $scope->supervisions(Supervision::query()->where('status', 'SUBMITTED')
            ->whereHas('assignment', fn ($query) => $query->where('status', '<>', 'CANCELLED')))
            ->when($period,
                fn ($query) => $query->whereHas('assignment.supervisionStage',
                    fn ($stages) => $stages->where('supervision_period_id', $period->id)),
                fn ($query) => $query->whereRaw('1 = 0'));
        $submittedCount = $submissions->count();
        $averageScore = $submittedCount ? round((float) (clone $submissions)->avg('final_score'), 2) : null;
        $recentSubmissions = (clone $submissions)->with(['assignment.targetLecturer:id,name', 'assignment.supervisionStage:id,stage_no'])
            ->latest('submitted_at')->limit(5)->get();
        $analytics = $period
            ? $analyticsService->computePairedComparison($period->loadMissing('stages'), $scope->facultyId(), $scope->studyProgramId())
            : null;
        $progress = $period
            ? $analyticsService->calculateProgress($period, $scope->facultyId(), $scope->studyProgramId())
            : ['percentage' => 0, 'submitted_count' => 0, 'total_required' => 0];

        return view('unit.dashboard', compact('scope', 'periods', 'period', 'lecturerCount', 'targetCount', 'submittedCount', 'averageScore', 'recentSubmissions', 'analytics', 'progress'));
    }
}
