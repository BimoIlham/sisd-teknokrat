<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\Faculty;
use App\Models\Lecturer;
use App\Models\StudyProgram;
use App\Models\SupervisionPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupervisedLecturerController extends Controller
{
    public function index(Request $request): View
    {
        $periods = SupervisionPeriod::query()
            ->with('academicTerm')
            ->orderByDesc('starts_on')
            ->get();
        $selectedPeriod = $request->filled('period_id')
            ? $periods->firstWhere('id', $request->integer('period_id'))
            : ($periods->firstWhere('status', 'ACTIVE') ?? $periods->first());
        abort_if($request->filled('period_id') && ! $selectedPeriod, 404);

        $faculties = Faculty::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        $selectedFacultyId = $request->integer('faculty_id') ?: null;
        $selectedProgramId = $request->integer('study_program_id') ?: null;
        $requestedStage = (string) $request->input('stage', 'all');
        $selectedStage = in_array($requestedStage, ['1', '2'], true) ? $requestedStage : 'all';
        $search = trim((string) $request->input('search', ''));
        $studyPrograms = StudyProgram::query()
            ->where('is_active', true)
            ->when($selectedFacultyId, fn (Builder $query): Builder => $query->where('faculty_id', $selectedFacultyId))
            ->with('faculty:id,code')
            ->orderBy('name')
            ->get();
        if ($selectedProgramId && ! $studyPrograms->contains(fn (StudyProgram $studyProgram): bool => $studyProgram->id === $selectedProgramId)) {
            $selectedProgramId = null;
        }

        $supervisedLecturers = Lecturer::query()
            ->whereHas('assignmentsAsTarget', function (Builder $query) use ($selectedPeriod, $selectedStage): void {
                $query->where('status', '<>', 'CANCELLED')
                    ->whereHas('supervision', fn (Builder $supervisionQuery) => $supervisionQuery->where('status', 'SUBMITTED'))
                    ->whereHas('supervisionStage', function (Builder $stageQuery) use ($selectedPeriod, $selectedStage): void {
                        $stageQuery->where('supervision_period_id', $selectedPeriod?->id);

                        if ($selectedStage !== 'all') {
                            $stageQuery->where('stage_no', (int) $selectedStage);
                        }
                    });
            })
            ->when($selectedFacultyId, fn (Builder $query): Builder => $query->where('faculty_id', $selectedFacultyId))
            ->when($selectedProgramId, fn (Builder $query): Builder => $query->where('study_program_id', $selectedProgramId))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('external_code', 'like', "%{$search}%");
                });
            })
            ->with([
                'faculty',
                'studyProgram',
                'assignmentsAsTarget' => function (HasMany $query) use ($selectedPeriod): void {
                    $query->where('status', '<>', 'CANCELLED')
                        ->whereHas('supervisionStage', fn (Builder $stageQuery) => $stageQuery->where('supervision_period_id', $selectedPeriod?->id))
                        ->with([
                            'supervisorLecturer',
                            'supervisionStage',
                            'supervision',
                            'teachingOccurrence.teachingSchedule.course',
                        ]);
                },
            ])
            ->orderBy('name')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('lpm.supervisions.completed', compact(
            'periods',
            'selectedPeriod',
            'faculties',
            'studyPrograms',
            'selectedFacultyId',
            'selectedProgramId',
            'selectedStage',
            'search',
            'supervisedLecturers'
        ));
    }
}
