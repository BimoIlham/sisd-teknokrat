<?php

namespace Tests\Feature;

use App\Models\GeneratedDocument;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Models\TeachingOccurrence;
use App\Models\TeachingSchedule;
use App\Services\Scheduling\ManualOverrideService;
use App\Services\Scheduling\SchedulingLockService;
use App\Services\Scheduling\SolverClientService;
use App\Services\Scheduling\StageFinalizationService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SchedulingConcurrencyTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
        $this->assertSame(0, DB::transactionLevel(), 'Parallel workers need committed fixtures.');
    }

    protected function tearDown(): void
    {
        try {
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Hold the actual MySQL mutex until both independent worker connections are waiting on it.
     * The test checks server process state, avoiding timing-only assumptions about overlap.
     *
     * @param  list<array<string, int|string>>  $jobs
     * @return list<array<string, mixed>>
     */
    private function runParallel(array $jobs): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sisd-concurrency-'.Str::uuid();
        mkdir($directory);
        $processes = [];
        $files = [];
        $releasePath = $directory.DIRECTORY_SEPARATOR.'release';
        $files[] = $releasePath;
        $connection = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => 'sisd_teknokrat_test', 'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'], 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array'];
        DB::beginTransaction();
        try {
            app(SchedulingLockService::class)->state();
            foreach ($jobs as $index => $job) {
                $ready = $directory.DIRECTORY_SEPARATOR.'ready-'.$index;
                $attempt = $directory.DIRECTORY_SEPARATOR.'attempt-'.$index;
                $files[] = $ready;
                $files[] = $attempt;
                $job += ['actor_id' => $this->admin->id, 'ready_path' => $ready, 'attempt_path' => $attempt, 'release_path' => $releasePath];
                $process = new Process([PHP_BINARY, base_path('tests/Feature/SchedulingConcurrencyWorker.php'),
                    base64_encode(json_encode($job, JSON_THROW_ON_ERROR))], base_path(), $environment);
                $process->setTimeout(20)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 5;
            while (count(glob($directory.DIRECTORY_SEPARATOR.'ready-*')) !== count($jobs)) {
                $this->assertLessThan($deadline, microtime(true), 'Workers did not reach the concurrency barrier.');
                usleep(10000);
            }
            file_put_contents($releasePath, 'release');
            $deadline = microtime(true) + 3;
            do {
                $waiting = (int) DB::scalar("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = ? AND ID <> CONNECTION_ID() AND INFO LIKE '%scheduling_state%' AND INFO LIKE '%for update%'", ['sisd_teknokrat_test']);
                if ($waiting === count($jobs)) {
                    break;
                }
                $this->assertLessThan($deadline, microtime(true), 'Both MySQL connections must be waiting on the scheduling mutex.');
                usleep(10000);
            } while (true);
            $this->assertSame(count($jobs), $waiting);
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), 'Concurrency worker failed; inspect its controlled result.');
                $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $this->assertNotSame('error', $result['result']);
                $results[] = $result;
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }

    public function test_real_parallel_period_activation_leaves_one_active_period(): void
    {
        $this->period->update(['status' => 'CLOSED']);
        $other = $this->period->replicate();
        $other->name = 'Periode Paralel';
        $other->save();
        $results = $this->runParallel([
            ['operation' => 'activate', 'period_id' => $this->period->id],
            ['operation' => 'activate', 'period_id' => $other->id],
        ]);
        $this->assertSame(['ok', 'ok'], array_column($results, 'result'));
        $this->assertSame(1, SupervisionPeriod::where('status', 'ACTIVE')->count());
    }

    public function test_real_parallel_letter_allocation_keeps_numbers_unique_with_existing_format(): void
    {
        app(ManualOverrideService::class)->assign($this->stage, $this->target->id, $this->supervisor->id, $this->occurrence->id, $this->admin);
        foreach ([$this->stage, $this->secondStage] as $stage) {
            $stage->update(['status' => 'FINALIZED', 'finalized_at' => now(), 'finalized_by' => $this->admin->id]);
            $stage->assignments()->update(['status' => 'FINAL']);
        }
        $this->runParallel([
            ['operation' => 'letter_command', 'stage_id' => $this->stage->id],
            ['operation' => 'letter', 'stage_id' => $this->secondStage->id],
        ]);
        $numbers = GeneratedDocument::where('supervision_period_id', $this->period->id)->pluck('document_number');
        $this->assertCount(2, $numbers);
        $this->assertCount(2, $numbers->unique());
        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression('~^001/LPM-UTI/X/2030(?:/T[12])?$~', $number);
        }
    }

    public function test_real_parallel_import_commit_processes_each_row_only_once(): void
    {
        $import = $this->createSchedulingImport();
        $results = $this->runParallel([
            ['operation' => 'import', 'import_id' => $import->id],
            ['operation' => 'import', 'import_id' => $import->id],
        ]);
        $counts = array_column($results, 'count');
        sort($counts);
        $this->assertSame([0, 1], $counts);
        $this->assertSame('COMMITTED', $import->fresh()->status);
        $this->assertSame(1, TeachingSchedule::where('source_import_id', $import->id)->count());
        $scheduleId = TeachingSchedule::where('source_import_id', $import->id)->value('id');
        $occurrences = TeachingOccurrence::where('teaching_schedule_id', $scheduleId)->get();
        $this->assertNotEmpty($occurrences);
        $this->assertSame($occurrences->count(), $occurrences->pluck('original_date')->unique()->count());
    }

    public function test_real_parallel_manual_assignments_cannot_store_overlapping_pairs(): void
    {
        $other = $this->createSchedulingTarget('Dosen Paralel');
        $otherOccurrence = $other->teachingSchedules()->first()->occurrences()->where('occurs_on', '2030-10-07')->first();
        $results = $this->runParallel([
            ['operation' => 'manual', 'stage_id' => $this->stage->id, 'target_id' => $this->target->id,
                'supervisor_id' => $this->supervisor->id, 'occurrence_id' => $this->occurrence->id],
            ['operation' => 'manual', 'stage_id' => $this->stage->id, 'target_id' => $other->id,
                'supervisor_id' => $this->supervisor->id, 'occurrence_id' => $otherOccurrence->id],
        ]);
        $statuses = array_column($results, 'result');
        sort($statuses);
        $this->assertSame(['ok', 'validation_conflict'], $statuses);
        $this->assertSame(1, SupervisionAssignment::where('supervision_stage_id', $this->stage->id)->count());
        $this->assertSame(1, SupervisionAssignment::where('supervision_stage_id', $this->secondStage->id)->count());
    }

    public function test_real_parallel_finalization_from_different_stages_is_atomic(): void
    {
        app(ManualOverrideService::class)->assign($this->stage, $this->target->id, $this->supervisor->id, $this->occurrence->id, $this->admin);
        $results = $this->runParallel([
            ['operation' => 'finalize', 'stage_id' => $this->stage->id],
            ['operation' => 'finalize', 'stage_id' => $this->secondStage->id],
        ]);
        $this->assertSame(['ok', 'ok'], array_column($results, 'result'));
        $this->assertSame(['FINALIZED'], $this->period->stages()->pluck('status')->unique()->values()->all());
        $this->assertSame(['FINAL'], SupervisionAssignment::whereIn('supervision_stage_id', [$this->stage->id, $this->secondStage->id])->pluck('status')->unique()->values()->all());
        $this->assertSame(2, GeneratedDocument::where('supervision_period_id', $this->period->id)->count());
    }

    public function test_real_parallel_submit_and_reopen_cannot_create_a_submitted_draft_assignment(): void
    {
        $assignment = app(ManualOverrideService::class)->assign($this->stage, $this->target->id, $this->supervisor->id, $this->occurrence->id, $this->admin);
        app(StageFinalizationService::class)->finalize($this->stage, $this->admin);
        $supervision = Supervision::where('supervision_assignment_id', $assignment->id)->firstOrFail();
        $this->answerSupervision($supervision);
        $results = $this->runParallel([
            ['operation' => 'submit', 'supervision_id' => $supervision->id, 'actor_id' => $this->supervisorUser->id, 'row_version' => $supervision->row_version],
            ['operation' => 'reopen', 'stage_id' => $this->secondStage->id],
        ]);
        $statuses = array_column($results, 'result');
        $this->assertContains('ok', $statuses);
        $this->assertCount(1, array_filter($statuses, fn (string $status): bool => $status !== 'ok'));
        if ($supervision->fresh()->status === 'SUBMITTED') {
            $this->assertSame('FINAL', $assignment->fresh()->status);
            $this->assertSame('FINALIZED', $this->stage->fresh()->status);
            $this->assertSame('3.000000', $supervision->fresh()->final_score);
            $this->assertSame(2, $supervision->fresh()->row_version);
        } else {
            $this->assertSame('DRAFT', $assignment->fresh()->status);
            $this->assertSame('DRAFT', $this->stage->fresh()->status);
            $this->assertSame(1, $supervision->fresh()->row_version);
        }
    }

    public function test_real_mysql_mutex_is_released_before_solver_http_and_mid_run_changes_become_stale(): void
    {
        config(['database.connections.scheduling_probe' => config('database.connections.mysql')]);
        Http::fake(function () {
            $this->assertSame(0, DB::transactionLevel());
            $probe = DB::connection('scheduling_probe');
            $this->assertSame('sisd_teknokrat_test', $probe->getDatabaseName());
            $this->assertNotSame(DB::scalar('SELECT CONNECTION_ID()'), $probe->scalar('SELECT CONNECTION_ID()'));
            $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $probe->transaction(function () use ($probe): void {
                $state = $probe->table('scheduling_state')->where('id', 1)->lockForUpdate()->first();
                $this->assertSame(10, $state->revision);
                $probe->table('scheduling_state')->where('id', 1)->update(['revision' => 11]);
            });

            return Http::response([], 503);
        });
        try {
            $run = app(SolverClientService::class)->solve($this->stage, $this->admin);
            $this->assertSame(10, $run->input_revision);
            $this->assertSame('STALE', $run->fresh()->status);
        } finally {
            DB::purge('scheduling_probe');
        }
    }
}
