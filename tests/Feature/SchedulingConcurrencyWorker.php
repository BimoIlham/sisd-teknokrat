<?php

use App\Http\Controllers\Lpm\SupervisionPeriodController;
use App\Models\ScheduleImport;
use App\Models\Supervision;
use App\Models\SupervisionPeriod;
use App\Models\SupervisionStage;
use App\Models\User;
use App\Services\Reporting\SupervisorAssignmentLetterService;
use App\Services\ScheduleImport\ScheduleCommitService;
use App\Services\Scheduling\ManualOverrideService;
use App\Services\Scheduling\StageFinalizationService;
use App\Services\Supervision\SubmitService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
if (! $application->environment('testing') || config('database.default') !== 'mysql'
    || DB::connection()->getDatabaseName() !== 'sisd_teknokrat_test') {
    fwrite(STDERR, 'Refusing concurrency test outside the isolated testing database.');
    exit(2);
}

try {
    $job = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
    Carbon::setTestNow(Carbon::parse('2030-10-07 10:00:00', 'Asia/Jakarta'));
    Auth::login(User::findOrFail($job['actor_id']));
    DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
    file_put_contents($job['ready_path'], 'ready');
    $deadline = microtime(true) + 10;
    while (! is_file($job['release_path'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Concurrency barrier timed out.');
        }
        usleep(10000);
    }
    file_put_contents($job['attempt_path'], 'attempting');
    $result = match ($job['operation']) {
        'activate' => app(SupervisionPeriodController::class)->toggleStatus(SupervisionPeriod::findOrFail($job['period_id'])),
        'letter' => app(SupervisorAssignmentLetterService::class)->issueForStage(SupervisionStage::findOrFail($job['stage_id']), Auth::user()),
        'letter_command' => Artisan::call('supervisors:issue-assignment-letters', ['--stage' => $job['stage_id']]),
        'import' => app(ScheduleCommitService::class)->commit(ScheduleImport::findOrFail($job['import_id'])),
        'manual' => app(ManualOverrideService::class)->assign(SupervisionStage::findOrFail($job['stage_id']),
            $job['target_id'], $job['supervisor_id'], $job['occurrence_id'], Auth::user()),
        'finalize' => app(StageFinalizationService::class)->finalize(SupervisionStage::findOrFail($job['stage_id']), Auth::user()),
        'reopen' => app(StageFinalizationService::class)->reopen(SupervisionStage::findOrFail($job['stage_id']), Auth::user()),
        'submit' => app(SubmitService::class)->submit(Supervision::findOrFail($job['supervision_id']), Auth::user(), $job['row_version']),
        default => throw new RuntimeException('Unknown concurrency test operation.'),
    };
    if ($job['operation'] === 'letter_command' && $result !== 0) {
        throw new RuntimeException('Assignment letter command failed.');
    }
    echo json_encode(['result' => 'ok', 'count' => is_int($result) ? $result : null], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['result' => 'validation_conflict'], JSON_THROW_ON_ERROR);
} catch (AuthorizationException $exception) {
    echo json_encode(['result' => 'authorization_conflict'], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['result' => 'error', 'exception_class' => $exception::class], JSON_THROW_ON_ERROR);
    exit(1);
}
