<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionEvidence;
use App\Services\Supervision\EvidenceService;
use App\Services\Supervision\SubmitService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SupervisionEvidenceConcurrencyTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTruncation;

    private Supervision $supervision;

    private Question $fileQuestion;

    private Question $otherFileQuestion;

    private SupervisionEvidence $evidence;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
        $this->assertSame(0, DB::transactionLevel());
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sisd-evidence-verification-'.Str::uuid();
        mkdir($this->directory);
        mkdir($this->directory.DIRECTORY_SEPARATOR.'files');
        config(['filesystems.disks.local.root' => $this->directory.DIRECTORY_SEPARATOR.'files']);
        Storage::forgetDisk('local');
        $section = $this->instrument->formTemplate->sections()->firstOrFail();
        $this->fileQuestion = $section->questions()->create([
            'form_template_id' => $this->instrument->form_template_id, 'code' => 'FILE',
            'question_text' => 'Bukti wajib', 'question_type' => 'FILE',
            'is_required' => true, 'is_scored' => false, 'sort_order' => 2,
        ]);
        $this->otherFileQuestion = $section->questions()->create([
            'form_template_id' => $this->instrument->form_template_id, 'code' => 'OTHER-FILE',
            'question_text' => 'Bukti tambahan', 'question_type' => 'FILE',
            'is_required' => false, 'is_scored' => false, 'sort_order' => 3,
        ]);
        $this->stage->update(['status' => 'FINALIZED']);
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'status' => 'FINAL', 'assignment_type' => 'MANUAL',
        ]);
        $this->supervision = Supervision::create([
            'supervision_assignment_id' => $assignment->id, 'stage_instrument_id' => $this->instrument->id,
            'form_template_id' => $this->instrument->form_template_id, 'scoring_policy_id' => $this->instrument->scoring_policy_id,
            'status' => 'DRAFT', 'row_version' => 1,
        ]);
        $this->answerSupervision($this->supervision);
        $this->actingAs($this->supervisorUser);
        $this->evidence = $this->upload();
    }

    protected function tearDown(): void
    {
        try {
            $this->truncateTablesForAllConnections();
        } finally {
            if (isset($this->directory) && is_dir($this->directory)) {
                $resolved = realpath($this->directory);
                if (is_string($resolved) && str_starts_with($resolved,
                    realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'sisd-evidence-verification-')) {
                    $this->app['files']->deleteDirectory($resolved);
                }
            }
            parent::tearDown();
        }
    }

    private function upload(?Question $question = null, int $kilobytes = 1): SupervisionEvidence
    {
        return app(EvidenceService::class)->storeEvidence($this->supervision,
            UploadedFile::fake()->image('fixture.jpg')->size($kilobytes), ($question ?? $this->fileQuestion)->id);
    }

    /**
     * Hold the supervision row while workers wait on the scheduling mutex or the parent row.
     * Both processes must visibly wait for SELECT FOR UPDATE before the parent releases its transaction.
     *
     * @param  list<array<string, int|string>>  $jobs
     * @return list<array<string, mixed>>
     */
    private function runParallel(array $jobs): array
    {
        $connection = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => 'sisd_teknokrat_test', 'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'], 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array'];
        $processes = [];
        $release = $this->directory.DIRECTORY_SEPARATOR.'release';
        DB::beginTransaction();
        try {
            Supervision::whereKey($this->supervision->id)->lockForUpdate()->firstOrFail();
            foreach ($jobs as $index => $job) {
                $job += ['actor_id' => $this->supervisorUser->id, 'supervision_id' => $this->supervision->id,
                    'question_id' => $this->fileQuestion->id, 'row_version' => $this->supervision->fresh()->row_version,
                    'storage_root' => $this->directory.DIRECTORY_SEPARATOR.'files',
                    'ready_path' => $this->directory.DIRECTORY_SEPARATOR.'ready-'.$index, 'release_path' => $release];
                $process = new Process([PHP_BINARY, base_path('tests/Feature/SupervisionEvidenceConcurrencyWorker.php'),
                    base64_encode(json_encode($job, JSON_THROW_ON_ERROR))], base_path(), $environment);
                $process->setTimeout(25)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 8;
            while (count(glob($this->directory.DIRECTORY_SEPARATOR.'ready-*')) !== count($jobs)) {
                $this->assertLessThan($deadline, microtime(true), 'Workers did not reach the evidence barrier.');
                usleep(10000);
            }
            file_put_contents($release, 'release');
            $deadline = microtime(true) + 5;
            do {
                $waiting = (int) DB::scalar("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = ? AND ID <> CONNECTION_ID() AND INFO LIKE '%for update%' AND (INFO LIKE '%scheduling_state%' OR INFO LIKE '%from `supervisions`%')", ['sisd_teknokrat_test']);
                if ($waiting === count($jobs)) {
                    break;
                }
                $this->assertLessThan($deadline, microtime(true), 'Both workers must wait on a scheduling or supervision lock.');
                usleep(10000);
            } while (true);
            $this->assertSame(count($jobs), $waiting);
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $exit = $process->wait();
                $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame(0, $exit, 'Worker failed: '.($result['class'] ?? 'unknown'));
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
        }
    }

    private function assertFilesMatchRecords(): void
    {
        $paths = $this->supervision->evidence()->pluck('file_path')->sort()->values()->all();
        $files = collect(Storage::disk('local')->allFiles('private/evidence'))->sort()->values()->all();
        $this->assertSame($paths, $files, 'Every file must have a committed evidence record.');
        foreach ($this->supervision->evidence()->get() as $evidence) {
            $this->assertTrue($evidence->isReady());
            $this->assertSame(hash_file('sha256', Storage::disk('local')->path($evidence->file_path)), $evidence->sha256);
        }
    }

    public function test_upload_and_draft_share_parent_lock_and_reject_a_stale_draft(): void
    {
        $version = $this->supervision->fresh()->row_version;
        $results = $this->runParallel([['operation' => 'upload'], ['operation' => 'draft']]);
        $this->assertSame('ok', $results[0]['result']);
        $this->assertContains($results[1]['result'], ['ok', 'version_conflict']);
        $this->assertSame($version + ($results[1]['result'] === 'ok' ? 2 : 1), $this->supervision->fresh()->row_version);
        $this->assertSame(2, $this->supervision->evidence()->count());
        $this->assertSame(1, $this->supervision->answers()->where('question_id', $this->fileQuestion->id)->count());
        $answer = $this->supervision->answers()->where('question_id', $this->fileQuestion->id)->firstOrFail();
        $this->assertSame($results[1]['result'] === 'ok' ? 'Catatan paralel.' : null, $answer->notes);
        $this->assertFilesMatchRecords();
    }

    public function test_delete_and_review_use_one_consistent_version_of_required_evidence(): void
    {
        $version = $this->supervision->fresh()->row_version;
        $results = $this->runParallel([['operation' => 'delete', 'evidence_id' => $this->evidence->id], ['operation' => 'review']]);
        $this->assertSame('ok', $results[0]['result']);
        $this->assertContains($results[1]['result'], ['ok', 'validation']);
        if ($results[1]['result'] === 'ok') {
            $this->assertSame($version, $results[1]['value']['row_version']);
            try {
                app(SubmitService::class)->submit($this->supervision, $this->supervisorUser, $version);
                $this->fail('Review completed before deletion must now be stale.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('review', $exception->errors());
            }
        } else {
            $this->assertContains('form', $results[1]['fields']);
        }
        $this->assertSame($version + 1, $this->supervision->fresh()->row_version);
        $this->assertSame('DRAFT', $this->supervision->fresh()->status);
        $this->assertNull($this->supervision->fresh()->snapshot_json);
        $this->assertSame(0, $this->supervision->evidence()->count());
        $this->assertFilesMatchRecords();
    }

    #[TestWith(['upload'])]
    #[TestWith(['delete'])]
    public function test_evidence_and_submit_cannot_publish_partial_or_later_mutated_evidence(string $operation): void
    {
        $version = app(SubmitService::class)->preview($this->supervision)['row_version'];
        $results = $this->runParallel([
            ['operation' => $operation, 'evidence_id' => $this->evidence->id],
            ['operation' => 'submit', 'row_version' => $version],
        ]);
        $fresh = $this->supervision->fresh();
        if ($results[1]['result'] === 'ok') {
            $this->assertSame('authorization', $results[0]['result']);
            $this->assertSame('SUBMITTED', $fresh->status);
            $this->assertSame($version + 1, $fresh->row_version);
            $this->assertSame([$this->evidence->id], array_column($fresh->snapshot_json['evidence'], 'id'));
            $this->assertSame(1, $this->supervision->evidence()->count());
        } else {
            $this->assertSame('ok', $results[0]['result']);
            $this->assertSame('validation', $results[1]['result']);
            $this->assertContains('review', $results[1]['fields']);
            $this->assertSame('DRAFT', $fresh->status);
            $this->assertNull($fresh->snapshot_json);
            $this->assertSame($version + 1, $fresh->row_version);
            $this->assertSame($operation === 'upload' ? 2 : 0, $this->supervision->evidence()->count());
        }
        $this->assertFilesMatchRecords();
    }

    public function test_two_uploads_to_one_question_preserve_both_files_and_one_answer(): void
    {
        $version = $this->supervision->fresh()->row_version;
        $results = $this->runParallel([['operation' => 'upload'], ['operation' => 'upload']]);
        $this->assertSame(['ok', 'ok'], array_column($results, 'result'));
        $this->assertNotSame($results[0]['value'], $results[1]['value']);
        $this->assertSame($version + 2, $this->supervision->fresh()->row_version);
        $this->assertSame(3, $this->supervision->evidence()->count());
        $this->assertSame(1, $this->supervision->answers()->where('question_id', $this->fileQuestion->id)->count());
        $this->assertFilesMatchRecords();
    }

    public function test_parallel_uploads_cannot_exceed_the_question_file_quota(): void
    {
        $this->upload();
        $version = $this->supervision->fresh()->row_version;
        $results = $this->runParallel([['operation' => 'upload'], ['operation' => 'upload']]);
        $statuses = array_column($results, 'result');
        sort($statuses);
        $this->assertSame(['ok', 'validation'], $statuses);
        $this->assertSame($version + 1, $this->supervision->fresh()->row_version);
        $this->assertSame(3, $this->supervision->evidence()->count());
        $this->assertFilesMatchRecords();
    }

    public function test_parallel_uploads_cannot_exceed_the_form_byte_quota(): void
    {
        app(EvidenceService::class)->deleteEvidence($this->evidence);
        for ($index = 0; $index < 3; $index++) {
            $this->upload($this->otherFileQuestion, 5 * 1024);
        }
        $version = $this->supervision->fresh()->row_version;
        $results = $this->runParallel([['operation' => 'upload', 'kilobytes' => 3 * 1024], ['operation' => 'upload', 'kilobytes' => 3 * 1024]]);
        $statuses = array_column($results, 'result');
        sort($statuses);
        $this->assertSame(['ok', 'validation'], $statuses);
        $this->assertSame($version + 1, $this->supervision->fresh()->row_version);
        $this->assertSame(18 * 1024 * 1024, (int) $this->supervision->evidence()->sum('size_bytes'));
        $this->assertSame(4, $this->supervision->evidence()->count());
        $this->assertFilesMatchRecords();
    }
}
