<?php

namespace App\Services\Scheduling;

use App\Models\PeriodSupervisor;
use App\Models\PeriodTarget;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use Illuminate\Validation\ValidationException;

class CandidateBuilder
{
    public function __construct(
        protected ConflictChecker $conflictChecker
    ) {}

    /**
     * Build the structured candidate pool for a given supervision stage.
     */
    public function build(SupervisionStage $stage): array
    {
        $period = $stage->supervisionPeriod;
        $stage->loadMissing('supervisionPeriod.stages');
        if ($period->status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'period' => ['Periode supervisi sudah tidak aktif. Jadwal tidak tersedia untuk penugasan baru.'],
            ]);
        }

        // Targets included for this period
        $targets = PeriodTarget::where('supervision_period_id', $period->id)
            ->where('scope_status', 'INCLUDED')
            ->whereHas('lecturer', fn ($query) => $query->where('is_active', true))
            ->with('lecturer')
            ->orderBy('lecturer_id')->get();

        // Supervisors active for this period
        $supervisors = PeriodSupervisor::where('supervision_period_id', $period->id)
            ->where('is_active', true)
            ->whereHas('lecturer', fn ($query) => $query->where('is_active', true))
            ->with('lecturer')
            ->orderBy('lecturer_id')->get();

        $targetLecturerIds = $targets->pluck('lecturer_id')->toArray();
        $supervisorLecturerIds = $supervisors->pluck('lecturer_id')->toArray();

        $counterpartOrder = $stage->stage_order === 1 ? 2 : 1;
        $counterpartStage = $period->stages->firstWhere('stage_order', $counterpartOrder);
        $pairedAssignmentsByTarget = $counterpartStage
            ? SupervisionAssignment::query()
                ->where('supervision_stage_id', $counterpartStage->id)
                ->where('status', '<>', 'CANCELLED')
                ->whereIn('target_lecturer_id', $targetLecturerIds)
                ->with('teachingOccurrence.teachingSchedule')
                ->get()
                ->keyBy('target_lecturer_id')
            : collect();

        // Occurrences within stage date range for target lecturers
        $occurrences = TeachingOccurrence::whereHas('teachingSchedule', function ($q) use ($targetLecturerIds, $period) {
            $q->whereIn('lecturer_id', $targetLecturerIds)->where('supervision_period_id', $period->id)->where('is_active', true);
        })
            ->where('status', 'SCHEDULED')
            ->whereBetween('occurs_on', [$stage->start_date, $stage->end_date])
            ->with('teachingSchedule')
            ->orderBy('id')->get();

        $this->conflictChecker->primeForCandidates(
            $occurrences,
            collect($supervisorLecturerIds),
            $stage
        );

        // Filter valid candidates for each target occurrence and supervisor
        $candidates = [];

        foreach ($occurrences as $occ) {
            $schedule = $occ->teachingSchedule;
            $targetId = $schedule->lecturer_id;
            $date = $occ->occurrence_date->toDateString();
            $dayOfWeek = $schedule->day_of_week ?? (int) $occ->occurs_on->format('N');
            $startTime = $occ->start_time_local;
            $endTime = $occ->end_time_local;
            $pairedAssignment = $pairedAssignmentsByTarget->get($targetId);
            $pairedOccurrence = $pairedAssignment?->teachingOccurrence;
            if ($pairedAssignment && (! $pairedOccurrence || ! $occ->hasSameSupervisionSlotAs($pairedOccurrence))) {
                continue;
            }

            $pairedSupervisorId = $pairedAssignment?->supervisor_lecturer_id;
            $eligibleSupervisors = $pairedSupervisorId
                ? $supervisors->where('lecturer_id', $pairedSupervisorId)
                : $supervisors;
            $baseCandidate = null;

            foreach ($eligibleSupervisors as $sup) {
                $supId = $sup->lecturer_id;

                $check = $this->conflictChecker->check($occ, $supId, $stage);

                if ($check['valid']) {
                    $baseCandidate ??= [
                        'occurrence_id' => $occ->id,
                        'target_lecturer_id' => $targetId,
                        'date' => $date,
                        'day_of_week' => (int) $dayOfWeek,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'schedule_identity' => $schedule->supervisionIdentity(),
                        'delivery_mode' => $schedule->delivery_mode,
                    ];
                    $candidate = $baseCandidate;
                    $candidate['supervisor_lecturer_id'] = $supId;
                    $candidates[] = $candidate;
                }
            }
        }

        return [
            'stage' => [
                'id' => $stage->id,
                'name' => $stage->name,
                'order' => $stage->stage_order,
                'start_date' => $stage->start_date->toDateString(),
                'end_date' => $stage->end_date->toDateString(),
            ],
            'targets' => $targets->map(fn ($t) => [
                'lecturer_id' => $t->lecturer_id,
                'name' => $t->lecturer->name,
                'nidn' => $t->lecturer->nidn,
            ])->values()->toArray(),
            'supervisors' => $supervisors->map(fn ($s) => [
                'lecturer_id' => $s->lecturer_id,
                'name' => $s->lecturer->name,
                'target_quota' => $s->target_quota,
            ])->values()->toArray(),
            'candidates' => $candidates,
        ];
    }
}
