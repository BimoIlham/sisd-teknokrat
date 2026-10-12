<?php

use App\Models\Supervision;
use App\Models\SupervisionEvidence;
use App\Models\User;
use App\Services\Supervision\DraftService;
use App\Services\Supervision\EvidenceService;
use App\Services\Supervision\SubmitService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
if (! $application->environment('testing') || config('database.default') !== 'mysql'
    || DB::connection()->getDatabaseName() !== 'sisd_teknokrat_test') {
    fwrite(STDERR, 'Refusing evidence test outside the isolated testing database.');
    exit(2);
}

try {
    $job = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
    $temporaryRoot = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR;
    $storageRoot = realpath($job['storage_root']);
    if (! is_string($storageRoot) || ! str_starts_with($storageRoot, $temporaryRoot.'sisd-evidence-verification-')) {
        throw new RuntimeException('Evidence worker requires isolated temporary storage.');
    }
    config(['filesystems.disks.local.root' => $storageRoot]);
    Carbon::setTestNow(Carbon::parse('2030-10-07 10:00:00', 'Asia/Jakarta'));
    $user = User::findOrFail($job['actor_id']);
    Auth::login($user);
    $supervision = Supervision::with('assignment.supervisionStage.period', 'answers.evidence')->findOrFail($job['supervision_id']);
    $evidence = isset($job['evidence_id']) ? SupervisionEvidence::with('answer.supervision')->findOrFail($job['evidence_id']) : null;
    DB::statement('SET SESSION innodb_lock_wait_timeout = 8');
    file_put_contents($job['ready_path'], 'ready');
    $deadline = microtime(true) + 10;
    while (! is_file($job['release_path'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Evidence concurrency barrier timed out.');
        }
        usleep(10000);
    }
    $value = match ($job['operation']) {
        'upload' => app(EvidenceService::class)->storeEvidence($supervision,
            UploadedFile::fake()->image('parallel.jpg')->size($job['kilobytes'] ?? 1), $job['question_id'])->id,
        'delete' => app(EvidenceService::class)->deleteEvidence($evidence),
        'draft' => app(DraftService::class)->saveDraft($supervision, [], $job['row_version'],
            [$job['question_id'] => 'Catatan paralel.']),
        'review' => app(SubmitService::class)->preview($supervision),
        'submit' => app(SubmitService::class)->submit($supervision, $user, $job['row_version'])->id,
        default => throw new RuntimeException('Unknown evidence test operation.'),
    };
    echo json_encode(['result' => 'ok', 'value' => $value], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['result' => 'validation', 'fields' => array_keys($exception->errors())], JSON_THROW_ON_ERROR);
} catch (AuthorizationException) {
    echo json_encode(['result' => 'authorization'], JSON_THROW_ON_ERROR);
} catch (ConflictHttpException) {
    echo json_encode(['result' => 'version_conflict'], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['result' => 'error', 'class' => $exception::class], JSON_THROW_ON_ERROR);
    exit(1);
}
