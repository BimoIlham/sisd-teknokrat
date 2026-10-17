<?php

namespace App\Services\Analytics;

use App\Models\PeriodTarget;
use App\Models\Supervision;
use App\Models\SupervisionPeriod;
use App\Models\SupervisionStage;
use Illuminate\Database\Eloquent\Builder;

class SupervisionAnalyticsService
{
    /**
     * Calculate global progress according to Invariant 38:
     * Progress = submitted / (target INCLUDED * mandatory stages)
     */
    public function calculateProgress(SupervisionPeriod $period, ?int $facultyId = null, ?int $studyProgramId = null): array
    {
        $includedTargetsCount = PeriodTarget::where('supervision_period_id', $period->id)
            ->where('scope_status', 'INCLUDED')
            ->when($facultyId || $studyProgramId, fn (Builder $query): Builder => $query->whereHas('lecturer',
                fn (Builder $lecturers): Builder => $this->filterLecturers($lecturers, $facultyId, $studyProgramId)))
            ->count();

        $stagesCount = SupervisionStage::where('supervision_period_id', $period->id)->count();

        $totalRequiredSubmissions = $includedTargetsCount * $stagesCount;

        $submittedCount = Supervision::whereHas('assignment', fn ($query) => $query->where('status', '<>', 'CANCELLED'))
            ->whereHas('assignment.supervisionStage', function ($q) use ($period) {
                $q->where('supervision_period_id', $period->id);
            })
            ->when($facultyId || $studyProgramId, fn (Builder $query): Builder => $query->whereHas('assignment.targetLecturer',
                fn (Builder $lecturers): Builder => $this->filterLecturers($lecturers, $facultyId, $studyProgramId)))
            ->where('status', 'SUBMITTED')
            ->count();

        $percentage = $totalRequiredSubmissions > 0
            ? round(($submittedCount / $totalRequiredSubmissions) * 100, 1)
            : 0.0;

        return [
            'included_targets_count' => $includedTargetsCount,
            'stages_count' => $stagesCount,
            'total_required' => $totalRequiredSubmissions,
            'submitted_count' => $submittedCount,
            'percentage' => $percentage,
        ];
    }

