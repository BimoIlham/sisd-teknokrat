<?php

namespace Tests\Feature;

use App\Http\Middleware\BrowserLogResourceGuard;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DevelopmentToolingProtectionTest extends TestCase
{
    #[TestWith(['production', 'true', false])]
    #[TestWith(['staging', 'true', false])]
    #[TestWith(['local', 'false', true])]
    public function test_boost_route_is_only_registered_in_local_environment(string $environment, string $debug, bool $expected): void
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$route = $app['router']->getRoutes()->getByName('boost.browser-logs');
echo json_encode(['exists' => $route !== null, 'middleware' => $route?->middleware(), 'browser' => config('logging.channels.browser.driver'), 'retention' => config('logging.channels.browser.days')]);
PHP;
        $process = new Process([PHP_BINARY, '-r', $code], base_path(), [
            'APP_ENV' => $environment, 'APP_DEBUG' => $debug, 'BOOST_ENABLED' => 'true',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'APP_CONFIG_CACHE' => sys_get_temp_dir().'/sisd-f13-unused-config.php',
            'APP_ROUTES_CACHE' => sys_get_temp_dir().'/sisd-f13-unused-routes.php',
        ]);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($expected, $result['exists']);
        $this->assertSame('daily', $result['browser']);
        $this->assertSame(14, $result['retention']);
        if ($expected) {
            $this->assertContains(BrowserLogResourceGuard::class, $result['middleware']);
            $this->assertContains('throttle:browser-logs', $result['middleware']);
        }
    }

    #[TestWith(['large', 413])]
    #[TestWith(['many', 422])]
    #[TestWith(['invalid', 422])]
    public function test_browser_logger_rejects_excessive_or_malformed_input(string $kind, int $status): void
    {
        $body = match ($kind) {
            'large' => str_repeat('a', 65537),
            'many' => json_encode(['logs' => array_fill(0, 51, [])]),
            default => json_encode(['logs' => [['type' => 'invalid']]]),
        };
        $request = Request::create('/_boost/browser-logs', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $response = (new BrowserLogResourceGuard)->handle($request, function (): never {
            $this->fail('Invalid input must not reach the vendor logger.');
        });
        $this->assertSame($status, $response->getStatusCode());
    }

    #[TestWith(['error'])]
    #[TestWith(['debug'])]
    public function test_valid_browser_logging_remains_available(string $type): void
    {
        $request = Request::create('/_boost/browser-logs', 'POST', server: ['CONTENT_TYPE' => 'text/plain'], content: json_encode(['logs' => [[
            'type' => $type, 'data' => ['test event'], 'url' => 'http://example.test/login',
            'userAgent' => 'test', 'timestamp' => '2026-10-11T00:00:00Z',
        ]]]));
        $response = (new BrowserLogResourceGuard)->handle($request, fn () => response()->json(['status' => 'logged']));
        $this->assertSame(200, $response->getStatusCode());
    }
}
