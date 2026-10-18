<?php

namespace App\Services\ScheduleImport;

use App\Models\AcademicTerm;
use App\Models\Lecturer;
use App\Models\PeriodTarget;
use App\Models\ScheduleImport;
use App\Models\ScheduleImportRow;
use App\Models\SupervisionPeriod;
use App\Models\TeachingSchedule;
use App\Models\User;
use App\Services\PythonServiceConnection;
use App\Services\Scheduling\SchedulingLockService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class ScheduleImportService
{
    private const PARSER_VERSION = '1.3.0';

    public function __construct(protected ScheduleRowValidator $rowValidator) {}

    /**
     * Upload and parse schedule PDF document into staging rows.
     */
    public function import(UploadedFile $file, AcademicTerm $term, User $uploader): ScheduleImport
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension !== 'pdf') {
            throw new \InvalidArgumentException('Hanya berkas PDF jadwal yang dapat diunggah.');
        }

        if ($file->getSize() > 15 * 1024 * 1024) {
            throw ValidationException::withMessages(['schedule_pdf' => 'Ukuran berkas PDF maksimal 15 MB.']);
        }
        $connection = app(PythonServiceConnection::class);
        $client = $connection->client();

        $filename = Str::uuid()->toString().'.pdf';
        $sha256 = hash_file('sha256', $file->getRealPath());

        // Check if an uncommitted import for the same term and sha256 already exists
        $period = SupervisionPeriod::where('status', 'ACTIVE')->where('academic_term_id', $term->id)->first();
        $existing = ScheduleImport::where('supervision_period_id', $period?->id)
            ->where('academic_term_id', $term->id)
            ->where('sha256', $sha256)
            ->first();

        $hasActiveSchedules = $existing && TeachingSchedule::where('source_import_id', $existing->id)->where('is_active', true)->exists();
        if ($hasActiveSchedules) {
            return $this->retryUnmatchedRows($existing);
        }
        if (in_array($existing?->status, ['COMMITTED', 'PENDING_REVIEW'], true) && $existing->rows()->exists()
            && ! $existing->rows()->where(function ($query): void {
                $query->whereNull('skip_reason')
                    ->orWhereNotIn('skip_reason', [...PeriodLecturerExclusions::intentionalReasons(), PeriodLecturerExclusions::DEFERRED_REASON]);
            })->exists()) {
            return $existing;
        }

        $storedPath = $file->storeAs('imports', $filename, 'local');
        if (! is_string($storedPath) || $storedPath === '') {
            throw new \RuntimeException('Berkas impor gagal disimpan.');
        }
        $previousPath = $existing?->stored_path;
        try {
            $import = DB::transaction(function () use ($existing, $period, $term, $file, $uploader, $storedPath, $sha256): ScheduleImport {
                DB::afterRollBack(fn () => $this->removeImportFile($storedPath));
                if ($existing) {
                    $existing->rows()->delete();
                    $import = $existing;
                    $import->update([
                        'status' => 'PROCESSING',
                        'original_filename' => $file->getClientOriginalName(),
                        'stored_path' => $storedPath,
                        'uploaded_by' => $uploader->id,
                        'size_bytes' => $file->getSize(),
                        'mime_type' => $file->getClientMimeType() ?: 'application/pdf',
                        'error_message' => null,
                    ]);
                } else {
                    $import = ScheduleImport::create([
                        'academic_term_id' => $term->id,
                        'supervision_period_id' => $period?->id,
                        'uploaded_by' => $uploader->id,
                        'original_filename' => $file->getClientOriginalName(),
                        'storage_disk' => 'local',
                        'stored_path' => $storedPath,
                        'sha256' => $sha256,
                        'size_bytes' => $file->getSize(),
                        'mime_type' => $file->getClientMimeType() ?: 'application/pdf',
                        'parser_name' => 'pdf-schedule-parser',
                        'parser_version' => self::PARSER_VERSION,
                        'contract_version' => '1.0',
                        'status' => 'PROCESSING',
                        'row_version' => 1,
                    ]);
                }

                return $import;
            });
        } catch (\Throwable $exception) {
            $this->removeImportFile($storedPath);
            throw $exception;
        }
        if ($previousPath && $previousPath !== $storedPath && ! ScheduleImport::where('stored_path', $previousPath)->exists()) {
            DB::afterCommit(fn () => $this->removeImportFile($previousPath));
        }

        $knownDocument = app(ScheduleImportReferences::class)->parsedDocument($sha256);
        $parsedRows = $knownDocument['rows'] ?? [];
        $pageCount = $knownDocument['page_count'] ?? 1;
        $parserOutdated = false;
        $parserHttpStatus = null;
        $parserExecuted = false;

        // 1. Try Python HTTP endpoint first
        if (empty($parsedRows)) {
            try {
                $stream = fopen($file->getRealPath(), 'rb');
                try {
                    $response = $client->timeout(30)->attach('file', $stream, $file->getClientOriginalName())->post('/v1/parse-schedule');
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                $parserHttpStatus = $response->status();
                $connection->assertAuthenticated($response);
                if (in_array($response->status(), [413, 422], true) || ($response->status() === 503 && $response->json('detail.code') === 'RESOURCE_BUSY')) {
                    $message = 'PDF tidak memenuhi batas pemrosesan atau service sedang sibuk. Maksimal 15 MB, 200 halaman, dan teks yang dapat diproses.';
                    $import->update(['status' => 'FAILED', 'error_message' => $message]);
                    throw ValidationException::withMessages(['schedule_pdf' => $message]);
                }

                if ($response->successful()) {
                    $data = $response->json();
                    if (version_compare($data['parser']['version'] ?? '0.0.0', self::PARSER_VERSION, '>=')) {
                        $parserExecuted = true;
                        $parsedRows = $data['rows'] ?? [];
                        $pageCount = $data['page_count'] ?? 1;
                    } else {
                        $parserOutdated = true;
                    }
                }
            } catch (ConnectionException $e) {
                Log::info('Python parser unavailable; attempting CLI fallback', ['exception_class' => $e::class]);
            }
        }

        // 2. Direct Python CLI execution fallback (ensures 100% availability without background daemon)
        if (empty($parsedRows)) {
            $pythonExe = $this->localParserExecutable();
            $cliScript = base_path('python-service/cli_parse.py');

            if ($pythonExe && file_exists($pythonExe) && file_exists($cliScript)) {
                try {
                    $env = array_merge(
                        getenv(),
                        $_ENV,
                        $_SERVER,
                        [
                            'SystemRoot' => getenv('SystemRoot') ?: (getenv('SYSTEMROOT') ?: 'C:\\Windows'),
                            'SYSTEMROOT' => getenv('SystemRoot') ?: (getenv('SYSTEMROOT') ?: 'C:\\Windows'),
                            'windir' => getenv('windir') ?: 'C:\\Windows',
                            'PATH' => getenv('PATH') ?: 'C:\\Windows\\System32;C:\\Windows',
                        ]
                    );
                    $process = new Process([$pythonExe, $cliScript, $file->getRealPath(), (string) $import->id], null, $env);
                    $process->setTimeout(60);
                    $process->run();

                    if ($process->isSuccessful()) {
                        $cliOutput = $process->getOutput();
                        // Find json starting bracket in case of warnings
                        $jsonStart = strpos($cliOutput, '{');
                        if ($jsonStart !== false) {
                            $jsonStr = substr($cliOutput, $jsonStart);
                            $data = json_decode($jsonStr, true);
                            if (is_array($data)
                                && version_compare($data['parser']['version'] ?? '0.0.0', self::PARSER_VERSION, '>=')) {
                                $parserExecuted = true;
                                $parsedRows = $data['rows'] ?? [];
                                $pageCount = $data['page_count'] ?? 1;
                            } elseif (is_array($data)) {
                                $parserOutdated = true;
                            }
                        }
                    } else {
                        Log::warning('Python CLI parser failed', ['exit_code' => $process->getExitCode()]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Python CLI parser failed', ['exception_class' => $e::class]);
                }
            }
        }

        if (empty($parsedRows) && $parserOutdated) {
            $message = 'Parser PDF di server belum versi '.self::PARSER_VERSION.'. Perbarui layanan Python bersama aplikasi sebelum mengunggah jadwal.';
            $import->update(['status' => 'FAILED', 'error_message' => $message]);
            throw ValidationException::withMessages(['schedule_pdf' => $message]);
        }

        if (empty($parsedRows)) {
            if ($parserExecuted) {
                $message = 'PDF berhasil diterima, tetapi tidak ada baris jadwal yang terbaca. Pastikan berkas berisi tabel jadwal dengan teks yang dapat dibaca.';
            } elseif ($parserHttpStatus !== null) {
                $message = 'Layanan pembaca PDF gagal memproses berkas (HTTP '.$parserHttpStatus.'). Periksa log layanan Python di server.';
            } else {
                $message = 'Layanan pembaca PDF tidak dapat dihubungi dan parser lokal belum tersedia. Pastikan layanan Python aktif di server CyberPanel.';
            }
            $import->update([
                'status' => 'FAILED',
                'page_count' => $pageCount,
                'parsed_at' => now(),
                'parser_version' => self::PARSER_VERSION,
                'error_message' => $message,
            ]);
            throw ValidationException::withMessages(['schedule_pdf' => $message]);
        }

        // Populate staging rows
        $rowCount = 0;
        $excludedNames = [];
        $resolvedLecturers = [];

        foreach ($parsedRows as $row) {
            $normalized = $row['normalized'] ?? $row;
            $lecturerName = trim($normalized['lecturer_name'] ?? $row['lecturer_name'] ?? '');
            $courseName = trim($normalized['course_name'] ?? $row['course_name'] ?? '');
            $courseCode = $normalized['course_code'] ?? $row['course_code'] ?? null;
            $className = trim($normalized['class_name'] ?? $row['class_name'] ?? '');
            $dayOfWeek = (int) ($normalized['day_of_week'] ?? $row['day_of_week'] ?? 0);
            $startTime = $normalized['start_time'] ?? $row['start_time'] ?? null;
            $endTime = $normalized['end_time'] ?? $row['end_time'] ?? null;
            $room = $normalized['room_name'] ?? $normalized['room'] ?? $row['room'] ?? null;
            $deliveryMode = strtoupper(trim((string) ($normalized['delivery_mode'] ?? $row['delivery_mode'] ?? '')));
            $deliveryMode = match ($deliveryMode) {
                'LURING' => 'OFFLINE',
                'DARING' => 'ONLINE',
                default => $deliveryMode,
            };
            $confidence = (float) ($row['confidence'] ?? 0);
            $sourcePage = (int) ($row['source']['page'] ?? 1);

            $isValidRow = $this->isPlausibleLecturerName($lecturerName)
                && preg_match_all('/\pL/u', $courseName) >= 3
                && mb_strtolower($courseName) !== 'mata kuliah';
            if (! $isValidRow && empty($row['issues'])) {
                continue;
            }

            $payload = [
                'lecturer_name' => $lecturerName,
                'lecturer_external_code' => $normalized['lecturer_external_code'] ?? $row['lecturer_external_code'] ?? null,
                'course_name' => $courseName,
                'course_code' => $courseCode,
                'class_name' => $className,
                'day_of_week' => $dayOfWeek,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'room_name' => $room,
                'delivery_mode' => $deliveryMode ?: null,
            ];
            $issues = $this->rowValidator->validate($payload, $row['issues'] ?? []);
            $hasErrors = collect($issues)->contains(fn (array $issue): bool => ($issue['severity'] ?? '') === 'ERROR');
            $nameKey = LecturerIdentityResolver::signature($lecturerName);
            $excludedFromPeriod = $period && ($excludedNames[$nameKey] ??= app(PeriodLecturerExclusions::class)->contains($period->id, $lecturerName));
            $canRestore = ! $hasErrors && $confidence >= 0.85;
            $resolutionKey = $nameKey.'|'.($payload['lecturer_external_code'] ?? '').'|'.(int) $canRestore;
            if (! $excludedFromPeriod && ! array_key_exists($resolutionKey, $resolvedLecturers)) {
                $resolvedLecturers[$resolutionKey] = $this->resolveLecturer($lecturerName, $canRestore, $payload['lecturer_external_code']);
            }
            $matchedLecturer = $excludedFromPeriod ? null : $resolvedLecturers[$resolutionKey];
            if ($period && $matchedLecturer && PeriodTarget::where('supervision_period_id', $period->id)
                ->where('lecturer_id', $matchedLecturer->id)->where('scope_status', 'EXCLUDED')->exists()) {
                $excludedFromPeriod = true;
                $matchedLecturer = null;
            }
            $status = $excludedFromPeriod ? 'SKIPPED' : ($hasErrors ? 'INVALID' : ($matchedLecturer && $confidence >= 0.85 ? 'VALID' : 'AMBIGUOUS'));

            ScheduleImportRow::create([
                'schedule_import_id' => $import->id,
                'row_no' => $rowCount + 1,
                'source_page' => $sourcePage,
                'source_locator' => $row['source'] ?? ['page' => $sourcePage],
                'raw_payload' => $row['raw'] ?? $row,
                'normalized_payload' => $payload,
                'validation_issues' => $issues,
                'parser_confidence' => $confidence,
                'validation_status' => $status,
                'matched_lecturer_id' => $matchedLecturer?->id,
                'is_approved' => $status === 'VALID',
                'skip_reason' => $excludedFromPeriod ? PeriodLecturerExclusions::SKIP_REASON : null,
            ]);
            $rowCount++;
        }

        $import->update([
            'status' => 'PENDING_REVIEW',
            'page_count' => $pageCount,
            'parsed_at' => now(),
            'parser_version' => self::PARSER_VERSION,
            'error_message' => $rowCount === 0 ? 'Tidak ada baris jadwal yang terbaca dari PDF.' : null,
        ]);

        return $import;
    }

    public function retryUnmatchedRows(ScheduleImport $existing): ScheduleImport
    {
        return DB::transaction(function () use ($existing): ScheduleImport {
            app(SchedulingLockService::class)->state();
            $import = ScheduleImport::query()->lockForUpdate()->findOrFail($existing->id);
            if (! in_array($import->status, ['COMMITTED', 'PENDING_REVIEW'], true)) {
                return $import;
            }

            $restoredCount = 0;
            $rows = ScheduleImportRow::query()
                ->where('schedule_import_id', $import->id)
                ->whereIn('validation_status', ['SKIPPED', 'AMBIGUOUS'])
                ->whereNull('matched_lecturer_id')
                ->whereNull('committed_schedule_id')
                ->where(function ($query): void {
                    $query->whereNull('skip_reason')
                        ->orWhere('skip_reason', 'Nama dosen dari PDF tidak terbaca atau tidak cocok dengan direktori dosen.');
                })
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $payload = $row->normalized_payload ?? [];
                if ($import->supervision_period_id && app(PeriodLecturerExclusions::class)
                    ->contains($import->supervision_period_id, (string) ($payload['lecturer_name'] ?? ''))) {
                    $row->update([
                        'skip_reason' => PeriodLecturerExclusions::SKIP_REASON,
                    ]);

                    continue;
                }
                $issues = $this->rowValidator->validate($payload, $row->validation_issues ?? []);
                if ((float) $row->parser_confidence < 0.85 || collect($issues)->contains(
                    fn (array $issue): bool => ($issue['severity'] ?? '') === 'ERROR'
                )) {
                    continue;
                }

                $lecturer = $this->resolveLecturer((string) ($payload['lecturer_name'] ?? ''), true, $payload['lecturer_external_code'] ?? null);
                if (! $lecturer || ($import->supervision_period_id && PeriodTarget::where('supervision_period_id', $import->supervision_period_id)
                    ->where('lecturer_id', $lecturer->id)->where('scope_status', 'EXCLUDED')->exists())) {
                    continue;
                }

                $row->update([
                    'matched_lecturer_id' => $lecturer->id,
                    'validation_status' => 'VALID',
                    'is_approved' => true,
                    'skip_reason' => null,
                ]);
                $restoredCount++;
            }

            if ($restoredCount > 0) {
                $import->update(['status' => 'PENDING_REVIEW']);
            }

            return $import;
        }, 3);
    }

    private function removeImportFile(string $path): void
    {
        try {
            if (Storage::disk('local')->delete($path)) {
                return;
            }
        } catch (\Throwable) {
        }
        Log::warning('Pembersihan berkas impor gagal.', ['file_path' => $path]);
    }

    private function localParserExecutable(): ?string
    {
        $configuredExecutable = config('services.python.binary');
        if (is_string($configuredExecutable) && trim($configuredExecutable) !== '') {
            return trim($configuredExecutable);
        }

        $relativePath = PHP_OS_FAMILY === 'Windows'
            ? 'python-service/venv/Scripts/python.exe'
            : 'python-service/venv/bin/python';
        $executable = base_path($relativePath);

        return is_file($executable) ? $executable : null;
    }

    /**
     * Resolve a parsed PDF name only when it uniquely identifies an active roster entry.
     */
    protected function resolveLecturer(string $lecturerName, bool $restoreReference = false, ?string $nidn = null): ?Lecturer
    {
        $lecturerName = trim($lecturerName);
        if (! $this->isPlausibleLecturerName($lecturerName)) {
            return null;
        }

        return app(LecturerIdentityResolver::class)->resolve($lecturerName, $nidn, $restoreReference);
    }

    private function isPlausibleLecturerName(string $lecturerName): bool
    {
        $name = trim($lecturerName);
        if (preg_match_all('/\pL/u', $name) < 4) {
            return false;
        }

        return ! preg_match('/^(?:team|tim(?:\s|$)|dosen pengampu|(?:senin|selasa|rabu|kamis|jumat|jum\x{2019}at|sabtu|minggu)|jadwal dihasilkan|lab(?:oratorium)?\s)/iu', $name);
    }
}
