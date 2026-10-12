<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\PeriodSupervisor;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        $lecturer = $user->lecturer;

        $activePeriod = SupervisionPeriod::active()->with('stages')->first()
            ?? SupervisionPeriod::latest('starts_on')->with('stages')->first();

        $myAssignments = collect();
        $submittedCount = 0;
        $pendingCount = 0;
        $quota = null;
        $inputDate = now('Asia/Jakarta');

        if ($lecturer && $activePeriod) {
            $periodSupervisor = PeriodSupervisor::where('supervision_period_id', $activePeriod->id)
                ->where('lecturer_id', $lecturer->id)
                ->first();

            $quota = $periodSupervisor?->target_quota;

            $myAssignments = SupervisionAssignment::where('supervisor_lecturer_id', $lecturer->id)
                ->whereHas('supervisionStage', fn ($q) => $q
                    ->where('supervision_period_id', $activePeriod->id)
                    ->inputWindowOpen($inputDate))
                ->where('status', '<>', 'CANCELLED')
                ->with([
                    'targetLecturer',
                    'teachingOccurrence.teachingSchedule.course',
                    'supervisionStage',
                    'supervision' => function (HasOne $relation): void {
                        $relation->getQuery()->withExists([
                            'answers' => fn (Builder $answers): Builder => $answers->where(fn (Builder $values): Builder => $values
                                ->whereNotNull('question_option_id')
                                ->orWhereNotNull('boolean_value')
                                ->orWhereNotNull('numeric_score')
                                ->orWhere('text_value', '<>', '')
                                ->orWhere('notes', '<>', '')),
                        ]);
                    },
                ])
                ->get();

            $submittedCount = $myAssignments->filter(fn ($a) => $a->supervision?->status === 'SUBMITTED')->count();
            $pendingCount = $myAssignments->filter(fn ($a) => $a->supervision?->status !== 'SUBMITTED')->count();
        }

        return view('supervisor.dashboard', compact(
            'user', 'lecturer', 'activePeriod', 'myAssignments', 'submittedCount', 'pendingCount', 'quota'
        ));
    }
}
