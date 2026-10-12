<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BrowserLogResourceGuard
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ((int) $request->header('Content-Length', 0) > 65536 || strlen($request->getContent()) > 65536) {
            return response()->json(['message' => 'Payload log terlalu besar.'], 413);
        }
        $payload = json_decode($request->getContent(), true, 12);
        if (! is_array($payload) || ! isset($payload['logs']) || ! is_array($payload['logs']) || count($payload['logs']) > 50) {
            return response()->json(['message' => 'Payload log tidak valid.'], 422);
        }
        foreach ($payload['logs'] as $log) {
            if (! is_array($log) || ! in_array($log['type'] ?? null, ['error', 'warn', 'warning', 'info', 'log', 'debug', 'table', 'window_error', 'uncaught_error', 'unhandled_rejection'], true)
                || ! is_array($log['data'] ?? null) || ! is_string($log['url'] ?? null)
                || ! is_string($log['userAgent'] ?? null) || ! is_string($log['timestamp'] ?? null)) {
                return response()->json(['message' => 'Payload log tidak valid.'], 422);
            }
        }

        return $next($request);
    }
}
