<?php

namespace App\Services\Scheduling;

use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use Illuminate\Support\Collection;

class ConflictChecker
{
    /** @var array<string, Collection<int, TeachingOccurrence>> */
    private array $teachingOccurrencesBySupervisorAndDate = [];

    /** @var array<int, Collection<int, SupervisionAssignment>> */
    private array $assignmentsBySupervisor = [];

    /** @var array<string, SupervisionAssignment|null> */
    private array $firstStageAssignmentsByTarget = [];

    public function reset(): void
    {
        $this->teachingOccurrencesBySupervisorAndDate = [];
        $this->assignmentsBySupervisor = [];
        $this->firstStageAssignmentsByTarget = [];
    }

    /**
     * Prime conflict data for a complete candidate pool.
     *
     * @param  Collection<int, TeachingOccurrence>  $occurrences
     * @param  Collection<int, int>  $supervisorLecturerIds
     */
    public function primeForCandidates(Collection $occurrences, Collection $supervisorLecturerIds, SupervisionStage $stage): void
    {
        $this->teachingOccurrencesBySupervisorAndDate = [];
        $this->assignmentsBySupervisor = [];
        $this->firstStageAssignmentsByTarget = [];

        $supervisorIds = $supervisorLecturerIds
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $dates = $occurrences
            ->map(fn (TeachingOccurrence $occurrence) => $occurrence->occurs_on->toDateString())
            ->unique()
            ->values();

        foreach ($supervisorIds as $supervisorId) {
            $this->assignmentsBySupervisor[$supervisorId] = collect();

            foreach ($dates as $date) {
                $this->teachingOccurrencesBySupervisorAndDate[$this->teachingCacheKey($supervisorId, $date)] = collect();
            }
        }

        if ($supervisorIds->isEmpty() || $occurrences->isEmpty()) {
            return;
        }

        $teachingOccurrences = TeachingOccurrence::query()
            ->whereHas('teachingSchedule', fn ($query) => $query->whereIn('lecturer_id', $supervisorIds)->where('is_active', true))
            ->where('status', 'SCHEDULED')
            ->whereIn('occurs_on', $dates)
            ->with('teachingSchedule:id,lecturer_id')
            ->get(['id', 'teaching_schedule_id', 'occurs_on', 'starts_at', 'ends_at']);

        foreach ($teachingOccurrences->groupBy(function (TeachingOccurrence $occurrence) {
            return $this->teachingCacheKey(
                (int) $occurrence->teachingSchedule->lecturer_id,
                $occurrence->occurs_on->toDateString()
            );
        }) as $key => $group) {
            $this->teachingOccurrencesBySupervisorAndDate[$key] = $group;
        }

        $rangeStart = $occurrences->min(fn (TeachingOccurrence $occurrence) => $occurrence->starts_at);
        $rangeEnd = $occurrences->max(fn (TeachingOccurrence $occurrence) => $occurrence->ends_at);
        $assignments = SupervisionAssignment::query()
            ->whereIn('supervisor_lecturer_id', $supervisorIds)
            ->where('status', '<>', 'CANCELLED')
            ->where('starts_at', '<', $rangeEnd)
            ->where('ends_at', '>', $rangeStart)
            ->get(['id', 'supervisor_lecturer_id', 'teaching_occurrence_id', 'starts_at', 'ends_at']);

        foreach ($assignments->groupBy('supervisor_lecturer_id') as $supervisorId => $group) {
            $this->assignmentsBySupervisor[(int) $supervisorId] = $group;
        }

        if ($stage->stage_order !== 2) {
            return;
        }

        $stage->loadMissing('supervisionPeriod.stages');
        $firstStage = $stage->supervisionPeriod->stages->firstWhere('stage_order', 1);
        if (! $firstStage) {
            return;
        }

        $targetLecturerIds = $occurrences
            ->pluck('teachingSchedule.lecturer_id')
            ->unique()
            ->values();

        foreach ($targetLecturerIds as $targetLecturerId) {
            $this->firstStageAssignmentsByTarget[$this->firstStageCacheKey($firstStage->id, (int) $targetLecturerId)] = null;
        }

        $firstStageAssignments = SupervisionAssignment::query()
            ->where('supervision_stage_id', $firstStage->id)
            ->whereIn('target_lecturer_id', $targetLecturerIds)
            ->where('status', '<>', 'CANCELLED')
            ->with('teachingOccurrence:id,occurs_on')
            ->get()
            ->groupBy('target_lecturer_id');

        foreach ($firstStageAssignments as $targetLecturerId => $group) {
            $this->firstStageAssignmentsByTarget[$this->firstStageCacheKey($firstStage->id, (int) $targetLecturerId)] = $group->first();
        }
    }

