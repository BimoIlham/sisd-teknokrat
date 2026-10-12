<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Lecturer;
use App\Models\ScheduleImport;
use App\Models\ScheduleImportRow;
use App\Models\TeachingSchedule;
use App\Models\User;
use App\Services\Academic\OccurrenceGeneratorService;
use App\Services\ScheduleImport\ScheduleCommitService;
use App\Services\ScheduleImport\ScheduleCourseResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SchedulePdfRepairTest extends TestCase
{
    use DatabaseTransactions;

    public function test_repair_preserves_schedule_ids_corrects_snapshots_and_holds_uncertain_rows(): void
    {
        [$import, $rows, $schedules, $manifest] = $this->createRepairFixture();
        $path = $this->writeManifest($manifest);
        try {
            $this->artisan('schedules:repair-pdf', ['file' => $path, '--dry-run' => true])->assertSuccessful();
            $this->assertSame('OFFLINE', $schedules[0]->fresh()->delivery_mode);
            $this->assertEmpty(Storage::disk('local')->allFiles('schedule-pdf-backups'));
            $this->artisan('schedules:repair-pdf', ['file' => $path])->assertSuccessful();

            $schedule = $schedules[0]->fresh();
            $this->assertSame('ONLINE', $schedule->delivery_mode);
            $this->assertSame('ONLINE 2', $schedule->room_name);
            $this->assertSame('Aplikasi Komputer (Teori)', $schedule->course->name);
            $this->assertSame('TS 26 A, TS 26 B, TI 26 A', $schedule->class_name);
            $this->assertSame($schedule->id, $rows[0]->fresh()->committed_schedule_id);
            $this->assertSame('ONLINE', $schedule->occurrences()->firstOrFail()->identity_snapshot['delivery_mode']);
            $this->assertSame('ONLINE 2', $schedule->occurrences()->firstOrFail()->identity_snapshot['room_name']);
            $this->assertSame('INVALID', $rows[1]->fresh()->validation_status);
            $this->assertSame('SKIPPED', $rows[2]->fresh()->validation_status);
            foreach ([$schedules[1], $schedules[2]] as $held) {
                $this->assertFalse($held->fresh()->is_active);
                $this->assertSame(0, $held->occurrences()->where('status', 'SCHEDULED')->count());
            }
            $this->assertSame('PENDING_REVIEW', $import->fresh()->status);
            $this->assertCount(1, Storage::disk('local')->allFiles('schedule-pdf-backups'));

            $rows[1]->refresh()->update([
                'normalized_payload' => $manifest['rows'][0]['normalized'],
                'validation_status' => 'VALID', 'validation_issues' => [], 'is_approved' => true,
            ]);
            $this->assertSame(1, app(ScheduleCommitService::class)->commit($import->fresh()));
            $this->assertSame(0, app(ScheduleCommitService::class)->commit($import->fresh()));
            $this->assertSame(3, TeachingSchedule::where('source_import_id', $import->id)->count());
            $this->assertTrue($schedules[1]->fresh()->is_active);
            $this->assertFalse($schedules[2]->fresh()->is_active);
        } finally {
            unlink($path);
        }
    }

    public function test_repair_rejects_changed_pdf_without_modifying_any_schedule(): void
    {
        [$import, $rows, $schedules, $manifest] = $this->createRepairFixture();
        Storage::disk('local')->put($import->stored_path, 'changed PDF');
        $path = $this->writeManifest($manifest);
        try {
            $this->artisan('schedules:repair-pdf', ['file' => $path])->assertFailed();
            $this->assertSame('OFFLINE', $schedules[0]->fresh()->delivery_mode);
            $this->assertTrue($schedules[1]->fresh()->is_active);
            $this->assertEmpty(Storage::disk('local')->allFiles('schedule-pdf-backups'));
        } finally {
            unlink($path);
        }
    }

    public function test_repair_rejects_changed_source_row_without_partial_updates(): void
    {
        [$import, $rows, $schedules, $manifest] = $this->createRepairFixture();
        $rows[1]->update(['raw_payload' => ['cell_text' => 'Manually changed']]);
        $path = $this->writeManifest($manifest);
        try {
            $this->artisan('schedules:repair-pdf', ['file' => $path])->assertFailed();
            $this->assertSame('OFFLINE', $schedules[0]->fresh()->delivery_mode);
            $this->assertSame('COMMITTED', $import->fresh()->status);
        } finally {
            unlink($path);
        }
    }

    /** @return array{ScheduleImport, list<ScheduleImportRow>, list<TeachingSchedule>, array<string, mixed>} */
    private function createRepairFixture(): array
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $term = AcademicTerm::firstOrCreate(['academic_year' => '2029/2030', 'semester' => 'GANJIL'], [
            'starts_on' => '2029-09-01', 'ends_on' => '2030-01-31', 'timezone' => 'Asia/Jakarta',
        ]);
        $lecturer = Lecturer::create(['name' => 'Dosen Uji Koreksi PDF', 'is_active' => true]);
        $bytes = '%PDF-1.7 '.fake()->uuid();
        $import = ScheduleImport::create([
            'academic_term_id' => $term->id, 'uploaded_by' => $user->id,
            'original_filename' => 'schedule.pdf', 'storage_disk' => 'local', 'stored_path' => 'imports/repair-test.pdf',
            'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes),
            'mime_type' => 'application/pdf', 'status' => 'COMMITTED',
        ]);
        Storage::disk('local')->put($import->stored_path, $bytes);
        $manifest = ['parser_version' => '1.1.0', 'imports' => [['id' => $import->id, 'sha256' => $import->sha256]], 'rows' => []];
        $payload = [
            'lecturer_name' => $lecturer->name, 'course_name' => 'Aplikasi Komputer (Teori)',
            'class_name' => 'TS 26 A, TS 26 B, TI 26 A', 'day_of_week' => 1,
            'start_time' => '07:00', 'end_time' => '08:40', 'delivery_mode' => 'ONLINE', 'room_name' => 'ONLINE 2',
        ];
        $rows = [];
        $schedules = [];
        $course = app(ScheduleCourseResolver::class)->resolve('TI 26 A Aplikasi Komputer (Teori) 2');
        foreach (['update', 'review', 'skip'] as $index => $action) {
            $oldPayload = array_replace($payload, ['delivery_mode' => 'OFFLINE', 'room_name' => 'R. 201']);
            $row = ScheduleImportRow::create([
                'schedule_import_id' => $import->id, 'row_no' => $index + 1, 'source_page' => 1,
                'raw_payload' => ['cell_text' => "source {$index}"], 'normalized_payload' => $oldPayload,
                'matched_lecturer_id' => $lecturer->id, 'validation_status' => 'VALID', 'is_approved' => true,
            ]);
            $schedule = TeachingSchedule::create([
                'academic_term_id' => $term->id, 'lecturer_id' => $lecturer->id, 'course_id' => $course->id,
                'class_name' => 'TS 26 A', 'room_name' => 'R. 201', 'delivery_mode' => 'OFFLINE',
                'day_of_week' => 1, 'starts_local' => '07:00', 'ends_local' => '08:40',
                'source_import_id' => $import->id, 'source_row_id' => $row->id, 'is_active' => true,
            ]);
            app(OccurrenceGeneratorService::class)->generateForSchedule($schedule);
            $manifest['rows'][] = [
                'row_id' => $row->id, 'import_id' => $import->id, 'expected_raw_payload' => $row->raw_payload,
                'action' => $action, 'reason' => 'Penanda tanpa jadwal', 'normalized' => $payload,
                'source' => ['page' => 1], 'raw' => ['cell_text' => "corrected source {$index}"],
                'issues' => $action === 'review' ? [['code' => 'CONFLICTING_MODE', 'severity' => 'ERROR', 'message' => 'Conflicting mode']] : [],
                'lecturer_id' => $lecturer->id,
            ];
            $rows[] = $row;
            $schedules[] = $schedule;
        }

        return [$import, $rows, $schedules, $manifest];
    }

    /** @param array<string, mixed> $manifest */
    private function writeManifest(array $manifest): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf-repair-');
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

        return $path;
    }
}
