<?php

namespace App\Services\Scheduling;

use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use Illuminate\Support\Collection;

class PythonSolverPayloadBuilder
{
    /**
     * Build the compact request contract consumed by the Python CP-SAT solver.
     *
     * @param  array<string, mixed>  $pool
     * @param  Collection<int, SupervisionAssignment>  $lockedAssignments
     * @return array<string, mixed>
     */
    public function build(
        SupervisionStage $stage,
        array $pool,
        Collection $lockedAssignments,
        int $inputRevision,
        int $timeoutSeconds,
        string $requestId
    ): array {
        $stage->loadMissing('supervisionPeriod.academicTerm', 'supervisionPeriod.activeRule', 'supervisionPeriod.stages');
        $period = $stage->supervisionPeriod;
        $supervisorIds = array_column($pool['supervisors'], 'lecturer_id');
        $occurrences = $pool['occurrences'];
        $occurrenceDates = collect($occurrences)->pluck('occurs_on')->unique()->values();

        $teachingBusyIntervals = collect();
        if ($supervisorIds !== [] && $occurrenceDates->isNotEmpty()) {
            $teachingBusyIntervals = TeachingOccurrence::query()
                ->whereHas('teachingSchedule', fn ($query) => $query
                    ->whereIn('lecturer_id', $supervisorIds)
                    ->where('supervision_period_id', $period->id)
                    ->where('academic_term_id', $period->academic_term_id)
                    ->where('is_active', true))
                ->where('status', 'SCHEDULED')
                ->whereIn('occurs_on', $occurrenceDates)
                ->with('teachingSchedule:id,lecturer_id')
                ->orderBy('id')
                ->get(['id', 'teaching_schedule_id', 'occurs_on', 'starts_at', 'ends_at'])
                ->map(fn (TeachingOccurrence $occurrence): array => [
                    'supervisor_id' => (int) $occurrence->teachingSchedule->lecturer_id,
                    'occurs_on' => $occurrence->occurs_on->toDateString(),
                    'starts_at' => $occurrence->starts_at->toIso8601String(),
                    'ends_at' => $occurrence->ends_at->toIso8601String(),
                ]);
        }

        $reservedSupervisions = collect();
        if ($supervisorIds !== []) {
            $reservedSupervisions = SupervisionAssignment::query()
                ->whereIn('supervisor_lecturer_id', $supervisorIds)
                ->whereHas('supervisionStage', fn ($query) => $query->where('supervision_period_id', $period->id))
                ->where('status', '<>', 'CANCELLED')
                ->where(fn ($query) => $query
                    ->where('supervision_stage_id', '<>', $stage->id)
                    ->orWhereIn('assignment_type', ['MANUAL', 'LOCKED']))
                ->with('teachingOccurrence:id,occurs_on')
                ->orderBy('id')
                ->get(['id', 'target_lecturer_id', 'supervisor_lecturer_id', 'teaching_occurrence_id', 'starts_at', 'ends_at'])
                ->map(fn (SupervisionAssignment $assignment): array => [
                    'supervisor_id' => (int) $assignment->supervisor_lecturer_id,
                    'target_id' => (int) $assignment->target_lecturer_id,
                    'occurs_on' => $assignment->teachingOccurrence?->occurs_on?->toDateString()
                        ?? $assignment->starts_at->toDateString(),
                    'starts_at' => $assignment->starts_at->toIso8601String(),
                    'ends_at' => $assignment->ends_at->toIso8601String(),
                ]);
        }

        $previousAutoAssignments = SupervisionAssignment::query()
            ->where('supervision_stage_id', $stage->id)
            ->where('assignment_type', 'AUTO')
            ->where('status', '<>', 'CANCELLED')
            ->orderBy('id')
            ->get(['target_lecturer_id', 'supervisor_lecturer_id', 'teaching_occurrence_id'])
            ->map(fn (SupervisionAssignment $assignment): array => [
                'target_id' => (int) $assignment->target_lecturer_id,
                'supervisor_id' => (int) $assignment->supervisor_lecturer_id,
                'occurrence_id' => (int) $assignment->teaching_occurrence_id,
            ]);

        $priorStageAssignments = collect();
        if ($stage->stage_order === 2) {
            $firstStage = $period->stages->firstWhere('stage_order', 1);
            if ($firstStage) {
                $priorStageAssignments = SupervisionAssignment::query()
                    ->where('supervision_stage_id', $firstStage->id)
                    ->where('status', '<>', 'CANCELLED')
                    ->with('teachingOccurrence:id,occurs_on')
                    ->orderBy('id')
                    ->get(['target_lecturer_id', 'supervisor_lecturer_id', 'teaching_occurrence_id', 'starts_at'])
                    ->map(fn (SupervisionAssignment $assignment): array => [
                        'target_id' => (int) $assignment->target_lecturer_id,
                        'supervisor_id' => (int) $assignment->supervisor_lecturer_id,
                        'occurs_on' => $assignment->teachingOccurrence?->occurs_on?->toDateString()
                            ?? $assignment->starts_at->toDateString(),
                    ]);
            }
        }

        return [
            'contract_version' => '1.0',
            'request_id' => $requestId,
            'input_revision' => $inputRevision,
            'period_id' => (int) $period->id,
            'stage_id' => (int) $stage->id,
            'stage_no' => $stage->stage_order,
            'timezone' => $period->academicTerm->timezone ?: 'Asia/Jakarta',
            'date_range' => [
                'starts_on' => $stage->start_date->toDateString(),
                'ends_on' => $stage->end_date->toDateString(),
            ],
            'rules' => $period->activeRule?->config ?? [],
            'targets' => collect($pool['targets'])->map(fn (array $target): array => [
                'id' => (int) $target['lecturer_id'],
                'name' => (string) $target['name'],
                'faculty_id' => $target['faculty_id'],
                'study_program_id' => $target['study_program_id'],
            ])->values()->all(),
            'supervisors' => collect($pool['supervisors'])->map(fn (array $supervisor): array => [
                'id' => (int) $supervisor['lecturer_id'],
                'name' => (string) $supervisor['name'],
                'faculty_id' => $supervisor['faculty_id'],
                'study_program_id' => $supervisor['study_program_id'],
            ])->values()->all(),
            'occurrences' => $occurrences,
            'teaching_busy_intervals' => $teachingBusyIntervals->all(),
            'reserved_supervisions' => $reservedSupervisions->all(),
            'locked_assignments' => $lockedAssignments->map(fn (SupervisionAssignment $assignment): array => [
                'target_id' => (int) $assignment->target_lecturer_id,
                'supervisor_id' => (int) $assignment->supervisor_lecturer_id,
                'occurrence_id' => (int) $assignment->teaching_occurrence_id,
            ])->values()->all(),
            'previous_auto_assignments' => $previousAutoAssignments->all(),
            'prior_stage_assignments' => $priorStageAssignments->all(),
            'solver_options' => ['time_limit_seconds' => $timeoutSeconds],
        ];
    }
}
