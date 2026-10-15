<?php

namespace Tests\Feature;

use App\Models\Supervision;
use App\Models\SupervisionAnswer;
use App\Models\SupervisionAssignment;
use App\Services\InstrumentEditService;
use App\Services\Scheduling\SchedulingLockService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InstrumentHistoryConcurrencyTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        try {
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    private function worker(array $job): Process
    {
        $connection = config('database.connections.mysql');
        $process = new Process([PHP_BINARY, base_path('tests/Feature/InstrumentHistoryWorker.php'), base64_encode(json_encode($job, JSON_THROW_ON_ERROR))], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => 'sisd_teknokrat_test', 'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'], 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array',
        ]);
        $process->setTimeout(20)->start();

        return $process;
    }

    private function awaitBlockedWorkers(int $count): void
    {
        $deadline = microtime(true) + 8;
        do {
            $waiting = (int) DB::scalar("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = ? AND ID <> CONNECTION_ID() AND INFO LIKE '%scheduling_state%' AND INFO LIKE '%for update%'", ['sisd_teknokrat_test']);
            if ($waiting === $count) {
                $this->assertSame($count, $waiting);

                return;
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Worker must be blocked on the real MySQL mutex.');
            }
            usleep(10000);
        } while (true);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_unstarted_supervision_creation_does_not_block_editing_in_either_order(bool $creationFirst): void
    {
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'status' => 'DRAFT', 'assignment_type' => 'MANUAL',
        ]);
        $values = ['supervision_assignment_id' => $assignment->id, 'stage_instrument_id' => $this->instrument->id,
            'form_template_id' => $this->instrument->form_template_id, 'scoring_policy_id' => $this->instrument->scoring_policy_id,
            'status' => 'DRAFT', 'row_version' => 1];
        DB::beginTransaction();
        app(SchedulingLockService::class)->state();
        $worker = $this->worker(['operation' => $creationFirst ? 'edit' : 'create', 'template_id' => $this->instrument->form_template_id, 'values' => $values]);
        try {
            $this->awaitBlockedWorkers(1);
            if ($creationFirst) {
                Supervision::create($values);
            } else {
                app(InstrumentEditService::class)->lockEditable($this->instrument->form_template_id)
                    ->sections()->firstOrFail()->update(['title' => 'Edited before first use']);
            }
            DB::commit();
            $this->assertSame(0, $worker->wait());
            $result = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($creationFirst ? 'edited' : 'created', $result['result']);
            $this->assertSame(1, Supervision::where('form_template_id', $this->instrument->form_template_id)->count());
            $this->assertSame($creationFirst ? 'Edited concurrently' : 'Edited before first use', $this->instrument->formTemplate->sections()->firstOrFail()->title);
            if (! $creationFirst) {
                $this->assertSame('Edited before first use', $result['title']);
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_first_saved_answer_and_edit_are_serialized_in_both_orders(bool $answerFirst): void
    {
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'status' => 'DRAFT', 'assignment_type' => 'MANUAL',
        ]);
        $supervision = Supervision::create([
            'supervision_assignment_id' => $assignment->id, 'stage_instrument_id' => $this->instrument->id,
            'form_template_id' => $this->instrument->form_template_id, 'scoring_policy_id' => $this->instrument->scoring_policy_id,
            'status' => 'DRAFT', 'row_version' => 1,
        ]);
        $question = $this->instrument->formTemplate->questions()->firstOrFail();
        $option = $question->options()->firstOrFail();
        $job = ['operation' => $answerFirst ? 'edit' : 'answer', 'template_id' => $this->instrument->form_template_id,
            'supervision_id' => $supervision->id, 'question_id' => $question->id, 'option_id' => $option->id];

        DB::beginTransaction();
        app(SchedulingLockService::class)->state();
        $worker = $this->worker($job);
        try {
            $this->awaitBlockedWorkers(1);
            if ($answerFirst) {
                SupervisionAnswer::create([
                    'supervision_id' => $supervision->id, 'question_id' => $question->id,
                    'question_option_id' => $option->id,
                ]);
            } else {
                app(InstrumentEditService::class)->lockEditable($this->instrument->form_template_id)
                    ->sections()->firstOrFail()->update(['title' => 'Edited before first answer']);
            }
            DB::commit();

            $this->assertSame(0, $worker->wait());
            $result = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($answerFirst ? 'rejected' : 'answered', $result['result']);
            $this->assertSame(1, $supervision->answers()->count());
            $this->assertSame($answerFirst ? 'Pengamatan' : 'Edited before first answer', $question->section->fresh()->title);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
    }
}
