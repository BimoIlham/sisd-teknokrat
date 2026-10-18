<?php

namespace App\Providers;

use App\Http\Middleware\BrowserLogResourceGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (['supervision-uploads' => 10, 'schedule-generation' => 2, 'schedule-imports' => 6, 'report-exports' => 15, 'schedule-commits' => 6] as $name => $attempts) {
            RateLimiter::for($name, fn (Request $request): Limit => Limit::perMinute($attempts)
                ->by((string) $request->user()->id)
                ->response(function (Request $request, array $headers) use ($name): Response {
                    $message = 'Terlalu banyak permintaan. Tunggu sebentar sebelum mencoba kembali.';
                    if ($name === 'schedule-generation' && ! $request->expectsJson()) {
                        $response = back()->with('error', 'Penjadwalan otomatis dibatasi dua kali per menit. Tunggu sebentar sebelum mencoba lagi.');
                        $response->headers->add($headers);

                        return $response;
                    }

                    return response()->json(['message' => $message], 429, $headers);
                }));
        }
        RateLimiter::for('browser-logs', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip())
            ->response(fn (Request $request, array $headers): JsonResponse => response()->json([
                'message' => 'Terlalu banyak permintaan log. Coba kembali sebentar lagi.',
            ], 429, $headers)));
        $this->app->booted(function (): void {
            foreach ($this->app['router']->getRoutes() as $route) {
                if ($route->getName() === 'boost.browser-logs') {
                    $route->middleware([BrowserLogResourceGuard::class, 'throttle:browser-logs']);
                }
            }
        });
    }
}
