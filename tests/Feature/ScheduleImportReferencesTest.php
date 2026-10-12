<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Faculty;
use App\Models\Lecturer;
use App\Models\SupervisionPeriod;
use App\Models\TeachingSchedule;
use App\Models\User;
use App\Services\ScheduleImport\ScheduleCommitService;
use App\Services\ScheduleImport\ScheduleImportReferences;
use App\Services\ScheduleImport\ScheduleImportService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ScheduleImportReferencesTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private AcademicTerm $term;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.python.secret' => Str::random(48), 'services.python.url' => 'http://127.0.0.1:18100']);
        $this->assertSame('sisd_teknokrat_test', DB::connection()->getDatabaseName());
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->admin->assignRole('superadmin');
        $this->actingAs($this->admin);
        $this->term = AcademicTerm::firstOrCreate(['academic_year' => '2026/2027', 'semester' => 'GANJIL'], ['starts_on' => '2026-09-01', 'ends_on' => '2027-01-31']);
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private function knownLecturer(): Lecturer
    {
        return Lecturer::create(['name' => 'Referensi Dosen, M.Kom.', 'external_code' => '0212345678', 'is_active' => true]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['lecturer_name' => 'Referensi Dosen, M.Kom.', 'course_name' => 'Pemrograman', 'class_name' => 'IF 25 A', 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '09:40', 'room_name' => 'ONLINE', 'delivery_mode' => 'ONLINE'];
    }

    public function test_upload_restores_only_a_uniquely_known_identity_and_archives_the_committed_pdf(): void
    {
        $lecturer = $this->knownLecturer();
        DB::table('lecturers')->where('id', $lecturer->id)->delete();
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.1.0'], 'rows' => [['normalized' => $this->payload(), 'confidence' => 0.95]], 'page_count' => 1])]);
        $file = UploadedFile::fake()->createWithContent('known.pdf', '%PDF-1.7 known-reference');
        $import = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $row = $import->rows()->sole();
        $this->assertSame('VALID', $row->validation_status);
        $this->assertSame('0212345678', $row->matchedLecturer->external_code);
        $this->assertNotSame($lecturer->id, $row->matched_lecturer_id);
        $this->assertSame(1, app(ScheduleCommitService::class)->commit($import, true));
        $reference = DB::table('schedule_document_references')->where('sha256', $import->sha256)->first();
        $this->assertNotNull($reference);
        Storage::disk('local')->assertExists($reference->stored_path);
        $this->artisan('app:reset-schedule-data', ['--keep-academic-master' => true])->assertSuccessful();
        $this->assertDatabaseCount('lecturers', 0);
        $this->assertDatabaseCount('teaching_schedules', 0);
        $this->assertDatabaseCount('lecturer_identity_references', 1);
        $this->assertDatabaseCount('schedule_document_references', 1);
        Storage::disk('local')->assertExists($reference->stored_path);
        Http::fake();
        $newImport = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->assertSame('VALID', $newImport->rows()->sole()->validation_status);
        Http::assertNothingSent();
    }

    #[TestWith(['low_confidence'])]
    #[TestWith(['invalid'])]
    #[TestWith(['unknown'])]
    #[TestWith(['ambiguous'])]
    #[TestWith(['inactive'])]
    public function test_unsafe_or_unknown_names_do_not_recreate_lecturers(string $case): void
    {
        $lecturer = $this->knownLecturer();
        if ($case === 'inactive') {
            $lecturer->update(['is_active' => false]);
        }
        DB::table('lecturers')->where('id', $lecturer->id)->delete();
        $payload = $this->payload();
        if ($case === 'unknown') {
            $payload['lecturer_name'] = 'Nama Baru Belum Diverifikasi';
        } elseif ($case === 'invalid') {
            $payload['delivery_mode'] = '';
        } elseif ($case === 'ambiguous') {
            app(ScheduleImportReferences::class)->rememberProfile(['name' => 'Referensi Dosen, M.Kom.', 'external_code' => '0298765432', 'is_active' => true]);
        }
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.1.0'], 'rows' => [['normalized' => $payload, 'confidence' => $case === 'low_confidence' ? 0.6 : 0.95]], 'page_count' => 1])]);
        $import = app(ScheduleImportService::class)->import(UploadedFile::fake()->createWithContent('uncertain.pdf', '%PDF-1.7 uncertain-reference'), $this->term, $this->admin);
        $this->assertNull($import->rows()->sole()->matched_lecturer_id);
        $this->assertDatabaseCount('lecturers', 0);
        $this->assertDatabaseCount('teaching_schedules', 0);
    }

    public function test_cached_rows_are_revalidated_instead_of_bypassing_current_validation(): void
    {
        $lecturer = $this->knownLecturer();
        DB::table('lecturers')->where('id', $lecturer->id)->delete();
        $file = UploadedFile::fake()->createWithContent('cached.pdf', '%PDF-1.7 cached-reference');
        app(ScheduleImportReferences::class)->rememberDocument(hash_file('sha256', $file->getRealPath()), 'cached.pdf', '1.1.0', 1, [
            ['normalized' => array_replace($this->payload(), ['delivery_mode' => 'UNKNOWN']), 'confidence' => 0.95, 'issues' => []],
        ], null);
        Http::fake();
        $import = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->assertSame('INVALID', $import->rows()->sole()->validation_status);
        $this->assertDatabaseCount('lecturers', 0);
        Http::assertNothingSent();
    }

    public function test_period_directory_contains_only_pdf_names_including_cross_faculty_teachers(): void
    {
        $faculty = Faculty::firstOrCreate(['code' => 'FEB'], ['name' => 'Fakultas Ekonomi dan Bisnis', 'is_active' => true]);
        $period = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id, 'name' => 'Periode Uji Jadwal FTIK',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id,
        ]);
        $references = app(ScheduleImportReferences::class);
        $references->rememberProfile([
            'name' => 'Pengajar FEB Dalam PDF, M.M.', 'external_code' => '0291111111',
            'faculty_code' => 'FEB', 'is_active' => true,
        ]);
        $references->rememberProfile([
            'name' => 'Referensi Lama Tidak Dalam PDF, M.Pd.', 'external_code' => '0292222222',
            'is_active' => true,
        ]);
        $activeUnlisted = Lecturer::create(['name' => 'Dosen Aktif Tidak Dalam PDF', 'is_active' => true]);
        Http::fake(['*' => Http::response([
            'parser' => ['version' => '1.1.0'], 'page_count' => 1,
            'rows' => [['normalized' => array_replace($this->payload(), ['lecturer_name' => 'Pengajar FEB Dalam PDF, M.M.']), 'confidence' => 0.95]],
        ])]);

        $response = $this->post(route('lpm.import.store'), [
            'academic_term_id' => $this->term->id,
            'schedule_pdf' => UploadedFile::fake()->createWithContent('jadwal-FTIK.pdf', '%PDF-1.7 cross-faculty-teaching'),
        ]);
        $response->assertRedirect(route('lpm.lecturers.index'))->assertSessionHas('success')->assertSessionMissing('upload_success');
        $imported = Lecturer::where('external_code', '0291111111')->sole();
        $this->assertSame($faculty->id, $imported->faculty_id);
        $this->assertSame([$imported->id], $period->targets()->pluck('lecturer_id')->all());
        $this->assertDatabaseMissing('lecturers', ['external_code' => '0292222222']);
        $this->assertSame(3, DB::table('lecturer_identity_references')->count());
        $this->get(route('lpm.lecturers.index', ['period_id' => $period->id]))
            ->assertOk()
            ->assertViewHas('lecturers', fn ($lecturers): bool => $lecturers->total() === 1
                && $lecturers->contains('id', $imported->id)
                && ! $lecturers->contains('id', $activeUnlisted->id))
            ->assertDontSee('Upload Jadwal Berhasil!')
            ->assertDontSee('Referensi Lama Tidak Dalam PDF');
    }

    public function test_candidate_doctor_prefix_is_normalized_without_guessing_a_different_name(): void
    {
        $this->assertSame('setiawansyah', ScheduleImportReferences::normalizeBaseName('Can (Dr.) Setiawansyah, M.Kom.'));
        $this->assertSame('setiawansyah', ScheduleImportReferences::normalizeBaseName('Dr. Setiawansyah, M.Kom.'));
        $this->assertNotSame(ScheduleImportReferences::normalizeBaseName('Can (Dr.) Setiawansyah, M.Kom.'), ScheduleImportReferences::normalizeBaseName('Setiawan, M.Kom.'));
    }

    public function test_supplied_ftik_pdf_imports_using_recovered_references_in_the_isolated_database(): void
    {
        $pdf = getenv('SISD_SCHEDULE_PDF');
        $profiles = getenv('SISD_REFERENCE_TEST_DATA');
        if (! $pdf || ! $profiles || ! is_file($pdf) || ! is_file($profiles)) {
            $this->markTestSkipped('Provide the PDF and recovered reference fixture for this integration case.');
        }
        $this->assertDatabaseCount('lecturers', 0);
        $references = app(ScheduleImportReferences::class);
        foreach (json_decode(file_get_contents($profiles), true, 512, JSON_THROW_ON_ERROR) as $profile) {
            $references->rememberProfile($profile);
        }
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.1.0'], 'rows' => []])]);
        $import = app(ScheduleImportService::class)->import(new UploadedFile($pdf, basename($pdf), 'application/pdf', null, true), $this->term, $this->admin);
        $this->assertSame(420, $import->rows()->count());
        $this->assertSame(414, $import->rows()->where('validation_status', 'VALID')->count());
        $this->assertSame(414, app(ScheduleCommitService::class)->commit($import, true));
        $this->assertSame(414, TeachingSchedule::count());
        $this->assertSame(6, $import->rows()->where('validation_status', 'SKIPPED')->count());
        $this->assertSame('COMMITTED', $import->fresh()->status);
    }
}
