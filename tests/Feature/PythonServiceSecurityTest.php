<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\ScheduleImport;
use App\Models\ScheduleRun;
use App\Models\SupervisionStage;
use App\Models\User;
use App\Services\ScheduleImport\ScheduleImportService;
use App\Services\Scheduling\SolverClientService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PythonServiceSecurityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(DemoAcademicSeeder::class);
        Storage::fake('local');
        config(['services.python.url' => 'http://127.0.0.1:18100', 'services.python.secret' => Str::random(48)]);
    }

    #[TestWith(['solver', null])]
    #[TestWith(['parser', null])]
    #[TestWith(['solver', ''])]
    #[TestWith(['parser', '   '])]
    public function test_missing_secret_fails_before_http_or_persistence(string $client, ?string $secret): void
    {
        config(['services.python.secret' => $secret]);
        Http::fake();
        $imports = ScheduleImport::count();
        $runs = ScheduleRun::count();
        try {
            $this->callClient($client);
            $this->fail('A configured secret is required.');
        } catch (\LogicException $exception) {
            $this->assertSame('Konfigurasi service internal belum lengkap.', $exception->getMessage());
        }
        Http::assertNothingSent();
        $this->assertSame($imports, ScheduleImport::count());
        $this->assertSame($runs, ScheduleRun::count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    #[TestWith(['solver', 401])]
    #[TestWith(['parser', 401])]
    #[TestWith(['solver', 403])]
    #[TestWith(['parser', 403])]
    #[TestWith(['solver', 503])]
    #[TestWith(['parser', 503])]
    public function test_authentication_failures_cannot_be_disguised_by_fallback(string $client, int $status): void
    {
        Http::fake(['*' => Http::response(['detail' => ['code' => 'SERVICE_ACCESS_NOT_CONFIGURED']], $status)]);
        $runs = ScheduleRun::count();
        try {
            $this->callClient($client);
            $this->fail('Authentication failure must stop this operation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Service internal menolak konfigurasi akses.', $exception->getMessage());
            $this->assertStringNotContainsString(config('services.python.secret'), $exception->getMessage());
        }
        $this->assertSame($runs + ($client === 'solver' ? 1 : 0), ScheduleRun::count());
        if ($client === 'solver') {
            $run = ScheduleRun::latest('id')->firstOrFail();
            $this->assertSame('FAILED', $run->status);
            $this->assertSame([], $run->proposed_assignments);
            $this->assertNull($run->applied_at);
        }
        Http::assertSentCount(1);
    }

    public function test_both_clients_use_the_same_configured_url_and_bearer_header(): void
    {
        Http::fake([
            '*/v1/solve-assignments' => Http::response(['status' => 'FEASIBLE', 'assignments' => [], 'coverage_rate' => 0]),
            '*/v1/parse-schedule' => Http::response(['parser' => ['version' => '1.3.0'], 'rows' => [[
                'confidence' => 0.98, 'normalized' => ['lecturer_name' => 'Dosen Pengujian', 'course_name' => 'Pemrograman', 'class_name' => 'IF A', 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '09:40', 'room_name' => 'R1', 'delivery_mode' => 'OFFLINE'],
            ]], 'page_count' => 1]),
        ]);
        $this->assertSame('FEASIBLE', $this->callClient('solver')->status);
        $this->assertSame(1, $this->callClient('parser')->rows()->count());
        Http::assertSentCount(2);
        foreach (['solve-assignments', 'parse-schedule'] as $endpoint) {
            Http::assertSent(fn ($request): bool => $request->url() === config('services.python.url').'/v1/'.$endpoint
                && $request->hasHeader('Authorization', 'Bearer '.config('services.python.secret'))
                && ! $request->hasHeader('X-Service-Token'));
        }
    }

    public function test_configuration_cache_preserves_the_service_config_without_loading_a_default(): void
    {
        $cacheRelative = 'bootstrap/cache/f12-test-'.Str::uuid().'.php';
        $cache = base_path($cacheRelative);
        $secret = Str::random(48);
        $url = 'http://127.0.0.1:18100';
        $environment = ['APP_CONFIG_CACHE' => $cacheRelative, 'APP_ENV' => 'testing', 'PYTHON_SERVICE_SECRET' => $secret, 'PYTHON_SERVICE_URL' => $url];
        try {
            $build = new Process([PHP_BINARY, 'artisan', 'config:cache', '--env=testing', '--no-interaction'], base_path(), $environment);
            $build->mustRun();
            $this->assertFileExists($cache);
            $probe = new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $config = config("services.python"); exit(is_string($config["secret"]) && hash_equals(getenv("EXPECTED_TEST_SECRET"), $config["secret"]) && $config["url"] === getenv("EXPECTED_TEST_URL") ? 0 : 1);'], base_path(), [
                'APP_CONFIG_CACHE' => $cacheRelative, 'APP_ENV' => 'testing', 'PYTHON_SERVICE_SECRET' => '', 'PYTHON_SERVICE_URL' => '',
                'EXPECTED_TEST_SECRET' => $secret, 'EXPECTED_TEST_URL' => $url,
            ]);
            $probe->run();
            $this->assertSame(0, $probe->getExitCode());
            $this->assertStringNotContainsString($secret, $build->getOutput().$build->getErrorOutput().$probe->getOutput().$probe->getErrorOutput());
        } finally {
            if (is_file($cache)) {
                unlink($cache);
            }
        }
    }

    public function test_source_has_no_legacy_token_or_secret_fallback(): void
    {
        foreach (['app/Services/Scheduling/SolverClientService.php', 'app/Services/ScheduleImport/ScheduleImportService.php'] as $file) {
            $source = file_get_contents(base_path($file));
            $this->assertStringNotContainsString("env('PYTHON_", $source);
            $this->assertStringNotContainsString('X-Service-Token', $source);
            $this->assertStringNotContainsString('PYTHON_SERVICE_TOKEN', $source);
        }
        $source = file_get_contents(base_path('python-service/app/main.py'));
        $this->assertStringContainsString('os.environ.get("PYTHON_SERVICE_SECRET")', $source);
        $this->assertStringContainsString('secrets.compare_digest', $source);
        $this->assertMatchesRegularExpression('/^PYTHON_SERVICE_SECRET=\s*$/m', file_get_contents(base_path('.env.example')));
    }

    private function callClient(string $client): ScheduleRun|ScheduleImport
    {
        $user = User::where('email', 'lpm@teknokrat.ac.id')->firstOrFail();
        if ($client === 'solver') {
            return app(SolverClientService::class)->solve(SupervisionStage::firstOrFail(), $user);
        }

        return app(ScheduleImportService::class)->import(UploadedFile::fake()->create('jadwal.pdf', 10, 'application/pdf'), AcademicTerm::firstOrFail(), $user);
    }
}
