<?php

use App\Models\FormTemplate;
use App\Models\Supervision;
use App\Services\InstrumentVersionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
if (! $application->environment('testing') || config('database.default') !== 'mysql'
    || DB::connection()->getDatabaseName() !== 'sisd_teknokrat_test') {
    exit(2);
}

try {
    $job = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
    DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
    $result = DB::transaction(function () use ($job): array {
        if ($job['operation'] === 'edit') {
            $template = app(InstrumentVersionService::class)->lockEditable($job['template_id']);
            $template->sections()->firstOrFail()->update(['title' => 'Edited concurrently']);

            return ['result' => 'edited'];
        }
        if ($job['operation'] === 'clone') {
            $copy = app(InstrumentVersionService::class)->duplicate(FormTemplate::findOrFail($job['template_id']));

            return ['result' => 'cloned', 'version' => $copy->version];
        }
        $supervision = Supervision::create($job['values']);

        return ['result' => 'created', 'title' => $supervision->formTemplate->sections()->firstOrFail()->title];
    });
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['result' => 'rejected'], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['result' => 'error', 'class' => $exception::class], JSON_THROW_ON_ERROR);
    exit(1);
}