    /**
     * Compute per-stage results and paired T1 vs T2 cohort analytics.
     * A stage result is available as soon as that supervision is submitted; a score delta
     * is only available when the lecturer has comparable submissions in both stages.
     */
    public function computePairedComparison(
        SupervisionPeriod $period,
        ?int $facultyId = null,
        ?int $studyProgramId = null
    ): array {
        $t1Stage = $period->stages->firstWhere('stage_order', 1);
        $t2Stage = $period->stages->firstWhere('stage_order', 2);

        $submittedSupervisions = function (?SupervisionStage $stage) use ($facultyId, $studyProgramId) {
            if (! $stage) {
                return collect();
            }

            return Supervision::whereHas('assignment', function ($query) use ($stage): void {
                $query->where('supervision_stage_id', $stage->id)
                    ->where('status', '<>', 'CANCELLED');
            })
                ->whereHas('assignment.targetLecturer', fn (Builder $query): Builder => $this->filterLecturers($query, $facultyId, $studyProgramId))
                ->where('status', 'SUBMITTED')
                ->with([
                    'assignment.targetLecturer.studyProgram.faculty',
                    'assignment.teachingOccurrence.teachingSchedule',
                    'formTemplate',
                    'scoringPolicy',
                ])
                ->get()
                ->keyBy(fn (Supervision $supervision) => $supervision->assignment->target_lecturer_id);
        };

        $t1Supervisions = $submittedSupervisions($t1Stage);
        $t2Supervisions = $submittedSupervisions($t2Stage);

        $comparablePairs = [];
        $incomparablePairs = [];
        $stageResults = [];
        $totalDelta = 0.0;
        $totalT1 = $t1Supervisions->sum(fn (Supervision $supervision): float => (float) $supervision->final_score);
        $totalT2 = $t2Supervisions->sum(fn (Supervision $supervision): float => (float) $supervision->final_score);

        $targetLecturerIds = $t1Supervisions->keys()
            ->merge($t2Supervisions->keys())
            ->unique();

        foreach ($targetLecturerIds as $targetLecturerId) {
            $s1 = $t1Supervisions->get($targetLecturerId);
            $s2 = $t2Supervisions->get($targetLecturerId);
            $targetLecturer = $s1?->assignment->targetLecturer ?? $s2?->assignment->targetLecturer;
            $mode1 = $s1?->assignment->teachingOccurrence?->teachingSchedule?->delivery_mode;
            $mode2 = $s2?->assignment->teachingOccurrence?->teachingSchedule?->delivery_mode;

            $row = [
                'target_lecturer_id' => $targetLecturerId,
                'target_lecturer' => $targetLecturer?->name ?? 'Dosen tidak diketahui',
                'nidn' => $targetLecturer?->nidn,
                'prodi' => $targetLecturer?->studyProgram?->name ?? '-',
                'faculty' => $targetLecturer?->studyProgram?->faculty?->name ?? '-',
                'delivery_mode' => $mode1 ?? $mode2,
                't1_score' => $s1 ? round((float) $s1->final_score, 2) : null,
                't2_score' => $s2 ? round((float) $s2->final_score, 2) : null,
                'delta' => null,
                'direction' => null,
            ];

            if (! $s1 || ! $s2) {
                $missingStageNo = $s1 ? 2 : 1;
                $missingStage = $missingStageNo === 1 ? $t1Stage : $t2Stage;
                $row['comparison_status'] = 'WAITING';
                $row['reason'] = $missingStage
                    ? "Tahap {$missingStageNo} belum disubmit oleh supervisor."
                    : "Tahap {$missingStageNo} belum diatur pada periode ini.";

                $stageResults[] = $row;
                $incomparablePairs[] = $row;

                continue;
            }

            // Invariant 37 check: delivery mode or template mismatch
            $tpl1 = $s1->form_template_id;
            $tpl2 = $s2->form_template_id;

            if ($mode1 !== $mode2 || $tpl1 !== $tpl2) {
                $row['comparison_status'] = 'INCOMPARABLE';
                $row['reason'] = "Metode/Instrumen berbeda (T1: {$mode1} vs T2: {$mode2}).";

                $stageResults[] = $row;
                $incomparablePairs[] = $row;

                continue;
            }

            // Comparable pair!
            $delta = (float) $s2->final_score - (float) $s1->final_score;
            $totalDelta += $delta;
            $row['delta'] = round($delta, 2);
            $row['direction'] = $delta > 0 ? 'INCREASE' : ($delta < 0 ? 'DECREASE' : 'STABLE');
            $row['comparison_status'] = 'COMPARABLE';
            $row['reason'] = null;

            $stageResults[] = $row;
            $comparablePairs[] = $row;
        }

        $n = count($comparablePairs);
        $averageDelta = $n > 0 ? round($totalDelta / $n, 2) : null;
        $t1Count = $t1Supervisions->count();
        $t2Count = $t2Supervisions->count();
        $meanT1 = $t1Count > 0 ? round($totalT1 / $t1Count, 2) : null;
        $meanT2 = $t2Count > 0 ? round($totalT2 / $t2Count, 2) : null;

        return [
            'has_two_stages' => (bool) $t1Stage && (bool) $t2Stage,
            'stage_results' => collect($stageResults)->sortBy('target_lecturer')->values()->all(),
            'results_count' => count($stageResults),
            't1_submitted_count' => $t1Count,
            't2_submitted_count' => $t2Count,
            'comparable_pairs' => $comparablePairs,
            'incomparable_pairs' => $incomparablePairs,
            'comparable_count' => $n,
            'average_delta' => $averageDelta,
            'mean_t1' => $meanT1,
            'mean_t2' => $meanT2,
        ];
    }

    private function filterLecturers(Builder $query, ?int $facultyId, ?int $studyProgramId): Builder
    {
        if ($studyProgramId) {
            return $query->where('study_program_id', $studyProgramId);
        }

        if ($facultyId) {
            return $query->where(function (Builder $lecturers) use ($facultyId): void {
                $lecturers->whereHas('studyProgram', fn (Builder $programs): Builder => $programs->where('faculty_id', $facultyId))
                    ->orWhere(fn (Builder $withoutProgram): Builder => $withoutProgram->whereNull('study_program_id')->where('faculty_id', $facultyId));
            });
        }

        return $query;
    }
}
