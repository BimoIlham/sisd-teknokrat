<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Faculty;
use App\Models\Lecturer;
use App\Models\ScheduleImport;
use App\Models\ScheduleImportRow;
use App\Models\StudyProgram;
use App\Models\SupervisionPeriod;
use App\Models\TeachingOccurrence;
use App\Models\TeachingSchedule;
use App\Models\User;
use App\Services\ScheduleImport\LecturerIdentityResolver;
use App\Services\ScheduleImport\ScheduleCommitService;
use App\Services\ScheduleImport\ScheduleImportService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScheduleImportTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected AcademicTerm $term;

    protected Faculty $faculty;

    protected StudyProgram $prodi;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.python.secret' => Str::random(48), 'services.python.url' => 'http://127.0.0.1:18100']);

        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('sisd_teknokrat_test', app('db')->connection()->getDatabaseName());

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create([
            'name' => 'Administrator LPM',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->admin->assignRole('superadmin');

        $this->term = AcademicTerm::firstOrCreate([
            'academic_year' => '2026/2027',
            'semester' => 'GANJIL',
        ], [
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-01-31',
        ]);
        $this->faculty = Faculty::firstOrCreate(['code' => 'FTIK'], [
            'name' => 'Fakultas Teknik dan Ilmu Komputer',
            'is_active' => true,
        ]);
        $this->prodi = StudyProgram::firstOrCreate(['code' => 'IF'], [
            'faculty_id' => $this->faculty->id,
            'name' => 'Informatika',
            'is_active' => true,
        ]);
    }

    public function test_guest_cannot_access_import_index_or_upload(): void
    {
        $response = $this->get(route('lpm.import.index'));
        $response->assertRedirect(route('login'));

        $postResponse = $this->post(route('lpm.import.store'), []);
        $postResponse->assertRedirect(route('login'));
    }

    public function test_pdf_upload_activates_valid_rows_and_skips_unclear_rows_without_review_page(): void
    {
        Storage::fake('local');
        $payload = $this->validOnlinePayload();
        $lecturer = $this->createImportLecturer($payload['lecturer_name']);
        $otherLecturer = $this->createImportLecturer('Dosen Master Tanpa Jadwal PDF');
        $activePeriod = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id,
            'name' => 'Periode Upload Aktif',
            'starts_on' => $this->term->starts_on,
            'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE',
            'created_by' => $this->admin->id,
        ]);
        $closedPeriod = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id,
            'name' => 'Periode Upload Lama',
            'starts_on' => $this->term->starts_on,
            'ends_on' => $this->term->ends_on,
            'status' => 'CLOSED',
            'created_by' => $this->admin->id,
        ]);
        Http::fake(['*' => Http::response([
            'parser' => ['version' => '1.3.0'],
            'rows' => [
                ['confidence' => 0.98, 'normalized' => $payload],
                ['confidence' => 0.98, 'normalized' => array_replace($payload, [
                    'course_name' => 'Aplikasi Komputer ◆',
                ])],
            ],
            'page_count' => 1,
        ])]);

        $response = $this->actingAs($this->admin)->post(route('lpm.import.store'), [
            'academic_term_id' => $this->term->id,
            'schedule_pdf' => UploadedFile::fake()->createWithContent('jadwal-langsung.pdf', '%PDF-1.7 direct-import'),
        ]);

        $import = ScheduleImport::sole();
        $response->assertRedirect(route('lpm.lecturers.index'))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '1 jadwal aktif')
                && str_contains($message, '1 baris dilewati'));
        $this->assertSame('COMMITTED', $import->fresh()->status);
        $this->assertSame(1, TeachingSchedule::where('source_import_id', $import->id)->count());
        $this->assertSame(1, $import->rows()->where('validation_status', 'SKIPPED')->whereNotNull('skip_reason')->count());
        $this->assertSame(1, $import->rows()->whereNotNull('committed_schedule_id')->count());
        $this->assertSame([$lecturer->id], $activePeriod->targets()->pluck('lecturer_id')->all());
        $this->assertSame(0, $closedPeriod->targets()->count());
        $this->get(route('lpm.lecturers.index', ['period_id' => $activePeriod->id]))
            ->assertOk()
            ->assertViewHas('lecturers', fn ($lecturers) => $lecturers->contains('id', $lecturer->id)
                && ! $lecturers->contains('id', $otherLecturer->id));
        $this->get(route('lpm.import.show', $import))->assertRedirect(route('lpm.lecturers.index'));
    }

    public function test_reuploading_committed_pdf_reports_existing_active_schedules_and_previous_skipped_rows(): void
    {
        Storage::fake('local');
        $this->createActiveImportPeriod();
        $payload = $this->validOnlinePayload();
        $this->createImportLecturer($payload['lecturer_name']);
        Http::fake(['*' => Http::response([
            'parser' => ['version' => '1.3.0'],
            'rows' => [
                ['confidence' => 0.98, 'normalized' => $payload],
                ['confidence' => 0.98, 'normalized' => array_replace($payload, ['course_name' => 'Aplikasi Komputer ◆'])],
            ],
            'page_count' => 1,
        ])]);
        $fileContent = '%PDF-1.7 repeated-schedule-import';

        $this->actingAs($this->admin)->post(route('lpm.import.store'), [
            'academic_term_id' => $this->term->id,
            'schedule_pdf' => UploadedFile::fake()->createWithContent('jadwal.pdf', $fileContent),
        ])->assertRedirect(route('lpm.lecturers.index'));

        $response = $this->post(route('lpm.import.store'), [
            'academic_term_id' => $this->term->id,
            'schedule_pdf' => UploadedFile::fake()->createWithContent('jadwal-kedua.pdf', $fileContent),
        ]);

        $response->assertRedirect(route('lpm.lecturers.index'))
            ->assertSessionHas('skipped_count', 1)
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '1 jadwal aktif')
                && str_contains($message, '1 baris dilewati'));
        $this->assertSame(1, ScheduleImport::count());
        $this->assertSame(1, TeachingSchedule::count());
        Http::assertSentCount(1);
    }

    public function test_pdf_upload_with_no_confident_rows_returns_to_upload_page_without_activating_schedules(): void
    {
        Storage::fake('local');
        $this->createActiveImportPeriod();
        Http::fake(['*' => Http::response([
            'parser' => ['version' => '1.3.0'],
            'rows' => [[
                'normalized' => array_replace($this->validOnlinePayload(), ['lecturer_name' => 'Nama Tidak Terdaftar']),
            ]],
            'page_count' => 1,
        ])]);

        $response = $this->from(route('lpm.import.index'))->actingAs($this->admin)->post(route('lpm.import.store'), [
            'academic_term_id' => $this->term->id,
            'schedule_pdf' => UploadedFile::fake()->createWithContent('jadwal-tidak-yakin.pdf', '%PDF-1.7 no-safe-rows'),
        ]);

        $response->assertRedirect(route('lpm.import.names.show', ScheduleImport::sole()))
            ->assertSessionHas('error', 'Tidak ada baris jadwal yang dapat diaktifkan. 1 baris tidak cocok dengan dosen aktif di Direktori Dosen; 1 baris memiliki hasil pembacaan PDF yang kurang yakin.');
        $this->assertSame(0, TeachingSchedule::count());
        $this->assertSame('PENDING_REVIEW', ScheduleImport::sole()->status);
    }

    public function test_pdf_upload_explains_when_all_rows_have_invalid_schedule_fields(): void
    {
        Storage::fake('local');
        $this->createActiveImportPeriod();
        $payload = array_replace($this->validOnlinePayload(), ['start_time' => null, 'end_time' => null]);
        $this->createImportLecturer($payload['lecturer_name']);
        Http::fake(['*' => Http::response([
            'parser' => ['version' => '1.3.0'],
            'rows' => [['confidence' => 0.98, 'normalized' => $payload]],
            'page_count' => 1,
        ])]);

        $response = $this->from(route('lpm.import.index'))->actingAs($this->admin)->post(route('lpm.import.store'), [
            'academic_term_id' => $this->term->id,
            'schedule_pdf' => UploadedFile::fake()->createWithContent('jadwal-tanpa-jam.pdf', '%PDF-1.7 missing-time'),
        ]);

        $response->assertRedirect(route('lpm.import.index'))
            ->assertSessionHas('error', 'Tidak ada baris jadwal yang dapat diaktifkan. 1 baris memiliki data jadwal yang tidak lengkap atau tidak valid.');
        $this->assertSame(0, TeachingSchedule::count());
        $this->assertSame('INVALID', ScheduleImport::sole()->rows()->sole()->validation_status);
    }

    public function test_import_page_shows_only_semester_and_academic_year_in_the_active_period_card(): void
    {
        $period = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id,
            'name' => 'Supervisi Semester Ganjil 2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-01-31',
            'status' => 'ACTIVE',
            'created_by' => $this->admin->id,
        ]);

        $this->assertSame('Semester Ganjil · Tahun Ajaran 2026/2027', $period->semester_display);
        $this->actingAs($this->admin)->get(route('lpm.import.index'))
            ->assertOk()
            ->assertSee('Semester Ganjil · Tahun Ajaran 2026/2027')
            ->assertDontSee($period->name);
    }

    public function test_schedule_import_model_maps_uploaded_by_and_aliases_correctly(): void
    {
        // Test that creating with uploaded_by works without default value errors
        $import = ScheduleImport::create([
            'academic_term_id' => $this->term->id,
            'uploaded_by' => $this->admin->id,
            'original_filename' => 'jadwal_test.pdf',
            'storage_disk' => 'local',
            'stored_path' => 'imports/sample.pdf',
            'sha256' => hash('sha256', 'sample'),
            'size_bytes' => 12345,
            'mime_type' => 'application/pdf',
            'status' => 'PENDING_REVIEW',
        ]);

        $this->assertDatabaseHas('schedule_imports', [
            'id' => $import->id,
            'uploaded_by' => $this->admin->id,
            'original_filename' => 'jadwal_test.pdf',
        ]);

        // Test alias accessors
        $this->assertEquals('jadwal_test.pdf', $import->file_name);
        $this->assertEquals('imports/sample.pdf', $import->file_path);
        $this->assertEquals(12345, $import->file_size);
    }

    public function test_schedule_import_row_accessors_and_mutators(): void
    {
        $import = ScheduleImport::create([
            'academic_term_id' => $this->term->id,
            'uploaded_by' => $this->admin->id,
            'original_filename' => 'jadwal_test.pdf',
            'storage_disk' => 'local',
            'stored_path' => 'imports/sample.pdf',
            'sha256' => hash('sha256', 'sample_row_test'),
            'size_bytes' => 12345,
            'mime_type' => 'application/pdf',
            'status' => 'PENDING_REVIEW',
        ]);

        $row = ScheduleImportRow::create([
            'schedule_import_id' => $import->id,
            'row_no' => 1,
            'source_page' => 1,
            'normalized_payload' => [
                'lecturer_name' => 'Dr. Test Dosen, M.Kom.',
                'course_name' => 'Kecerdasan Buatan',
                'course_code' => 'IF301',
                'class_name' => 'IF-23-A',
                'day_of_week' => 1,
                'start_time' => '07:10',
                'end_time' => '08:50',
                'room_name' => 'Lab Komputer 3',
                'delivery_mode' => 'OFFLINE',
            ],
            'parser_confidence' => 0.95,
            'validation_status' => 'VALID',
        ]);

        $this->assertEquals('Dr. Test Dosen, M.Kom.', $row->parsed_lecturer_name);
        $this->assertEquals('Kecerdasan Buatan', $row->parsed_course_name);
        $this->assertEquals('IF-23-A', $row->parsed_class_name);
        $this->assertEquals(1, $row->parsed_day_of_week);
        $this->assertEquals('07:10', $row->parsed_start_time);
        $this->assertEquals('08:50', $row->parsed_end_time);
        $this->assertEquals('Lab Komputer 3', $row->parsed_room);
        $this->assertEquals('OFFLINE', $row->parsed_delivery_mode);
    }

    public function test_schedule_import_rejects_pdf_headers_and_symbols_as_lecturers(): void
    {
        $invalidLecturerCount = Lecturer::whereIn('name', ['---', 'Dosen Pengampu'])->count();
        Http::fake(['*' => Http::response([
            'parser' => ['version' => '1.3.0'],
            'rows' => [
                ['normalized' => ['lecturer_name' => '---', 'course_name' => '---']],
                ['normalized' => ['lecturer_name' => 'Dosen Pengampu', 'course_name' => 'Metode Penelitian']],
                ['confidence' => 0.95, 'normalized' => [
                    'lecturer_name' => 'Dosen Uji Jadwal Valid', 'course_name' => 'Metode Penelitian',
                    'class_name' => 'IF 26 A', 'day_of_week' => 1,
                    'start_time' => '08:00', 'end_time' => '09:40',
                    'room_name' => 'ONLINE 2', 'delivery_mode' => 'ONLINE',
                ]],
            ],
            'page_count' => 1,
        ])]);

        $import = app(ScheduleImportService::class)->import(
            UploadedFile::fake()->create('jadwal-validasi.pdf', 10, 'application/pdf'),
            $this->term,
            $this->admin
        );

        $this->assertSame(['AMBIGUOUS'], $import->rows()->orderBy('row_no')->pluck('validation_status')->all());
        $this->assertSame($invalidLecturerCount, Lecturer::whereIn('name', ['---', 'Dosen Pengampu'])->count());
        $this->assertFalse(Lecturer::where('name', 'Dosen Uji Jadwal Valid')->exists());
        $this->assertNull($import->rows()->firstOrFail()->matched_lecturer_id);
        $this->assertFalse($import->rows()->firstOrFail()->is_approved);
    }

    public function test_pdf_rows_with_suspicious_symbols_or_encoding_are_not_activated(): void
    {
        $lecturer = $this->createImportLecturer('Dosen Uji Simbol');
        $symbolPayload = array_replace($this->validOnlinePayload(), [
            'lecturer_name' => $lecturer->name." \u{25C6}",
            'course_name' => 'Aplikasi Komputer ◆',
        ]);
        $encodingPayload = array_replace($this->validOnlinePayload(), [
            'lecturer_name' => $lecturer->name,
            'course_name' => 'Aplikasi Komputer â€“ Teori',
        ]);
        Http::fake(['*' => Http::response([
            'parser' => ['version' => '1.3.0'],
            'rows' => [
                ['normalized' => $symbolPayload, 'confidence' => 0.98],
                ['normalized' => $encodingPayload, 'confidence' => 0.98],
            ],
            'page_count' => 1,
        ])]);

        $import = app(ScheduleImportService::class)->import(
            UploadedFile::fake()->createWithContent('jadwal-simbol.pdf', '%PDF-1.7 symbol-test'),
            $this->term,
            $this->admin
        );
        $rows = $import->rows()->orderBy('row_no')->get();

        $this->assertSame(['INVALID', 'INVALID'], $rows->pluck('validation_status')->all());
        foreach ($rows as $row) {
            $this->assertFalse($row->is_approved);
            $this->assertContains('SUSPICIOUS_CHARACTERS', collect($row->validation_issues)->pluck('code')->all());
        }
        try {
            app(ScheduleCommitService::class)->commit($import);
            $this->fail('PDF rows containing suspicious symbols must not be activated.');
        } catch (ValidationException) {
            $this->assertSame(0, TeachingSchedule::where('source_import_id', $import->id)->count());
        }
        $this->assertSame(0, TeachingSchedule::where('source_import_id', $import->id)->count());
    }

    public function test_pdf_lecturer_name_matches_unique_roster_base_without_losing_academic_titles(): void
    {
        $lecturerCountBefore = Lecturer::count();
        $similarLecturer = Lecturer::create([
            'name' => 'Berlintina Permatasari, S.P., M.M.',
            'normalized_name' => 'berlintina permatasari, s.p., m.m.',
            'external_code' => '0215028706',
            'is_active' => true,
        ]);
        $lecturer = Lecturer::create([
            'name' => 'Permata, M.Si',
            'normalized_name' => 'permata, m.si',
            'external_code' => '0229038201',
            'is_active' => true,
        ]);

        Http::fake(['*' => Http::response([
            'parser' => ['version' => '1.3.0'],
            'rows' => [[
                'confidence' => 0.95,
                'normalized' => [
                    'lecturer_name' => 'Permata, S.Si., M.Si.',
                    'course_name' => 'Matematika Diskrit',
                    'class_name' => 'IF 26 A',
                    'day_of_week' => 1,
                    'start_time' => '13:00',
                    'end_time' => '14:40',
                    'room_name' => '301 ICT B (100)',
                    'delivery_mode' => 'OFFLINE',
                ],
            ]],
            'page_count' => 1,
        ])]);

        $import = app(ScheduleImportService::class)->import(
            UploadedFile::fake()->createWithContent('permata.pdf', '%PDF-1.7 permata-test'),
            $this->term,
            $this->admin
        );
        $row = $import->rows()->sole();

        $this->assertSame($lecturer->id, $row->matched_lecturer_id);
        $this->assertSame('Permata, S.Si., M.Si.', $row->parsed_lecturer_name);
        $this->assertSame('VALID', $row->validation_status);
        $this->assertSame('Berlintina Permatasari, S.P., M.M.', $similarLecturer->fresh()->name);
        $this->assertSame('Permata, M.Si', $lecturer->fresh()->name);
        $this->assertSame($lecturerCountBefore + 2, Lecturer::count());

        Lecturer::create([
            'name' => 'Permata, S.Pd.',
            'normalized_name' => 'permata, s.pd.',
            'external_code' => '0229038202',
            'is_active' => true,
        ]);
        $ambiguousImport = app(ScheduleImportService::class)->import(
            UploadedFile::fake()->createWithContent('permata-ambiguous.pdf', '%PDF-1.7 permata-ambiguous-test'),
            $this->term,
            $this->admin
        );
        $ambiguousRow = $ambiguousImport->rows()->sole();

        $this->assertSame($lecturer->id, $ambiguousRow->matched_lecturer_id);
        $this->assertSame('VALID', $ambiguousRow->validation_status);
        $this->assertTrue($ambiguousRow->is_approved);
    }

    public function test_commit_staging_rows_creates_teaching_schedules_and_occurrences(): void
    {
        $period = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id,
            'name' => 'Periode Komit Jadwal Aktif',
            'starts_on' => $this->term->starts_on,
            'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE',
            'created_by' => $this->admin->id,
        ]);

        $lecturer = Lecturer::firstOrCreate([
            'name' => 'Dosen Pengujian Komit, M.T.',
        ], [
            'external_code' => 'DSN-TEST-COMMIT',
            'faculty_id' => $this->faculty->id,
            'study_program_id' => $this->prodi->id,
            'is_active' => true,
        ]);

        $import = ScheduleImport::create([
            'academic_term_id' => $this->term->id,
            'uploaded_by' => $this->admin->id,
            'original_filename' => 'jadwal_commit_test.pdf',
            'storage_disk' => 'local',
            'stored_path' => 'imports/commit_test.pdf',
            'sha256' => hash('sha256', 'commit_test_hash'),
            'size_bytes' => 54321,
            'mime_type' => 'application/pdf',
            'status' => 'PENDING_REVIEW',
        ]);

        $row = ScheduleImportRow::create([
            'schedule_import_id' => $import->id,
            'row_no' => 1,
            'source_page' => 1,
            'matched_lecturer_id' => $lecturer->id,
            'normalized_payload' => [
                'lecturer_name' => $lecturer->name,
                'course_name' => 'Pemrograman Berorientasi Objek',
                'course_code' => 'IF-TEST-PBO',
                'class_name' => 'IF-22-B',
                'day_of_week' => 2,
                'start_time' => '08:50',
                'end_time' => '10:30',
                'room_name' => 'Lab Software Engineering',
                'delivery_mode' => 'OFFLINE',
            ],
            'parser_confidence' => 0.98,
            'validation_status' => 'VALID',
            'is_approved' => true,
        ]);

        $this->actingAs($this->admin);
        $commitService = app(ScheduleCommitService::class);
        $committedCount = $commitService->commit($import);

        $this->assertEquals(1, $committedCount);
        $this->assertEquals('COMMITTED', $import->fresh()->status);
        $this->assertNotNull($import->fresh()->committed_at);

        // Verify TeachingSchedule created
        $schedule = TeachingSchedule::where('lecturer_id', $lecturer->id)
            ->where('source_import_id', $import->id)
            ->first();

        $this->assertNotNull($schedule);
        $this->assertEquals('IF-22-B', $schedule->class_name);
        $this->assertEquals(2, $schedule->day_of_week);
        $this->assertStringStartsWith('08:50', $schedule->starts_local);
        $this->assertStringStartsWith('10:30', $schedule->ends_local);

        // Verify TeachingOccurrences created
        $occurrencesCount = TeachingOccurrence::where('teaching_schedule_id', $schedule->id)->count();
        $this->assertGreaterThan(0, $occurrencesCount);

        // Verify Lecturer Directory displays this lecturer and schedule
        $directoryResponse = $this->actingAs($this->admin)->get(route('lpm.lecturers.show', [
            'lecturer' => $lecturer,
            'period_id' => $period->id,
        ]));
        $directoryResponse->assertStatus(200);
        $directoryResponse->assertSee($lecturer->name);
        $directoryResponse->assertSee('Pemrograman Berorientasi Objek');
        $directoryResponse->assertSee('IF-22-B');
    }

    public function test_direct_commit_preserves_pdf_name_while_excel_identity_stays_on_the_same_profile(): void
    {
        Storage::fake('local');
        $lecturer = Lecturer::create([
            'name' => 'Reflan Nauri Uji',
            'external_code' => '0123456784',
            'is_active' => true,
        ]);
        $pdfName = 'Reflan Nauri Uji, S.Kom., M.Kom.';
        $payload = array_replace($this->validOnlinePayload(), [
            'lecturer_name' => $pdfName, 'class_name' => 'IF 26 A',
        ]);
        Http::fake(['*' => Http::response([
            'parser' => ['version' => '1.3.0'],
            'rows' => [[
                'confidence' => 0.95, 'normalized' => $payload,
                'raw' => ['lecturer' => $pdfName],
            ]],
        ])]);
        $import = app(ScheduleImportService::class)->import(
            UploadedFile::fake()->createWithContent('reflan-typo.pdf', '%PDF-1.7 reflan-identity-test'),
            $this->term, $this->admin
        );
        $row = $import->rows()->sole();
        $this->assertNull($row->matched_lecturer_id);
        $this->assertSame($pdfName, $row->fresh()->parsed_lecturer_name);
        $this->actingAs($this->admin)->post(route('lpm.import.names.resolve', [$import, $row]), ['action' => 'same', 'lecturer_id' => $lecturer->id])
            ->assertRedirect(route('lpm.import.names.show', $import));

        $lecturer->refresh();
        $this->assertSame('Reflan Nauri Uji', $lecturer->name);
        $this->assertSame('reflan nauri uji', $lecturer->normalized_name);
        $this->assertSame('0123456784', $lecturer->nidn);
        $this->assertNull($lecturer->study_program_id);
        $this->assertNull($lecturer->faculty_id);
        $schedule = TeachingSchedule::where('source_import_id', $import->id)->sole();
        $this->assertSame($lecturer->id, $schedule->lecturer_id);
        $this->assertSame('IF', $schedule->studyProgram->code);
        $this->assertSame('Reflan Nauri Uji', $schedule->occurrences()->firstOrFail()->identity_snapshot['lecturer_name']);
        $this->assertNull($lecturer->identity_sources['name'] ?? null);

        $shorterPdfName = 'Reflan Nuari Uji, M.Kom.';
        Http::fake(['*' => Http::response([
            'parser' => ['version' => '1.3.0'],
            'rows' => [[
                'confidence' => 0.95,
                'normalized' => array_replace($payload, ['lecturer_name' => $shorterPdfName]),
                'raw' => ['lecturer' => $shorterPdfName],
            ]],
        ])]);
        $laterImport = app(ScheduleImportService::class)->import(
            UploadedFile::fake()->createWithContent('reflan-short-title.pdf', '%PDF-1.7 shorter-pdf-title-test'),
            $this->term, $this->admin
        );
        $this->assertNull(app(LecturerIdentityResolver::class)->resolve($shorterPdfName));
        $this->assertSame('Reflan Nauri Uji', $lecturer->fresh()->name);
        $this->assertSame('0123456784', $lecturer->fresh()->nidn);
    }

    public function test_online_pdf_data_survives_import_commit_and_occurrence_generation(): void
    {
        Storage::fake('local');
        $payload = $this->validOnlinePayload();
        $this->createImportLecturer($payload['lecturer_name']);
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'rows' => [
            ['normalized' => $payload, 'source' => ['page' => 69], 'raw' => ['cell_text' => "TS 26 A, TS 26 B,\nTI 26 A\nAplikasi Komputer\n(Teori)\nONLINE 2"], 'confidence' => 0.95, 'issues' => []],
        ]])]);
        $file = UploadedFile::fake()->createWithContent('online.pdf', '%PDF-1.7 online-test');
        $import = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->assertSame(1, app(ScheduleCommitService::class)->commit($import));
        $schedule = TeachingSchedule::where('source_import_id', $import->id)->sole();
        $this->assertSame('ONLINE', $schedule->delivery_mode);
        $this->assertSame('ONLINE 2', $schedule->room_name);
        $this->assertSame($payload['class_name'], $schedule->class_name);
        $this->assertSame($payload['course_name'], $schedule->course->name);
        $this->assertSame('ONLINE', $schedule->occurrences()->firstOrFail()->identity_snapshot['delivery_mode']);
        $this->assertSame($import->id, app(ScheduleImportService::class)->import($file, $this->term, $this->admin)->id);
        $this->assertSame(1, TeachingSchedule::where('source_import_id', $import->id)->count());
    }

    public function test_missing_delivery_mode_and_parser_conflicts_are_not_auto_committed(): void
    {
        Storage::fake('local');
        $this->createImportLecturer($this->validOnlinePayload()['lecturer_name']);
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'rows' => [
            ['normalized' => array_replace($this->validOnlinePayload(), ['delivery_mode' => null, 'room_name' => null])],
            ['normalized' => $this->validOnlinePayload(), 'issues' => [
                ['code' => 'CONFLICTING_MODE', 'severity' => 'ERROR', 'message' => 'Penanda online dan offline bertentangan.'],
            ]],
        ]])]);
        $import = app(ScheduleImportService::class)->import(
            UploadedFile::fake()->createWithContent('conflict.pdf', '%PDF-1.7 conflict-test'), $this->term, $this->admin
        );
        $this->assertSame(['INVALID', 'INVALID'], $import->rows()->pluck('validation_status')->all());
        $this->assertNull($import->rows()->firstOrFail()->normalized_payload['delivery_mode']);
        $this->assertFalse($import->rows()->firstOrFail()->is_approved);
        try {
            app(ScheduleCommitService::class)->commit($import);
            $this->fail('Invalid source data must not be activated.');
        } catch (ValidationException $exception) {
            $this->assertSame(0, TeachingSchedule::where('source_import_id', $import->id)->count());
        }
        $this->assertSame(0, TeachingSchedule::where('source_import_id', $import->id)->count());
    }

    public function test_courses_with_the_same_prefix_are_not_merged_on_commit(): void
    {
        Storage::fake('local');
        $this->createImportLecturer($this->validOnlinePayload()['lecturer_name']);
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'rows' => [
            ['confidence' => 0.95, 'normalized' => array_replace($this->validOnlinePayload(), ['course_name' => 'Pemrograman Dasar'])],
            ['confidence' => 0.95, 'normalized' => array_replace($this->validOnlinePayload(), ['course_name' => 'Pemrograman Berorientasi Objek'])],
        ]])]);
        $import = app(ScheduleImportService::class)->import(
            UploadedFile::fake()->createWithContent('courses.pdf', '%PDF-1.7 distinct-courses'), $this->term, $this->admin
        );
        $this->assertSame(2, app(ScheduleCommitService::class)->commit($import));
        $schedules = TeachingSchedule::with('course')->where('source_import_id', $import->id)->get();
        $this->assertCount(2, $schedules->pluck('course_id')->unique());
        $this->assertEqualsCanonicalizing(['Pemrograman Dasar', 'Pemrograman Berorientasi Objek'], $schedules->pluck('course.name')->all());
    }

    public function test_parser_rows_without_confidence_are_not_automatically_activated(): void
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'rows' => [
            ['normalized' => $this->validOnlinePayload()],
        ]])]);
        $import = app(ScheduleImportService::class)->import(
            UploadedFile::fake()->createWithContent('uncertain.pdf', '%PDF-1.7 missing-confidence'), $this->term, $this->admin
        );
        $row = $import->rows()->sole();
        $this->assertSame('AMBIGUOUS', $row->validation_status);
        $this->assertFalse($row->is_approved);
        $this->expectException(ValidationException::class);
        app(ScheduleCommitService::class)->commit($import);
    }

    /** @return array<string, mixed> */
    private function validOnlinePayload(): array
    {
        return [
            'lecturer_name' => 'Dosen Uji Metode Online', 'course_name' => 'Aplikasi Komputer (Teori)',
            'class_name' => 'TS 26 A, TS 26 B, TI 26 A', 'day_of_week' => 1,
            'start_time' => '07:00', 'end_time' => '08:40', 'room_name' => 'ONLINE 2', 'delivery_mode' => 'ONLINE',
        ];
    }

    private function createImportLecturer(string $name): Lecturer
    {
        return Lecturer::create([
            'faculty_id' => $this->faculty->id,
            'study_program_id' => $this->prodi->id,
            'external_code' => 'DSN-TEST-'.strtoupper(substr(md5($name), 0, 8)),
            'name' => $name,
            'normalized_name' => mb_strtolower($name),
            'is_active' => true,
        ]);
    }

    private function createActiveImportPeriod(): SupervisionPeriod
    {
        return SupervisionPeriod::create([
            'academic_term_id' => $this->term->id,
            'name' => 'Periode Impor Aktif',
            'starts_on' => $this->term->starts_on,
            'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_end_to_end_pdf_upload_and_extraction_if_file_available(): void
    {
        $realPdfPath = storage_path('app/private/private/imports/c559268a-c38b-4780-be04-ff1b2c5490c3.pdf');
        if (! file_exists($realPdfPath)) {
            $this->markTestSkipped('Real PDF sample not found.');
        }

        $copyPath = tempnam(sys_get_temp_dir(), 'test_pdf_').'.pdf';
        copy($realPdfPath, $copyPath);

        $uploadedFile = new UploadedFile(
            $copyPath,
            'Jadwal_Perkuliahan_Teknokrat.pdf',
            'application/pdf',
            null,
            true
        );

        $importService = app(ScheduleImportService::class);
        $import = $importService->import($uploadedFile, $this->term, $this->admin);

        $this->assertNotNull($import);
        $this->assertEquals('PENDING_REVIEW', $import->status);
        $this->assertGreaterThan(50, $import->rows()->count());

        // Check that lecturers were created or matched
        $firstRow = $import->rows()->first();
        $this->assertNotNull($firstRow->matched_lecturer_id);
        $this->assertNotNull($firstRow->matchedLecturer);

        // Commit all
        $commitService = app(ScheduleCommitService::class);
        $committed = $commitService->commit($import);
        $this->assertGreaterThan(50, $committed);
        $this->assertEquals('COMMITTED', $import->fresh()->status);

        // Check Lecturer Directory with search filter for newly created lecturer
        $response = $this->actingAs($this->admin)->get(route('lpm.lecturers.index', [
            'search' => substr($firstRow->matchedLecturer->name, 0, 6),
        ]));
        $response->assertStatus(200);
        $response->assertSee($firstRow->matchedLecturer->name);

        // Check Lecturer Detail Page
        $detailResponse = $this->actingAs($this->admin)->get(route('lpm.lecturers.show', $firstRow->matchedLecturer));
        $detailResponse->assertStatus(200);
        $detailResponse->assertSee($firstRow->matchedLecturer->name);

        if (file_exists($copyPath)) {
            @unlink($copyPath);
        }
    }
}
