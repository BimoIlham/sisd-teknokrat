<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Lecturer;
use App\Models\ScheduleImport;
use App\Models\ScheduleImportRow;
use App\Models\StudyProgram;
use App\Models\TeachingOccurrence;
use App\Models\TeachingSchedule;
use App\Services\ScheduleImport\ScheduleCourseResolver;
use App\Services\ScheduleImport\ScheduleRowValidator;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

#[Signature('schedules:repair-pdf {file : Manifest JSON hasil pencocokan ulang dengan PDF sumber} {--dry-run : Validasi tanpa mengubah data}')]
#[Description('Koreksi jadwal impor dari PDF sumber dengan cadangan dan mempertahankan ID jadwal')]
class RepairSchedulePdfData extends Command
{
    public function handle(ScheduleRowValidator $validator, ScheduleCourseResolver $courses): int
    {
        try {
            $path = (string) $this->argument('file');
            if (! is_file($path)) {
                throw new InvalidArgumentException('Manifest tidak ditemukan.');
            }
            $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $this->validateManifest($manifest, $validator);
            $counts = collect($manifest['rows'])->countBy('action');
            $this->info("Koreksi: {$counts->get('update', 0)}; perlu pemeriksaan: {$counts->get('review', 0)}; bukan jadwal/fragmen: {$counts->get('skip', 0)}.");
            if ($this->option('dry-run')) {
                return self::SUCCESS;
            }

            $backup = 'schedule-pdf-backups/'.now()->format('Ymd_His_u').'.json';
            DB::transaction(function () use ($manifest, $validator, $courses, $backup): void {
                $importIds = array_column($manifest['imports'], 'id');
                ScheduleImport::whereIn('id', $importIds)->lockForUpdate()->get();
                ScheduleImportRow::whereIn('schedule_import_id', $importIds)->lockForUpdate()->get();
                TeachingSchedule::whereIn('source_import_id', $importIds)->lockForUpdate()->get();
                $this->validateManifest($manifest, $validator);
                $scheduleIds = TeachingSchedule::whereIn('source_import_id', $importIds)->pluck('id');
                $saved = Storage::disk('local')->put($backup, json_encode([
                    'manifest' => $manifest,
                    'imports' => ScheduleImport::whereIn('id', $importIds)->get()->toArray(),
                    'rows' => ScheduleImportRow::whereIn('schedule_import_id', $importIds)->get()->toArray(),
                    'schedules' => TeachingSchedule::whereIn('id', $scheduleIds)->get()->toArray(),
                    'occurrences' => TeachingOccurrence::whereIn('teaching_schedule_id', $scheduleIds)->get()->toArray(),
                    'lecturers' => Lecturer::all()->toArray(),
                    'courses' => Course::all()->toArray(),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                if (! $saved) {
                    throw new InvalidArgumentException('Cadangan tidak berhasil disimpan; koreksi dibatalkan.');
                }

                foreach ($manifest['rows'] as $entry) {
                    $row = ScheduleImportRow::findOrFail($entry['row_id']);
                    $schedule = TeachingSchedule::where('source_row_id', $row->id)->sole();
                    $row->committed_schedule_id = $schedule->id;
                    if ($entry['action'] === 'skip') {
                        $row->fill(['validation_status' => 'SKIPPED', 'is_approved' => false, 'skip_reason' => $entry['reason']]);
                    } else {
                        $lecturer = $this->resolveLecturer($entry);
                        $row->fill([
                            'normalized_payload' => $entry['normalized'],
                            'raw_payload' => $entry['raw'],
                            'source_locator' => $entry['source'],
                            'source_page' => $entry['source']['page'],
                            'matched_lecturer_id' => $lecturer?->id,
                            'validation_issues' => $entry['issues'],
                            'validation_status' => $entry['action'] === 'update' ? 'VALID' : 'INVALID',
                            'parser_confidence' => $entry['action'] === 'update' ? 0.95 : 0.60,
                            'is_approved' => $entry['action'] === 'update',
                            'skip_reason' => null,
                        ]);
                    }

                    if ($entry['action'] !== 'update') {
                        $schedule->update(['is_active' => false]);
                        $schedule->occurrences()->update([
                            'status' => 'CANCELLED',
                            'exception_reason' => $entry['reason'] ?? 'Hasil pembacaan PDF perlu diperiksa sebelum jadwal diaktifkan.',
                        ]);
                        $row->save();

                        continue;
                    }

                    $payload = $entry['normalized'];
                    $course = $courses->resolve($payload['course_name'], $payload['course_code'] ?? null);
                    $row->matched_course_id = $course->id;
                    $schedule->update([
                        'lecturer_id' => $lecturer->id,
                        'course_id' => $course->id,
                        'class_name' => $payload['class_name'],
                        'room_name' => $payload['room_name'],
                        'delivery_mode' => $payload['delivery_mode'],
                        'day_of_week' => $payload['day_of_week'],
                        'starts_local' => $payload['start_time'],
                        'ends_local' => $payload['end_time'],
                        'source_payload' => $payload,
                        'is_active' => true,
                    ]);
                    foreach ($schedule->occurrences()->get() as $occurrence) {
                        $occurrence->identity_snapshot = array_replace($occurrence->identity_snapshot ?? [], [
                            'lecturer_id' => $lecturer->id, 'lecturer_name' => $lecturer->name,
                            'course_id' => $course->id, 'course_name' => $course->name,
                            'class_name' => $schedule->class_name,
                            'delivery_mode' => $schedule->delivery_mode, 'room_name' => $schedule->room_name,
                        ]);
                        if (! $occurrence->is_exception && $occurrence->status === 'SCHEDULED') {
                            $date = $occurrence->occurs_on->toDateString();
                            $timezone = $schedule->academicTerm->timezone ?: 'Asia/Jakarta';
                            $occurrence->starts_at = Carbon::parse($date.' '.$schedule->starts_local, $timezone)->utc();
                            $occurrence->ends_at = Carbon::parse($date.' '.$schedule->ends_local, $timezone)->utc();
                        }
                        $occurrence->save();
                    }
                    $row->save();
                }

                foreach ($importIds as $importId) {
                    $pending = ScheduleImportRow::where('schedule_import_id', $importId)->where('validation_status', 'INVALID')->count();
                    ScheduleImport::findOrFail($importId)->update([
                        'parser_version' => $manifest['parser_version'],
                        'status' => $pending ? 'PENDING_REVIEW' : 'COMMITTED',
                        'error_code' => $pending ? 'SOURCE_REVIEW' : null,
                        'error_message' => $pending ? "{$pending} sel PDF perlu pemeriksaan dan belum aktif sebagai jadwal." : null,
                    ]);
                }
            });
            $this->info('Koreksi selesai. Cadangan: '.Storage::disk('local')->path($backup));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @param array<string, mixed> $manifest */
    private function validateManifest(array $manifest, ScheduleRowValidator $validator): void
    {
        if (($manifest['parser_version'] ?? '') !== '1.1.0' || empty($manifest['imports']) || empty($manifest['rows'])) {
            throw new InvalidArgumentException('Manifest koreksi tidak lengkap atau versi parser tidak sesuai.');
        }
        $importIds = array_column($manifest['imports'], 'id');
        foreach ($manifest['imports'] as $source) {
            $import = ScheduleImport::findOrFail($source['id']);
            $disk = Storage::disk($import->storage_disk);
            if ($source['sha256'] !== $import->sha256 || ! $disk->exists($import->stored_path)
                || hash('sha256', $disk->get($import->stored_path)) !== $source['sha256']) {
                throw new InvalidArgumentException('PDF sumber berubah atau tidak tersedia: '.$import->id);
            }
        }
        $rowIds = array_column($manifest['rows'], 'row_id');
        $existingIds = ScheduleImportRow::whereIn('schedule_import_id', $importIds)->pluck('id')->all();
        sort($rowIds);
        sort($existingIds);
        if ($rowIds !== $existingIds) {
            throw new InvalidArgumentException('Manifest harus mencakup setiap baris impor tepat satu kali.');
        }
        foreach ($manifest['rows'] as $entry) {
            $row = ScheduleImportRow::findOrFail($entry['row_id']);
            if ($row->schedule_import_id !== $entry['import_id'] || $row->raw_payload != $entry['expected_raw_payload']) {
                throw new InvalidArgumentException('Data baris telah berubah sejak pemeriksaan PDF: '.$row->id);
            }
            $schedule = TeachingSchedule::where('source_row_id', $row->id)->sole();
            if ($schedule->source_import_id !== $row->schedule_import_id
                || $schedule->occurrences()->whereHas('assignments')->exists()) {
                throw new InvalidArgumentException('Referensi jadwal berbeda atau sudah digunakan supervisi: '.$row->id);
            }
            if (! in_array($entry['action'], ['update', 'review', 'skip'], true)) {
                throw new InvalidArgumentException('Tindakan koreksi tidak dikenal.');
            }
            if ($entry['action'] === 'skip') {
                if (empty($entry['reason'])) {
                    throw new InvalidArgumentException('Alasan pengabaian baris wajib diisi.');
                }

                continue;
            }
            if (! empty($entry['lecturer_id'])) {
                $lecturer = Lecturer::findOrFail($entry['lecturer_id']);
                if (! $lecturer->is_active) {
                    throw new InvalidArgumentException('Dosen hasil pemetaan tidak aktif: '.$lecturer->id);
                }
            }
            if (! empty($entry['lecturer_data'])) {
                $person = $entry['lecturer_data'];
                if (! preg_match('/^\d{10}$/', $person['nidn'] ?? '') || empty($person['name'])) {
                    throw new InvalidArgumentException('Identitas dosen baru belum lengkap.');
                }
                StudyProgram::query()
                    ->where('code', $person['program_code'])
                    ->where('is_active', true)
                    ->whereHas('faculty', fn ($query) => $query->where('is_active', true))
                    ->sole();
            }
            if ($entry['action'] === 'update') {
                $issues = $validator->validate($entry['normalized'], $entry['issues']);
                if (collect($issues)->contains(fn (array $issue): bool => ($issue['severity'] ?? '') === 'ERROR')
                    || empty($entry['lecturer_id']) && empty($entry['lecturer_data'])
                    || $schedule->day_of_week !== $entry['normalized']['day_of_week']) {
                    throw new InvalidArgumentException('Koreksi belum valid atau memerlukan perubahan tanggal pertemuan: '.$row->id);
                }
            }
        }
    }

    /** @param array<string, mixed> $entry */
    private function resolveLecturer(array $entry): ?Lecturer
    {
        if (! empty($entry['lecturer_id'])) {
            return Lecturer::findOrFail($entry['lecturer_id']);
        }
        if (empty($entry['lecturer_data'])) {
            return null;
        }
        $person = $entry['lecturer_data'];
        $program = StudyProgram::query()
            ->where('code', $person['program_code'])
            ->where('is_active', true)
            ->whereHas('faculty', fn ($query) => $query->where('is_active', true))
            ->sole();

        return Lecturer::firstOrCreate(['external_code' => $person['nidn']], [
            'name' => $person['name'], 'study_program_id' => $program->id,
            'faculty_id' => $program->faculty_id, 'is_active' => true,
        ]);
    }
}
