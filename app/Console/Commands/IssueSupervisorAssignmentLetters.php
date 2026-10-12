<?php

namespace App\Console\Commands;

use App\Models\GeneratedDocument;
use App\Models\SupervisionStage;
use App\Services\Reporting\SupervisorAssignmentLetterService;
use App\Services\Scheduling\SchedulingLockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class IssueSupervisorAssignmentLetters extends Command
{
    protected $signature = 'supervisors:issue-assignment-letters {--stage= : ID tahap tertentu} {--refresh : Perbarui surat tugas yang sudah terbit}';

    protected $description = 'Buat atau perbarui surat tugas pada tahap yang sudah difinalisasi';

    /**
     * Execute the console command.
     */
    public function handle(SupervisorAssignmentLetterService $letters): int
    {
        $stages = SupervisionStage::where('status', 'FINALIZED')
            ->when($this->option('stage'), fn ($query) => $query->whereKey($this->option('stage')))
            ->orderBy('id')->get();
        $processedCount = 0;
        foreach ($stages as $stage) {
            try {
                if ($this->option('refresh')) {
                    if (! $stage->is_finalized || ! $stage->finalized_at || ! $stage->finalizer) {
                        throw new RuntimeException('Tanggal atau penanggung jawab finalisasi belum tersedia.');
                    }

                    $processedCount += $letters->refreshForStage($stage);

                    continue;
                }

                $processedCount += DB::transaction(function () use ($stage, $letters): int {
                    $locks = app(SchedulingLockService::class);
                    $locks->state();
                    $stage = $locks->stage($stage->id);
                    if (! $stage->is_finalized) {
                        return 0;
                    }
                    if (! $stage->finalized_at || ! $stage->finalizer) {
                        throw new RuntimeException('Tanggal atau penanggung jawab finalisasi belum tersedia.');
                    }
                    $before = GeneratedDocument::where('supervision_stage_id', $stage->id)
                        ->where('document_type', SupervisorAssignmentLetterService::DOCUMENT_TYPE)->count();
                    $letters->issueForStage($stage, $stage->finalizer);

                    return GeneratedDocument::where('supervision_stage_id', $stage->id)
                        ->where('document_type', SupervisorAssignmentLetterService::DOCUMENT_TYPE)->count() - $before;
                }, 3);
            } catch (Throwable $exception) {
                $this->error('Tahap '.$stage->id.': '.$exception->getMessage());

                return self::FAILURE;
            }
        }
        $message = $this->option('refresh')
            ? $processedCount.' surat tugas berhasil diperbarui.'
            : $processedCount.' surat tugas berhasil dibuat.';
        $this->info($message);

        return self::SUCCESS;
    }
}
