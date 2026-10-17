<?php

namespace App\Console\Commands;

use App\Services\PermanentDeletionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:retry-pending-file-deletions')]
#[Description('Coba lagi pembersihan file setelah penghapusan permanen')]
class RetryPendingFileDeletions extends Command
{
    public function handle(PermanentDeletionService $deletions): int
    {
        $failed = $deletions->retryPendingFiles();
        if ($failed !== []) {
            $this->warn(count($failed).' file belum dapat dibersihkan. Jalankan perintah ini lagi setelah penyimpanan pulih.');

            return self::FAILURE;
        }

        $this->info('Semua file tertunda sudah dibersihkan.');

        return self::SUCCESS;
    }
}