    /**
     * Check hard constraints H01-H08 for a proposed assignment.
     *
     * @return array{valid: bool, conflicts: list<string>}
     */
    public function check(
        TeachingOccurrence $occurrence,
        int $supervisorLecturerId,
        SupervisionStage $stage,
        ?int $ignoredAssignmentId = null
    ): array {
        $conflicts = [];
        $targetLecturerId = $occurrence->teachingSchedule->lecturer_id;
        if ($occurrence->status !== 'SCHEDULED' || ! $occurrence->teachingSchedule->is_active) {
            $conflicts[] = 'Jadwal perkuliahan belum aktif atau pertemuan sudah dibatalkan.';
        }
        if ($occurrence->teachingSchedule->academic_term_id !== $stage->supervisionPeriod->academic_term_id) {
            $conflicts[] = 'Jadwal perkuliahan harus berasal dari semester pada periode supervisi ini.';
        }

        // H01: Self-supervision is strictly forbidden
        if ($targetLecturerId === $supervisorLecturerId) {
            $conflicts[] = 'H01: Self-supervision dilarang (Dosen target tidak dapat menilai dirinya sendiri).';
        }

        // Check if occurrence is within stage bounds
        $occDate = $occurrence->occurs_on->toDateString();
        $stageStart = $stage->start_date->toDateString();
        $stageEnd = $stage->end_date->toDateString();

        if ($occDate < $stageStart || $occDate > $stageEnd) {
            $conflicts[] = "Tanggal kelas ({$occDate}) berada di luar rentang Tahap ({$stageStart} s/d {$stageEnd}).";
        }

        // H02: Supervisor cannot teach at the same time
        $supervisorTeachingOccurrences = $this->teachingOccurrencesFor(
            $supervisorLecturerId,
            $occurrence->occurs_on->toDateString()
        );
        $supervisorTeachingConflicts = $supervisorTeachingOccurrences->contains(
            fn (TeachingOccurrence $candidate) => $candidate->starts_at < $occurrence->ends_at
                && $candidate->ends_at > $occurrence->starts_at
        );

        if ($supervisorTeachingConflicts) {
            $conflicts[] = "H02: Bentrok jadwal mengajar supervisor pada tanggal {$occDate}.";
        }

        // H04: Supervisor cannot have concurrent supervision assignment
        $supervisorSupervisionConflicts = $this->assignmentsForSupervisor($supervisorLecturerId)->contains(
            fn (SupervisionAssignment $assignment) => $assignment->id !== $ignoredAssignmentId
                && $assignment->teaching_occurrence_id !== $occurrence->id
                && $assignment->starts_at < $occurrence->ends_at
                && $assignment->ends_at > $occurrence->starts_at
        );

        if ($supervisorSupervisionConflicts) {
            $conflicts[] = 'H04: Supervisor telah memiliki penugasan supervisi lain pada waktu yang bersamaan.';
        }

        // H05: Baseline >= 3 teaching sessions on same date prohibits additional supervision
        $supervisorClassesCount = $supervisorTeachingOccurrences->count();

        if ($supervisorClassesCount >= 3) {
            $conflicts[] = "H05: Supervisor memiliki {$supervisorClassesCount} jadwal mengajar pada tanggal {$occDate} (ambang batas >= 3 pertemuan tercapai).";
        }

        // Tahap 2 precedence: T2 must happen strictly after T1 for the same target
        if ($stage->stage_order === 2) {
            $period = $stage->supervisionPeriod;
            $t1Stage = $period->stages->firstWhere('stage_order', 1);

            if ($t1Stage) {
                $t1Assignment = $this->firstStageAssignment($t1Stage->id, $targetLecturerId);

                if ($t1Assignment && $t1Assignment->teachingOccurrence) {
                    $t1Date = $t1Assignment->teachingOccurrence->occurrence_date->toDateString();
                    if ($occDate <= $t1Date) {
                        $conflicts[] = "Invarian T2: Supervisi Tahap 2 ({$occDate}) harus berlangsung setelah tanggal Tahap 1 ({$t1Date}).";
                    }
                }
            }
        }

        return [
            'valid' => empty($conflicts),
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @return Collection<int, TeachingOccurrence>
     */
    private function teachingOccurrencesFor(int $supervisorLecturerId, string $date): Collection
    {
        $key = $this->teachingCacheKey($supervisorLecturerId, $date);
        if (! array_key_exists($key, $this->teachingOccurrencesBySupervisorAndDate)) {
            $this->teachingOccurrencesBySupervisorAndDate[$key] = TeachingOccurrence::query()
                ->whereHas('teachingSchedule', fn ($query) => $query->where('lecturer_id', $supervisorLecturerId)->where('is_active', true))
                ->where('status', 'SCHEDULED')
                ->where('occurs_on', $date)
                ->get(['id', 'teaching_schedule_id', 'occurs_on', 'starts_at', 'ends_at']);
        }

        return $this->teachingOccurrencesBySupervisorAndDate[$key];
    }

    /**
     * @return Collection<int, SupervisionAssignment>
     */
    private function assignmentsForSupervisor(int $supervisorLecturerId): Collection
    {
        if (! array_key_exists($supervisorLecturerId, $this->assignmentsBySupervisor)) {
            $this->assignmentsBySupervisor[$supervisorLecturerId] = SupervisionAssignment::query()
                ->where('supervisor_lecturer_id', $supervisorLecturerId)
                ->where('status', '<>', 'CANCELLED')
                ->get(['id', 'supervisor_lecturer_id', 'teaching_occurrence_id', 'starts_at', 'ends_at']);
        }

        return $this->assignmentsBySupervisor[$supervisorLecturerId];
    }

    private function firstStageAssignment(int $firstStageId, int $targetLecturerId): ?SupervisionAssignment
    {
        $key = $this->firstStageCacheKey($firstStageId, $targetLecturerId);
        if (! array_key_exists($key, $this->firstStageAssignmentsByTarget)) {
            $this->firstStageAssignmentsByTarget[$key] = SupervisionAssignment::query()
                ->where('supervision_stage_id', $firstStageId)
                ->where('target_lecturer_id', $targetLecturerId)
                ->where('status', '<>', 'CANCELLED')
                ->with('teachingOccurrence')
                ->first();
        }

        return $this->firstStageAssignmentsByTarget[$key];
    }

    private function teachingCacheKey(int $supervisorLecturerId, string $date): string
    {
        return $supervisorLecturerId.':'.$date;
    }

    private function firstStageCacheKey(int $firstStageId, int $targetLecturerId): string
    {
        return $firstStageId.':'.$targetLecturerId;
    }
}
