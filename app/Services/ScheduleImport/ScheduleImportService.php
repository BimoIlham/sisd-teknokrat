<?php

namespace App\Services\ScheduleImport;

use App\Models\AcademicTerm;
use App\Models\Lecturer;
use App\Models\ScheduleImport;
use App\Models\ScheduleImportRow;
use App\Models\User;
use App\Services\PythonServiceConnection;
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
        $existing = ScheduleImport::where('academic_term_id', $term->id)
            ->where('sha256', $sha256)
            ->first();

        if ($existing && ($existing->status === 'COMMITTED' || $existing->rows()->whereNotNull('committed_schedule_id')->exists())) {
            return $existing;
        }

        $storedPath = $file->storeAs('imports', $filename, 'local');
        if (! is_string($storedPath) || $storedPath === '') {
            throw new \RuntimeException('Berkas impor gagal disimpan.');
        }
        $previousPath = $existing?->stored_path;
        try {
            $import = DB::transaction(function () use ($existing, $term, $file, $uploader, $storedPath, $sha256): ScheduleImport {
                DB::afterRollBack(fn () => $this->removeImportFile($storedPath));
                if ($existing && $existing->status !== 'COMMITTED') {
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
                        'uploaded_by' => $uploader->id,
                        'original_filename' => $file->getClientOriginalName(),
                        'storage_disk' => 'local',
                        'stored_path' => $storedPath,
                        'sha256' => $sha256,
                        'size_bytes' => $file->getSize(),
                        'mime_type' => $file->getClientMimeType() ?: 'application/pdf',
                        'parser_name' => 'pdf-schedule-parser',
                        'parser_version' => '1.1.0',
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
                $connection->assertAuthenticated($response);
                if (in_array($response->status(), [413, 422], true) || ($response->status() === 503 && $response->json('detail.code') === 'RESOURCE_BUSY')) {
                    $message = 'PDF tidak memenuhi batas pemrosesan atau service sedang sibuk. Maksimal 15 MB, 200 halaman, dan teks yang dapat diproses.';
                    $import->update(['status' => 'FAILED', 'error_message' => $message]);
                    throw ValidationException::withMessages(['schedule_pdf' => $message]);
                }

                if ($response->successful()) {
                    $data = $response->json();
                    if (version_compare($data['parser']['version'] ?? '0.0.0', '1.1.0', '>=')) {
                        $parsedRows = $data['rows'] ?? [];
                        $pageCount = $data['page_count'] ?? 1;
                    }
                }
            } catch (ConnectionException $e) {
                Log::info('Python parser unavailable; attempting CLI fallback', ['exception_class' => $e::class]);
            }
        }

        // 2. Direct Python CLI execution fallback (ensures 100% availability without background daemon)
        if (empty($parsedRows)) {
            $pythonExe = base_path('python-service/venv/Scripts/python.exe');
            $cliScript = base_path('python-service/cli_parse.py');

            if (file_exists($pythonExe) && file_exists($cliScript)) {
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
                            if (! empty($data['rows'])) {
                                $parsedRows = $data['rows'];
                                $pageCount = $data['page_count'] ?? 1;
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

        // Populate staging rows
        $rowCount = 0;

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
            $matchedLecturer = $this->resolveLecturer($lecturerName, ! $hasErrors && $confidence >= 0.85);
            $status = $hasErrors ? 'INVALID' : ($matchedLecturer && $confidence >= 0.85 ? 'VALID' : 'AMBIGUOUS');

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
            ]);
            $rowCount++;
        }

        $import->update([
            'status' => 'PENDING_REVIEW',
            'page_count' => $pageCount,
            'parsed_at' => now(),
            'parser_version' => '1.1.0',
            'error_message' => $rowCount === 0 ? 'Tidak ada baris jadwal yang terbaca dari PDF.' : null,
        ]);

        return $import;
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

    /**
     * Resolve a parsed PDF name only when it uniquely identifies an active roster entry.
     */
    protected function resolveLecturer(string $lecturerName, bool $restoreReference = false): ?Lecturer
    {
        $lecturerName = trim($lecturerName);
        if (! $this->isPlausibleLecturerName($lecturerName)) {
            return null;
        }

        $activeLecturers = Lecturer::where('is_active', true)->get(['id', 'name']);
        $normalizedName = $this->normalizeLecturerName($lecturerName);
        $exactMatches = $activeLecturers->filter(
            fn (Lecturer $lecturer): bool => $this->normalizeLecturerName($lecturer->name) === $normalizedName
        );

        if ($exactMatches->count() === 1) {
            return $exactMatches->first();
        }

        if ($exactMatches->isNotEmpty()) {
            return null;
        }

        $normalizedBaseName = $this->normalizeLecturerBaseName($lecturerName);
        $baseNameMatches = $activeLecturers->filter(
            fn (Lecturer $lecturer): bool => $this->normalizeLecturerBaseName($lecturer->name) === $normalizedBaseName
        );

        if ($baseNameMatches->count() === 1) {
            return $baseNameMatches->first();
        }

        return $baseNameMatches->isEmpty() && $restoreReference
            ? app(ScheduleImportReferences::class)->restoreLecturer($lecturerName)
            : null;
    }

    private function isPlausibleLecturerName(string $lecturerName): bool
    {
        $name = trim($lecturerName);
        if (preg_match_all('/\pL/u', $name) < 4) {
            return false;
        }

        return ! preg_match('/^(?:dosen pengampu|(?:senin|selasa|rabu|kamis|jumat|jum\x{2019}at|sabtu|minggu)|jadwal dihasilkan|lab(?:oratorium)?\s)/iu', $name);
    }

    private function normalizeLecturerBaseName(string $lecturerName): string
    {
        return ScheduleImportReferences::normalizeBaseName($lecturerName);
    }

    private function normalizeLecturerName(string $lecturerName): string
    {
        $normalized = preg_replace('/[^\pL\pN]+/u', ' ', $lecturerName);

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $normalized ?? $lecturerName)));
    }
}
