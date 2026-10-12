<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class UnexpectedErrorReporter
{
    /**
     * @return array{message: string, reference: string}
     */
    public function report(Throwable $exception, string $operation): array
    {
        $reference = (string) Str::uuid();

        Log::error('Application operation failed', [
            'reference' => $reference,
            'operation' => $operation,
            'exception_class' => $exception::class,
            'source' => ['file' => $exception->getFile(), 'line' => $exception->getLine()],
            'user_id' => auth()->id(),
        ]);

        return [
            'message' => 'Proses gagal. Hubungi administrator dengan kode '.$reference.'.',
            'reference' => $reference,
        ];
    }
}
