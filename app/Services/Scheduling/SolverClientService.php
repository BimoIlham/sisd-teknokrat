<?php

namespace App\Services\Scheduling;

use App\Models\ScheduleRun;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\User;
use App\Services\PythonServiceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class SolverClientService
{
    private const STALE_RUN_AFTER_MINUTES = 5;

    public function __construct(
        protected CandidateBuilder $candidateBuilder,
        protected ConflictChecker $conflictChecker,
        protected SchedulingInputSnapshot $snapshots,
        protected SchedulingLockService $locks
    ) {}

    /**
     * Run the assignment solver for a supervision stage.
     */
    public function solve(SupervisionStage $stage, User $user, int $timeoutSeconds = 30): ScheduleRun
    {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 60) {
            throw ValidationException::withMessages(['timeout_seconds' => 'Batas waktu solver harus antara 1 dan 60 detik.']);
        }
        $run = new ScheduleRun(['supervision_stage_id' => $stage->id, 'created_by' => $user->id, 'status' => 'RUNNING', 'started_at' => now()]);
        try {
            return $this->solveRun($stage, $timeoutSeconds, $run);
        } catch (Throwable $exception) {
            if ($run->exists) {
                DB::transaction(function () use ($run): void {
                    $this->locks->state();
                    ScheduleRun::whereKey($run->id)->where('status', 'RUNNING')
                        ->update(['status' => 'FAILED', 'finished_at' => now(), 'error_code' => 'SOLVER_REQUEST_FAILED']);
                }, 3);
            }
            throw $exception;
        }
    }

    private function solveRun(SupervisionStage $stage, int $timeoutSeconds, ScheduleRun $run): ScheduleRun
    {
        $connection = app(PythonServiceConnection::class);
        $client = $connection->client();
        $revision = DB::transaction(function () use ($stage, $run): int {
            $state = $this->locks->state();
            $lockedStage = $this->locks->stage($stage->id);
            $period = $lockedStage->supervisionPeriod;
            $recoveredRuns = ScheduleRun::whereHas('stage', fn ($query) => $query->where('supervision_period_id', $period->id))
                ->where('status', 'RUNNING')
                ->where('started_at', '<', now()->subMinutes(self::STALE_RUN_AFTER_MINUTES))
                ->update([
                    'status' => 'FAILED',
                    'finished_at' => now(),
                    'error_code' => 'STALE_RUN_RECOVERED',
                    'error_message' => 'Proses solver tidak selesai dan dipulihkan secara otomatis.',
                ]);
            if ($recoveredRuns > 0) {
                Log::warning('Recovered interrupted solver runs', ['period_id' => $period->id, 'count' => $recoveredRuns]);
            }
            if (ScheduleRun::whereHas('stage', fn ($query) => $query->where('supervision_period_id', $period->id))
                ->where('status', 'RUNNING')->exists()) {
                throw new ConflictHttpException('Penyusunan usulan pada periode ini masih berjalan. Tunggu hingga proses selesai.');
            }
            $previousRuleId = $period->active_scheduling_rule_id;
            $run->setRelation('stage', $lockedStage);
            $run->prepareSchedulingRule();
            if ($period->active_scheduling_rule_id !== $previousRuleId) {
                $this->locks->bump($state);
            }

            $run->input_revision = $state->revision;
            $run->save();

            return $state->revision;
        }, 3);
        $stage = $stage->fresh(['supervisionPeriod.stages']);
        $preparationStartedAt = microtime(true);
        Log::info('Solver input preparation started', ['run_id' => $run->id, 'stage_id' => $stage->id]);
        $sources = $this->snapshots->capture($stage);
        Log::info('Solver input snapshot ready', [
            'run_id' => $run->id,
            'elapsed_ms' => (int) ((microtime(true) - $preparationStartedAt) * 1000),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ]);
        $pool = $this->candidateBuilder->build($stage);
        Log::info('Solver candidate pool ready', [
            'run_id' => $run->id,
            'candidates' => count($pool['candidates']),
            'elapsed_ms' => (int) ((microtime(true) - $preparationStartedAt) * 1000),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ]);

        // Fetch existing locked assignments (MANUAL or FINAL) to ensure stability
        $lockedAssignments = SupervisionAssignment::where('supervision_stage_id', $stage->id)
            ->whereIn('assignment_type', ['MANUAL', 'LOCKED'])
            ->where('status', '<>', 'CANCELLED')
            ->with('teachingOccurrence.teachingSchedule')
            ->orderBy('id')->get();

        $payload = [
            'stage_id' => $stage->id,
            'timeout_seconds' => $timeoutSeconds,
            'targets' => $pool['targets'],
            'supervisors' => $pool['supervisors'],
            'candidates' => $pool['candidates'],
            'locked_assignments' => $lockedAssignments->map(fn ($a) => [
                'target_lecturer_id' => $a->target_lecturer_id,
                'supervisor_lecturer_id' => $a->supervisor_lecturer_id,
                'occurrence_id' => $a->teaching_occurrence_id,
                'schedule_identity' => $a->teachingOccurrence?->teachingSchedule?->supervisionIdentity(),
            ])->toArray(),
        ];

        $run->fill([
            'input_revision' => $revision,
            'input_payload' => ['sources' => $sources, 'solver' => $payload],
            'input_hash' => $this->snapshots->hash(['sources' => $sources, 'solver' => $payload]),
        ]);
        DB::transaction(function () use ($run, $revision): void {
            if ($this->locks->state()->revision !== $revision) {
                throw new ConflictHttpException('Usulan sudah kedaluwarsa karena data penjadwalan berubah. Buat usulan baru.');
            }
            if (ScheduleRun::whereKey($run->id)->lockForUpdate()->value('status') !== 'RUNNING') {
                throw new ConflictHttpException('Proses penjadwalan sebelumnya telah dihentikan. Buat usulan baru.');
            }
            $run->save();
        }, 3);

        $solvedExternally = false;

        try {
            $response = $client->connectTimeout(1)->timeout($timeoutSeconds + 5)->post('/v1/solve-assignments', $payload);
            $connection->assertAuthenticated($response);
            if ($response->status() === 413 || ($response->status() === 503 && $response->json('detail.code') === 'RESOURCE_BUSY')) {
                throw ValidationException::withMessages(['solver' => 'Service penjadwalan sedang sibuk atau input melebihi batas pemrosesan. Coba kembali dengan input yang sesuai.']);
            }

            if ($response->successful()) {
                $data = $response->json();
                $run->status = $data['status']; // OPTIMAL, FEASIBLE, INFEASIBLE
                $run->execution_duration_ms = $data['duration_ms'] ?? 0;
                $run->diagnostics_json = $data['diagnostics'] ?? [];
                $run->proposed_assignments = $data['assignments'] ?? [];
                $run->target_coverage_rate = $data['coverage_rate'] ?? 0.0;
                $solvedExternally = true;
            }
        } catch (ConnectionException $e) {
            Log::info('Python solver unavailable; using internal constraint engine', ['exception_class' => $e::class]);
        } catch (Throwable $exception) {
            DB::transaction(function () use ($run): void {
                $this->locks->state();
                $run->update(['status' => 'FAILED', 'finished_at' => now(), 'error_code' => 'SOLVER_REQUEST_FAILED']);
            }, 3);

            throw $exception;
        }

        if (! $solvedExternally) {
            // Internal deterministic constraint scheduler
            $internalResult = $this->solveInternally($pool, $lockedAssignments);
            $run->status = $internalResult['status'];
            $run->execution_duration_ms = $internalResult['duration_ms'];
            $run->diagnostics_json = $internalResult['diagnostics'];
            $run->proposed_assignments = $internalResult['assignments'];
            $run->target_coverage_rate = $internalResult['coverage_rate'];
        }

        $run->completed_at = now();
        DB::transaction(function () use ($run, $revision, $stage, $sources): void {
            $state = $this->locks->state();
            if (ScheduleRun::whereKey($run->id)->lockForUpdate()->value('status') !== 'RUNNING') {
                throw new ConflictHttpException('Proses penjadwalan sebelumnya telah dihentikan. Buat usulan baru.');
            }
            $freshSources = $this->snapshots->capture($stage);
            if ($state->revision !== $revision || $this->snapshots->hash($freshSources) !== $this->snapshots->hash($sources)) {
                $run->status = 'STALE';
            }
            $run->save();
        }, 3);

        return $run;
    }

    /**
     * Fallback internal deterministic constraint scheduler adhering strictly to H01-H08.
     * Guarantees zero time-overlap for any supervisor on the same date.
     */
    protected function solveInternally(array $pool, Collection $lockedAssignments): array
    {
        $startedAt = microtime(true);
        $assignedTargets = [];
        $proposed = [];
        $supervisorCounts = [];
        $supervisorDailyCounts = [];
        $supervisorSlotCounts = [];
        $diagnostics = [];

        foreach ($pool['supervisors'] as $s) {
            $supervisorCounts[$s['lecturer_id']] = 0;
            $supervisorDailyCounts[$s['lecturer_id']] = [];
            $supervisorSlotCounts[$s['lecturer_id']] = [];
        }

        // 1. Honor locked assignments first
        foreach ($lockedAssignments as $la) {
            $assignedTargets[$la->target_lecturer_id] = true;
            $supId = $la->supervisor_lecturer_id;
            $supervisorCounts[$supId] = ($supervisorCounts[$supId] ?? 0) + 1;

            $occ = $la->teachingOccurrence ?? TeachingOccurrence::find($la->teaching_occurrence_id);
            $date = $occ ? $occ->occurrence_date->toDateString() : ($la->starts_at ? $la->starts_at->toDateString() : null);
            $startTime = $occ ? $occ->start_time_local : ($la->starts_at ? $la->starts_at->format('H:i') : '08:00');
            $endTime = $occ ? $occ->end_time_local : ($la->ends_at ? $la->ends_at->format('H:i') : '09:40');
            $dayOfWeek = $occ ? ($occ->teachingSchedule?->day_of_week ?? (int) $occ->occurs_on->format('N')) : ($la->starts_at ? (int) $la->starts_at->format('N') : 1);

            if ($date) {
                $supervisorDailyCounts[$supId][$date] = ($supervisorDailyCounts[$supId][$date] ?? 0) + 1;
            }
            $slotKey = "{$dayOfWeek}_".substr($startTime, 0, 5);
            $supervisorSlotCounts[$supId][$slotKey] = ($supervisorSlotCounts[$supId][$slotKey] ?? 0) + 1;

            $proposed[] = [
                'target_lecturer_id' => $la->target_lecturer_id,
                'supervisor_lecturer_id' => $supId,
                'occurrence_id' => $la->teaching_occurrence_id,
                'assignment_type' => $la->assignment_type,
                'schedule_identity' => $occ?->teachingSchedule?->supervisionIdentity(),
                'date' => $date,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ];
        }

        // Group candidate options by target
        $candidatesByTarget = [];
        foreach ($pool['candidates'] as $cand) {
            $candidatesByTarget[$cand['target_lecturer_id']][] = $cand;
        }

        $orderedTargets = collect($pool['targets'])->sortBy(function (array $target) use ($candidatesByTarget): array {
            $options = $candidatesByTarget[$target['lecturer_id']] ?? [];
            $offlineCount = count(array_filter($options, fn (array $option): bool => SupervisionModePriority::rank($option['delivery_mode'] ?? null) === 0));

            return [count($options), $offlineCount > 0 ? 0 : 1, $target['lecturer_id']];
        })->values()->all();

        foreach ($orderedTargets as $target) {
            $targetId = $target['lecturer_id'];
            if (isset($assignedTargets[$targetId])) {
                continue;
            }

            $options = $candidatesByTarget[$targetId] ?? [];
            if (empty($options)) {
                $diagnostics[] = "Target #{$targetId} ({$target['name']}) tidak memiliki slot jadwal mengajar yang valid di tahap ini.";

                continue;
            }

            usort($options, function (array $a, array $b) use ($supervisorCounts, $supervisorDailyCounts, $supervisorSlotCounts): int {
                $modeOrder = SupervisionModePriority::rank($a['delivery_mode'] ?? null) <=> SupervisionModePriority::rank($b['delivery_mode'] ?? null);
                if ($modeOrder !== 0) {
                    return $modeOrder;
                }
                $supA = $a['supervisor_lecturer_id'];
                $supB = $b['supervisor_lecturer_id'];

                $loadA = $supervisorCounts[$supA] ?? 0;
                $loadB = $supervisorCounts[$supB] ?? 0;
                if ($loadA !== $loadB) {
                    return $loadA <=> $loadB;
                }

                $dateA = $a['date'] ?? '';
                $dateB = $b['date'] ?? '';
                $dateLoadA = $supervisorDailyCounts[$supA][$dateA] ?? 0;
                $dateLoadB = $supervisorDailyCounts[$supB][$dateB] ?? 0;
                if ($dateLoadA !== $dateLoadB) {
                    return $dateLoadA <=> $dateLoadB;
                }

                $slotA = ($a['day_of_week'] ?? '').'_'.substr($a['start_time'] ?? '', 0, 5);
                $slotB = ($b['day_of_week'] ?? '').'_'.substr($b['start_time'] ?? '', 0, 5);
                $slotLoadA = $supervisorSlotCounts[$supA][$slotA] ?? 0;
                $slotLoadB = $supervisorSlotCounts[$supB][$slotB] ?? 0;
                if ($slotLoadA !== $slotLoadB) {
                    return $slotLoadA <=> $slotLoadB;
                }

                return strcmp($dateA, $dateB);
            });

            $selected = null;
            foreach ($options as $opt) {
                $conflict = false;
                $supId = $opt['supervisor_lecturer_id'];

                foreach ($proposed as $prop) {
                    if ($prop['supervisor_lecturer_id'] !== $supId) {
                        continue;
                    }

                    // 1. Same occurrence check
                    if ($prop['occurrence_id'] === $opt['occurrence_id']) {
                        $conflict = true;
                        break;
                    }

                    // 2. Strict Date & Time Overlap check!
                    // A supervisor CANNOT observe multiple classes at the same time or with < 10 mins buffer
                    if (! empty($prop['date']) && ! empty($opt['date']) && $prop['date'] === $opt['date']) {
                        if ($this->intervalsOverlap($opt['start_time'], $opt['end_time'], $prop['start_time'], $prop['end_time'], 10)) {
                            $conflict = true;
                            break;
                        }
                    }
                }

                if (! $conflict) {
                    $selected = $opt;
                    break;
                }
            }

            if ($selected) {
                $assignedTargets[$targetId] = true;
                $supId = $selected['supervisor_lecturer_id'];
                $supervisorCounts[$supId] = ($supervisorCounts[$supId] ?? 0) + 1;
                $dateKey = $selected['date'] ?? null;
                if ($dateKey) {
                    $supervisorDailyCounts[$supId][$dateKey] = ($supervisorDailyCounts[$supId][$dateKey] ?? 0) + 1;
                }
                $slotKey = ($selected['day_of_week'] ?? '').'_'.substr($selected['start_time'] ?? '', 0, 5);
                $supervisorSlotCounts[$supId][$slotKey] = ($supervisorSlotCounts[$supId][$slotKey] ?? 0) + 1;

                $proposed[] = [
                    'target_lecturer_id' => $targetId,
                    'supervisor_lecturer_id' => $supId,
                    'occurrence_id' => $selected['occurrence_id'],
                    'assignment_type' => 'AUTO',
                    'schedule_identity' => $selected['schedule_identity'],
                    'date' => $selected['date'] ?? null,
                    'start_time' => $selected['start_time'] ?? null,
                    'end_time' => $selected['end_time'] ?? null,
                ];
            } else {
                $diagnostics[] = "Tidak ditemukan supervisor tanpa bentrok untuk target {$target['name']}.";
            }
        }

        $totalTargets = count($pool['targets']);
        $assignedCount = count($assignedTargets);
        $coverage = $totalTargets > 0 ? round(($assignedCount / $totalTargets) * 100, 2) : 0.0;

        $status = 'FEASIBLE';
        if ($assignedCount === $totalTargets && $totalTargets > 0) {
            $status = 'OPTIMAL';
        } elseif ($assignedCount === 0 && $totalTargets > 0) {
            $status = 'INFEASIBLE';
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'status' => $status,
            'duration_ms' => $durationMs,
            'coverage_rate' => $coverage,
            'assignments' => $proposed,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Check if two time intervals overlap (with an optional buffer in minutes).
     */
    protected function intervalsOverlap(string $startA, string $endA, string $startB, string $endB, int $bufferMinutes = 0): bool
    {
        $a1 = $this->timeToMinutes($startA);
        $a2 = $this->timeToMinutes($endA);
        $b1 = $this->timeToMinutes($startB);
        $b2 = $this->timeToMinutes($endB);

        return ($a1 - $bufferMinutes < $b2) && ($b1 - $bufferMinutes < $a2);
    }

    protected function timeToMinutes(string $time): int
    {
        $parts = explode(':', substr(trim($time), 0, 5));

        return ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 0);
    }
}
