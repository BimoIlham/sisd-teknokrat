<?php

namespace App\Http\Controllers\Unit;

use App\Http\Controllers\Controller;
use App\Models\SupervisionPeriod;
use App\Services\Analytics\SupervisionAnalyticsService;
use App\Services\OrganizationalScope;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UnitAnalyticsController extends Controller
{
    public function index(Request $request, SupervisionAnalyticsService $analyticsService): View
    {
        $scope = new OrganizationalScope($request->user());
        $periods = SupervisionPeriod::with(['academicTerm', 'stages'])->latest('starts_on')->get();
        $period = $request->filled('period_id')
            ? $periods->firstWhere('id', $request->integer('period_id'))
            : ($periods->firstWhere('status', 'ACTIVE') ?? $periods->first());
        abort_if($request->filled('period_id') && ! $period, 404);
        $analytics = $period
            ? $analyticsService->computePairedComparison($period, $scope->facultyId(), $scope->studyProgramId())
            : null;
        $progress = $period
            ? $analyticsService->calculateProgress($period, $scope->facultyId(), $scope->studyProgramId())
            : null;

        return view('unit.analytics.index', compact('scope', 'periods', 'period', 'analytics', 'progress'));
    }
}
