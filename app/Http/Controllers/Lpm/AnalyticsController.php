<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\Faculty;
use App\Models\StudyProgram;
use App\Models\SupervisionPeriod;
use App\Services\Analytics\SupervisionAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function __construct(
        protected SupervisionAnalyticsService $analyticsService
    ) {}

    public function index(Request $request): View
    {
        $periods = SupervisionPeriod::with(['academicTerm', 'stages'])
            ->latest('starts_on')
            ->get();
        $period = $periods->firstWhere('id', $request->integer('period_id'))
            ?? $periods->firstWhere('status', 'ACTIVE')
            ?? $periods->first();

        $faculties = Faculty::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $selectedFacultyId = $request->integer('faculty_id') ?: null;
        if ($selectedFacultyId && ! $faculties->contains('id', $selectedFacultyId)) {
            $selectedFacultyId = null;
        }
        $selectedStudyProgramId = $request->integer('study_program_id') ?: null;
        $selectedStudyProgram = $selectedStudyProgramId
            ? StudyProgram::query()
                ->where('is_active', true)
                ->whereHas('faculty', fn ($query) => $query->where('is_active', true))
                ->find($selectedStudyProgramId)
            : null;

        if ($selectedStudyProgramId && (! $selectedStudyProgram
            || ($selectedFacultyId && (int) $selectedStudyProgram->faculty_id !== $selectedFacultyId))) {
            $selectedStudyProgramId = null;
        }

        $studyPrograms = StudyProgram::query()
            ->where('is_active', true)
            ->whereHas('faculty', fn ($query) => $query->where('is_active', true))
            ->when($selectedFacultyId, fn ($query) => $query->where('faculty_id', $selectedFacultyId))
            ->with('faculty:id,name')
            ->orderBy('name')
            ->get(['id', 'faculty_id', 'name']);

        $analytics = $period
            ? $this->analyticsService->computePairedComparison($period, $selectedFacultyId, $selectedStudyProgramId)
            : [
                'has_two_stages' => false,
                'stage_results' => [],
                'results_count' => 0,
                't1_submitted_count' => 0,
                't2_submitted_count' => 0,
                'comparable_pairs' => [],
                'incomparable_pairs' => [],
                'comparable_count' => 0,
                'average_delta' => null,
                'mean_t1' => null,
                'mean_t2' => null,
            ];

        $progress = $period
            ? $this->analyticsService->calculateProgress($period)
            : [
                'percentage' => 0,
                'submitted_count' => 0,
                'total_required' => 0,
                'included_targets_count' => 0,
                'stages_count' => 0,
            ];

        return view('lpm.analytics.index', compact(
            'periods',
            'period',
            'analytics',
            'progress',
            'faculties',
            'studyPrograms',
            'selectedFacultyId',
            'selectedStudyProgramId'
        ));
    }
}
