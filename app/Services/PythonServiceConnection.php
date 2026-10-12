<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;

class PythonServiceConnection
{
    public function client(): PendingRequest
    {
        $secret = config('services.python.secret');
        $url = config('services.python.url');
        if (! is_string($secret) || trim($secret) === '' || ! is_string($url) || trim($url) === '') {
            throw new LogicException('Konfigurasi service internal belum lengkap.');
        }

        return Http::baseUrl(rtrim($url, '/'))->withToken($secret);
    }

    public function assertAuthenticated(Response $response): void
    {
        if (in_array($response->status(), [401, 403], true)
            || ($response->status() === 503 && $response->json('detail.code') === 'SERVICE_ACCESS_NOT_CONFIGURED')) {
            throw new RuntimeException('Service internal menolak konfigurasi akses.');
        }
    }
}
