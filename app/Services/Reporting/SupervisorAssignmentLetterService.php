<?php

namespace App\Services\Reporting;

use App\Models\GeneratedDocument;
use App\Models\SupervisionStage;
use App\Models\User;
use App\Services\Scheduling\SchedulingLockService;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class SupervisorAssignmentLetterService
{
    public const DOCUMENT_TYPE = 'SUPERVISOR_ASSIGNMENT_LETTER';

    /**
     * Issue both stage letters as one period-level finalization operation.
     */
    public function issueForStages(SupervisionStage $stageOne, SupervisionStage $stageTwo, User $actor): void
    {
        DB::transaction(function () use ($stageOne, $stageTwo, $actor): void {
            foreach ([$stageOne, $stageTwo] as $stage) {
                $this->issueForStage($stage, $actor);
            }
        });
    }

    /**
     * Register letter numbers and immutable contents; render PDFs when downloaded.
     */
    public function issueForStage(SupervisionStage $stage, User $actor): void
    {
        DB::transaction(function () use ($stage, $actor): void {
            $locks = app(SchedulingLockService::class);
            $locks->state();
            $stage = $locks->stage($stage->id);
            if (! $stage->is_finalized || ! $stage->finalized_at) {
                throw ValidationException::withMessages(['stage' => 'Surat tugas hanya dapat diterbitkan setelah tahap difinalisasi.']);
            }
            $stage->loadMissing('period.academicTerm');
            $assignments = $stage->assignments()->whereIn('status', ['FINAL', 'COMPLETED'])
                ->with(['supervisor', 'target', 'occurrence.schedule'])
                ->orderBy('starts_at')->orderBy('id')->get();
            $supervisorOrdinals = $this->supervisorOrdinals($stage);
            $issuedAt = $stage->finalized_at->copy()->timezone('Asia/Jakarta');
            $this->lockNumberingYear($issuedAt->year);
            $issuedSupervisorIds = GeneratedDocument::where('document_type', self::DOCUMENT_TYPE)
                ->where('supervision_stage_id', $stage->id)->where('status', 'READY')
                ->pluck('supervisor_lecturer_id')->all();

            foreach ($assignments->groupBy('supervisor_lecturer_id') as $supervisorId => $duties) {
                if (in_array($supervisorId, $issuedSupervisorIds)) {
                    continue;
                }
                $groups = [];
                foreach ($duties as $assignment) {
                    $mode = $assignment->occurrence?->schedule?->delivery_mode;
                    if (! in_array($mode, ['OFFLINE', 'ONLINE', 'HYBRID'], true) || ! $assignment->target || ! $assignment->supervisor) {
                        throw ValidationException::withMessages(['stage' => 'Surat tugas belum dapat dibuat: identitas dosen atau metode perkuliahan penugasan belum lengkap.']);
                    }
                    $groups[$mode][$assignment->target_lecturer_id] = $assignment->target->name;
                }

                $ordinal = $supervisorOrdinals[(int) $supervisorId] ?? null;
                if (! $ordinal) {
                    throw ValidationException::withMessages([
                        'stage' => 'Nomor surat tugas tidak dapat ditentukan karena supervisor tidak terdaftar pada daftar supervisor periode ini.',
                    ]);
                }
                $number = $this->numberForSupervisor($ordinal, $stage, $issuedAt);
                $snapshot = [
                    'number' => $number,
                    'supervisor_name' => $duties->first()->supervisor->name,
                    'stage_no' => $stage->stage_no,
                    'semester' => ucfirst(strtolower($stage->period->academicTerm->semester)),
                    'academic_year' => $stage->period->academicTerm->academic_year,
                    'starts_on' => $stage->starts_on->locale('id')->translatedFormat('j F Y'),
                    'ends_on' => $stage->ends_on->locale('id')->translatedFormat('j F Y'),
                    'issued_on' => $issuedAt->locale('id')->translatedFormat('j F Y'),
                    'chair_name' => 'Dr. Laila Ulsi Qodriani, M.A.',
                    'groups' => collect(['OFFLINE' => 'Offline', 'ONLINE' => 'Online', 'HYBRID' => 'Hibrida'])
                        ->filter(fn (string $label, string $mode): bool => isset($groups[$mode]))
                        ->map(fn (string $label, string $mode): array => ['label' => $label, 'lecturers' => array_values($groups[$mode])])->values()->all(),
                ];
                GeneratedDocument::create([
                    'document_type' => self::DOCUMENT_TYPE,
                    'document_number' => $number,
                    'supervision_period_id' => $stage->supervision_period_id,
                    'supervision_stage_id' => $stage->id,
                    'supervisor_lecturer_id' => $supervisorId,
                    'source_revision' => $stage->schedule_revision ?? 1,
                    'source_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                    'filter_snapshot' => $snapshot,
                    'status' => 'READY',
                    'storage_disk' => 'local',
                    'generated_by' => $actor->id,
                    'generated_at' => $issuedAt,
                ]);
            }
        }, 3);
    }

    /**
     * Refresh already issued letters after changing the signature or numbering format.
     */
    public function refreshForStage(SupervisionStage $stage): int
    {
        $documents = GeneratedDocument::query()
            ->where('document_type', self::DOCUMENT_TYPE)
            ->where('supervision_stage_id', $stage->id)
            ->where('status', 'READY')
            ->orderBy('supervisor_lecturer_id')
            ->get();
        if ($documents->isEmpty()) {
            return 0;
        }

        $supervisorOrdinals = $this->supervisorOrdinals($stage);
        $documentIds = $documents->modelKeys();
        $revisions = [];
        $revisionPaths = [];

        try {
            foreach ($documents as $document) {
                $ordinal = $supervisorOrdinals[(int) $document->supervisor_lecturer_id] ?? null;
                if (! $ordinal) {
                    throw ValidationException::withMessages([
                        'stage' => 'Nomor surat tugas tidak dapat diperbarui karena supervisor tidak terdaftar pada daftar supervisor periode ini.',
                    ]);
                }

                $issuedAt = ($document->generated_at ?? now())->copy()->timezone('Asia/Jakarta');
                $snapshot = $document->filter_snapshot ?? [];
                $snapshot['number'] = $this->numberForSupervisor($ordinal, $stage, $issuedAt, $documentIds);
                $contents = $this->render($snapshot)->output();
                $diskName = $document->storage_disk;
                $newPath = 'surat-tugas/revisions/supervisor-'.$document->id.'-'.Str::uuid().'.pdf';
                $revisionPaths[] = [$diskName, $newPath];

                if (! Storage::disk($diskName)->put($newPath, $contents)) {
                    throw new RuntimeException('Surat tugas hasil pembaruan gagal disimpan.');
                }

                $revisions[] = [
                    'document' => $document,
                    'snapshot' => $snapshot,
                    'number' => $snapshot['number'],
                    'new_path' => $newPath,
                    'old_path' => $document->file_path,
                    'disk' => $diskName,
                    'contents' => $contents,
                ];
            }

            DB::transaction(function () use ($stage, $documentIds, $revisions): void {
                $locks = app(SchedulingLockService::class);
                $locks->state();
                $lockedStage = $locks->stage($stage->id);
                if (! $lockedStage->is_finalized || ! $lockedStage->finalized_at) {
                    throw new ConflictHttpException('Tahap berubah saat pembaruan surat tugas. Muat ulang dan ulangi pembaruan.');
                }
                $years = collect($revisions)->map(fn (array $revision): int => (int) ($revision['document']->generated_at ?? now())->copy()->timezone('Asia/Jakarta')->year)->unique()->sort();
                foreach ($years as $year) {
                    $this->lockNumberingYear($year);
                }
                $freshDocuments = GeneratedDocument::whereIn('id', $documentIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($revisions as $revision) {
                    $original = $revision['document'];
                    $fresh = $freshDocuments->get($original->id);
                    $issuedAt = ($original->generated_at ?? now())->copy()->timezone('Asia/Jakarta');
                    $ordinal = $this->supervisorOrdinals($lockedStage)[(int) $original->supervisor_lecturer_id] ?? 0;
                    if (! $fresh || $fresh->status !== 'READY' || $fresh->source_hash !== $original->source_hash
                        || $fresh->document_number !== $original->document_number || $fresh->file_path !== $original->file_path
                        || ! $ordinal || $this->numberForSupervisor($ordinal, $lockedStage, $issuedAt, $documentIds) !== $revision['number']) {
                        throw new ConflictHttpException('Surat tugas berubah saat pembaruan. Muat ulang dan ulangi pembaruan.');
                    }
                }
                GeneratedDocument::whereIn('id', $documentIds)->update(['document_number' => null]);

                foreach ($revisions as $revision) {
                    $document = $revision['document'];
                    $document->refresh();
                    $document->update([
                        'document_number' => $revision['number'],
                        'filter_snapshot' => $revision['snapshot'],
                        'source_hash' => hash('sha256', json_encode($revision['snapshot'], JSON_THROW_ON_ERROR)),
                        'file_path' => $revision['new_path'],
                        'sha256' => hash('sha256', $revision['contents']),
                    ]);
                }
            }, 3);
        } catch (Throwable $exception) {
            foreach ($revisionPaths as [$diskName, $path]) {
                Storage::disk($diskName)->delete($path);
            }

            throw $exception;
        }

        foreach ($revisions as $revision) {
            if ($revision['old_path'] && $revision['old_path'] !== $revision['new_path']) {
                Storage::disk($revision['disk'])->delete($revision['old_path']);
            }
        }

        return count($revisions);
    }

    /**
     * @param  array{number: string, supervisor_name: string, stage_no: int, semester: string, academic_year: string, starts_on: string, ends_on: string, issued_on: string, chair_name: string, groups: array<int, array{label: string, lecturers: array<int, string>}>}  $letter
     */
    public function render(array $letter): PdfDocument
    {
        $letterhead = 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents(public_path('images/supervisor-letterhead.jpeg')));
        $signature = 'data:image/png;base64,'.base64_encode((string) file_get_contents(public_path('images/lpm-chair-signature.png')));

        return Pdf::loadView('pdf.supervisor-assignment-letter', compact('letter', 'letterhead', 'signature'))
            ->setPaper('a4', 'portrait');
    }

    /**
     * @return array<int, int>
     */
    private function supervisorOrdinals(SupervisionStage $stage): array
    {
        $supervisorIds = DB::table('period_supervisors')
            ->join('lecturers', 'lecturers.id', '=', 'period_supervisors.lecturer_id')
            ->where('period_supervisors.supervision_period_id', $stage->supervision_period_id)
            ->where('period_supervisors.is_active', true)
            ->orderByRaw('LOWER(lecturers.name)')
            ->orderBy('lecturers.id')
            ->pluck('period_supervisors.lecturer_id');

        $ordinals = [];
        foreach ($supervisorIds as $index => $supervisorId) {
            $ordinals[(int) $supervisorId] = $index + 1;
        }

        return $ordinals;
    }

    /**
     * @param  list<int>  $excludedDocumentIds
     */
    private function numberForSupervisor(
        int $ordinal,
        SupervisionStage $stage,
        CarbonInterface $issuedAt,
        array $excludedDocumentIds = []
    ): string {
        $romanMonths = [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
        $number = sprintf('%03d/LPM-UTI/%s/%d', $ordinal, $romanMonths[$issuedAt->month], $issuedAt->year);
        $usedNumbers = GeneratedDocument::query()
            ->where('document_number', 'like', $number.'%')
            ->when($excludedDocumentIds !== [], fn ($query) => $query->whereNotIn('id', $excludedDocumentIds))
            ->pluck('document_number')->flip();
        $stageNumber = $number.'/T'.$stage->stage_no;
        $periodNumber = $stageNumber.'/P'.$stage->supervision_period_id;
        foreach ([$number, $stageNumber, $periodNumber] as $candidateNumber) {
            if (! $usedNumbers->has($candidateNumber)) {
                return $candidateNumber;
            }
        }

        $revision = 2;
        while ($usedNumbers->has($periodNumber.'/R'.$revision)) {
            $revision++;
        }

        return $periodNumber.'/R'.$revision;
    }

    /**
     * The year row is a mutex only; supervisor ordinals and existing suffix rules stay unchanged.
     */
    private function lockNumberingYear(int $year): void
    {
        DB::table('assignment_letter_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
        DB::table('assignment_letter_sequences')->where('year', $year)->lockForUpdate()->first();
    }
}
