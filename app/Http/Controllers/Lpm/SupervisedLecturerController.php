<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\Faculty;
use App\Models\Lecturer;
use App\Models\StudyProgram;
use App\Models\SupervisionPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupervisedLecturerController extends Controller
{
    public function index(Request $request): View
    {
        $isLeadershipReport = $request->routeIs('pimpinan.*');
        $periods = SupervisionPeriod::query()
            ->with(['academicTerm', 'stages'])
            ->orderByDesc('starts_on')
            ->get();
        $selectedPeriod = $request->filled('period_id')
            ? $periods->firstWhere('id', $request->integer('period_id'))
            : ($periods->firstWhere('status', 'ACTIVE') ?? $periods->first());
        abort_if($request->filled('period_id') && ! $selectedPeriod, 404);

        $faculties = Faculty::query()
            ->when(! $isLeadershipReport, fn (Builder $query): Builder => $query->where('is_active', true))
            ->orderBy('name')
            ->get();
        $selectedFacultyId = $request->integer('faculty_id') ?: null;
        $selectedProgramId = $request->integer('study_program_id') ?: null;
        $requestedStage = (string) $request->input('stage', 'all');
        $selectedStage = in_array($requestedStage, ['1', '2'], true) ? $requestedStage : 'all';
        $requestedStatus = $isLeadershipReport ? 'submitted' : (string) $request->input('status', $request->routeIs('lpm.supervisions.completed') ? 'submitted' : 'all');
        $selectedStatus = in_array($requestedStatus, ['not_supervised', 'draft', 'submitted'], true) ? $requestedStatus : 'all';
        $showUnfinishedTasks = $requestedStatus === 'pending';
        $statusFilter = $showUnfinishedTasks ? 'pending' : $selectedStatus;
        $selectedSupervisorId = $isLeadershipReport ? null : ($request->integer('supervisor_id') ?: null);
        $stageNumbers = $selectedPeriod?->stages->pluck('stage_no')->all() ?? [];
        $supervisors = $isLeadershipReport ? collect() : Lecturer::query()
            ->whereHas('assignmentsAsSupervisor', fn (Builder $query): Builder => $query
                ->where('status', '<>', 'CANCELLED')
                ->whereHas('supervisionStage', fn (Builder $stageQuery): Builder => $stageQuery->where('supervision_period_id', $selectedPeriod?->id)))
            ->orderBy('name')->get(['id', 'name']);
        $search = trim((string) $request->input('search', ''));
        $studyPrograms = StudyProgram::query()
            ->when(! $isLeadershipReport, fn (Builder $query): Builder => $query->where('is_active', true))
            ->when($selectedFacultyId, fn (Builder $query): Builder => $query->where('faculty_id', $selectedFacultyId))
            ->with('faculty:id,code')
            ->orderBy('name')
            ->get();
        if ($selectedProgramId && ! $studyPrograms->contains(fn (StudyProgram $studyProgram): bool => $studyProgram->id === $selectedProgramId)) {
            $selectedProgramId = null;
        }

        $query = Lecturer::query()
            ->where(function (Builder $lecturers) use ($selectedPeriod): void {
                $lecturers->whereHas('periodTargets', fn (Builder $targets): Builder => $targets
                    ->where('supervision_period_id', $selectedPeriod?->id)->where('scope_status', 'INCLUDED'))
                    ->orWhereHas('assignmentsAsTarget', fn (Builder $assignments): Builder => $assignments
                        ->where('status', '<>', 'CANCELLED')
                        ->whereHas('supervisionStage', fn (Builder $stages): Builder => $stages->where('supervision_period_id', $selectedPeriod?->id)));
            })
            ->when($selectedFacultyId, fn (Builder $query): Builder => $query->where('faculty_id', $selectedFacultyId))
            ->when($selectedProgramId, fn (Builder $query): Builder => $query->where('study_program_id', $selectedProgramId))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('external_code', 'like', "%{$search}%");
                });
            });

        if ($statusFilter !== 'all' || $selectedSupervisorId) {
            $filteredStageNumbers = $selectedStage === 'all' ? $stageNumbers : array_intersect($stageNumbers, [(int) $selectedStage]);
            if ($filteredStageNumbers === []) {
                $query->whereKey([]);
            } else {
                $query->where(function (Builder $lecturers) use ($filteredStageNumbers, $selectedPeriod, $statusFilter, $selectedSupervisorId): void {
                    foreach ($filteredStageNumbers as $stageNumber) {
                        $lecturers->orWhere(function (Builder $matchingLecturers) use ($stageNumber, $selectedPeriod, $statusFilter, $selectedSupervisorId): void {
                            $stageAssignments = function (Builder $assignments) use ($stageNumber, $selectedPeriod, $statusFilter, $selectedSupervisorId): void {
                                $assignments->where('status', '<>', 'CANCELLED')
                                    ->whereHas('supervisionStage', fn (Builder $stages): Builder => $stages
                                        ->where('supervision_period_id', $selectedPeriod?->id)->where('stage_no', $stageNumber));
                                if ($selectedSupervisorId) {
                                    $assignments->where('supervisor_lecturer_id', $selectedSupervisorId);
                                }
                                $this->constrainStatus($assignments, $statusFilter);
                            };

                            $matchingLecturers->whereHas('assignmentsAsTarget', $stageAssignments);
                        });
                    }
                });
            }
        }

        $supervisedLecturers = $query->with([
            'faculty',
            'studyProgram',
            'assignmentsAsTarget' => function (HasMany $query) use ($selectedPeriod, $isLeadershipReport, $selectedStage): void {
                $query->where('status', '<>', 'CANCELLED')
                    ->whereHas('supervisionStage', fn (Builder $stageQuery): Builder => $stageQuery
                        ->where('supervision_period_id', $selectedPeriod?->id)
                        ->when($isLeadershipReport && $selectedStage !== 'all', fn (Builder $stages): Builder => $stages->where('stage_no', (int) $selectedStage)))
                    ->with([
                        'supervisorLecturer',
                        'supervisionStage',
                        'supervision' => function (HasOne $relation): void {
                            $relation->getQuery()->withExists(['answers' => $this->filledAnswers(...)]);
                        },
                    ]);
                if ($isLeadershipReport) {
                    $this->constrainStatus($query->getQuery(), 'submitted');
                }
            },
        ])
            ->orderBy('name')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('lpm.supervisions.completed', compact(
            'isLeadershipReport',
            'periods',
            'selectedPeriod',
            'faculties',
            'studyPrograms',
            'selectedFacultyId',
            'selectedProgramId',
            'selectedStage',
            'selectedStatus',
            'showUnfinishedTasks',
            'selectedSupervisorId',
            'stageNumbers',
            'supervisors',
            'search',
            'supervisedLecturers'
        ));
    }

    private function constrainStatus(Builder $assignments, string $status): void
    {
        if ($status === 'submitted') {
            $assignments->whereHas('supervision', fn (Builder $supervisions): Builder => $supervisions->where('status', 'SUBMITTED'));
        } elseif (in_array($status, ['not_supervised', 'draft', 'pending'], true)) {
            $assignments->whereDoesntHave('supervision', fn (Builder $supervisions): Builder => $supervisions->where('status', 'SUBMITTED'));
            if ($status === 'draft') {
                $assignments->whereHas('supervision.answers', $this->filledAnswers(...));
            } elseif ($status === 'not_supervised') {
                $assignments->whereDoesntHave('supervision.answers', $this->filledAnswers(...));
            }
        }
    }

    private function filledAnswers(Builder $answers): void
    {
        $answers->where(fn (Builder $values): Builder => $values
            ->whereNotNull('question_option_id')
            ->orWhereNotNull('boolean_value')
            ->orWhereNotNull('numeric_score')
            ->orWhere('text_value', '<>', '')
            ->orWhere('notes', '<>', ''));
    }
}
