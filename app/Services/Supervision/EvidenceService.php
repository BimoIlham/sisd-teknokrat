<?php

namespace App\Services\Supervision;

use App\Models\Supervision;
use App\Models\SupervisionAnswer;
use App\Models\SupervisionEvidence;
use App\Services\Scheduling\SchedulingLockService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class EvidenceService
{
    protected string $disk = 'local';

    public function __construct(private SupervisionAccessService $access) {}

    /**
     * Store uploaded evidence privately.
     */
    public function storeEvidence(Supervision $supervision, UploadedFile $file, ?int $questionId = null, ?string $description = null): SupervisionEvidence
    {
        $allowedMimes = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg'];
        if (! in_array($file->getMimeType(), $allowedMimes)) {
            throw ValidationException::withMessages(['file' => 'Format file tidak didukung. Harap unggah berkas PDF, PNG, atau JPG.']);
        }

        $maxBytes = 5 * 1024 * 1024; // 5 MB
        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages(['file' => 'Ukuran berkas melebihi batas maksimum 5 MB.']);
        }

        return DB::transaction(function () use ($supervision, $file, $questionId): SupervisionEvidence {
            app(SchedulingLockService::class)->state();
            $lockedSupervision = Supervision::lockForUpdate()->findOrFail($supervision->id);
            $this->access->authorizeMutation($lockedSupervision);

            $question = $lockedSupervision->formTemplate->questions()
                ->whereKey($questionId)
                ->where('question_type', 'FILE')
                ->first();

            if (! $question) {
                throw ValidationException::withMessages(['file' => 'Butir bukti tidak valid untuk formulir ini.']);
            }

            $maxQuestionFiles = min(3, max(1, (int) ($question->validation_rules['max_files'] ?? 3)));
            if ($lockedSupervision->evidence()->where('supervision_answers.question_id', $question->id)->count() >= $maxQuestionFiles) {
                throw ValidationException::withMessages(['file' => "Butir bukti ini maksimal {$maxQuestionFiles} berkas. Hapus berkas sebelumnya sebelum mengunggah penggantinya."]);
            }

            $totals = $lockedSupervision->evidence()->toBase()->selectRaw('COUNT(*) AS file_count, COALESCE(SUM(size_bytes), 0) AS total_bytes')->first();
            $fileQuestionCount = $lockedSupervision->formTemplate->questions()->where('question_type', 'FILE')->count();
            if ((int) $totals->file_count >= $fileQuestionCount * 3) {
                throw ValidationException::withMessages(['file' => 'Jumlah berkas bukti sudah mencapai batas formulir.']);
            }
            if ($lockedSupervision->evidence()->where(fn ($query) => $query->whereNull('size_bytes')->orWhere('size_bytes', '<', 0))->exists()) {
                throw ValidationException::withMessages(['file' => 'Ukuran bukti sebelumnya belum valid. Hubungi LPM sebelum menambah bukti.']);
            }
            if ((int) $totals->total_bytes + $file->getSize() > 20 * 1024 * 1024) {
                throw ValidationException::withMessages(['file' => 'Total ukuran bukti pada formulir ini maksimal 20 MB.']);
            }

            $answer = SupervisionAnswer::firstOrCreate(
                [
                    'supervision_id' => $lockedSupervision->id,
                    'question_id' => $question->id,
                ],
                [
                    'text_value' => $file->getClientOriginalName(),
                    'is_applicable' => true,
                ]
            );
            $answer->update(['text_value' => $file->getClientOriginalName()]);

            $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
            $storedFilename = Str::uuid()->toString().'.'.$extension;
            $folder = "private/evidence/{$lockedSupervision->id}";
            $storedPath = $folder.'/'.$storedFilename;
            DB::afterRollBack(fn () => $this->removeStoredFile($this->disk, $storedPath));

            $path = $file->storeAs($folder, $storedFilename, $this->disk);
            if (! is_string($path) || $path === '') {
                throw new RuntimeException('Berkas bukti gagal disimpan. Silakan unggah kembali.');
            }

            $evidence = SupervisionEvidence::create([
                'supervision_answer_id' => $answer->id,
                'uploaded_by' => auth()->id(),
                'evidence_type' => $file->getMimeType() === 'application/pdf' ? 'FILE' : 'IMAGE',
                'storage_disk' => $this->disk,
                'file_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'status' => 'READY',
                'validated_at' => now(),
            ]);

            $lockedSupervision->draft_started_at ??= now();
            $lockedSupervision->row_version += 1;
            $lockedSupervision->save();

            return $evidence->setRelation('answer', $answer->setRelation('supervision', $lockedSupervision));
        });
    }

    /**
     * Delete evidence file and record.
     */
    public function deleteEvidence(SupervisionEvidence $evidence): int
    {
        $supervisionId = SupervisionEvidence::findOrFail($evidence->id)->answer()->value('supervision_id');

        return DB::transaction(function () use ($evidence, $supervisionId): int {
            app(SchedulingLockService::class)->state();
            $supervision = Supervision::lockForUpdate()->findOrFail($supervisionId);
            $this->access->authorizeMutation($supervision);
            $freshEvidence = $supervision->evidence()->with('answer')->whereKey($evidence->id)->firstOrFail();

            $answer = $freshEvidence->answer;
            $disk = $freshEvidence->storage_disk ?: $this->disk;
            $path = $freshEvidence->file_path;
            $evidenceId = $freshEvidence->id;

            $freshEvidence->delete();

            if (! $answer->evidence()->exists()) {
                $answer->delete();
            }

            $supervision->increment('row_version');
            if ($path) {
                DB::afterCommit(fn () => $this->removeStoredFile($disk, $path, $evidenceId));
            }

            return (int) $supervision->row_version;
        });
    }

    private function removeStoredFile(string $disk, string $path, ?int $evidenceId = null): void
    {
        try {
            if (Storage::disk($disk)->delete($path)) {
                return;
            }
        } catch (Throwable) {
        }

        Log::warning('Pembersihan berkas bukti gagal.', ['evidence_id' => $evidenceId, 'storage_disk' => $disk, 'file_path' => $path]);
    }
}
