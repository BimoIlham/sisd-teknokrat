<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Faculty;
use App\Models\Lecturer;
use App\Models\SupervisionPeriod;
use App\Models\TeachingSchedule;
use App\Models\User;
use App\Services\PermanentDeletionService;
use App\Services\ScheduleImport\PeriodLecturerExclusions;
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
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'rows' => [['normalized' => $this->payload(), 'confidence' => 0.95]], 'page_count' => 1])]);
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
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'rows' => [['normalized' => $payload, 'confidence' => $case === 'low_confidence' ? 0.6 : 0.95]], 'page_count' => 1])]);
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
        app(ScheduleImportReferences::class)->rememberDocument(hash_file('sha256', $file->getRealPath()), 'cached.pdf', '1.3.0', 1, [
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
            'parser' => ['version' => '1.3.0'], 'page_count' => 1,
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

    public function test_verified_fithratullah_alias_matches_the_same_lecturer_as_full_name(): void
    {
        $shortName = array_replace($this->payload(), ['lecturer_name' => 'M. Fithratullah, M.A.']);
        $fullName = array_replace($this->payload(), ['lecturer_name' => 'Muhammad Fithratullah, M.A.']);
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'page_count' => 1, 'rows' => [
            ['normalized' => $shortName, 'confidence' => 0.95],
            ['normalized' => $fullName, 'confidence' => 0.95],
        ]])]);

        $import = app(ScheduleImportService::class)->import(
            UploadedFile::fake()->createWithContent('fithratullah.pdf', '%PDF-1.7 verified-name-alias'),
            $this->term,
            $this->admin
        );
        $rows = $import->rows()->orderBy('row_no')->get();

        $this->assertSame(['VALID', 'VALID'], $rows->pluck('validation_status')->all());
        $this->assertSame($rows[0]->matched_lecturer_id, $rows[1]->matched_lecturer_id);
        $this->assertSame('0225098602', $rows[0]->matchedLecturer->external_code);
        $this->assertSame(1, Lecturer::where('external_code', '0225098602')->count());
    }

    public function test_ten_previously_deleted_pdf_names_are_uniquely_available_in_the_catalog(): void
    {
        $names = [
            'Alvi Suhartanto, S.Kom.',
            'Budi Santosa, S.Kom.(Instruktur)',
            'Deva Dian Ardana, S.Kom., (Instruktur)',
            'Fikih Yuda Sena, S.Kom.(Instruktur)',
            'Helma Nopijani Heidy, S.Kom., M.Kom.',
            'Mahendra Dewantoro, S.Kom., M.Kom.',
            'Muhammad Surono, M.Kom.',
            'Rido Febriansyah, S.Kom. (Instruktur)',
            'Samingan, S.Kom. (Instruktur)',
            'Turwan Aldi Putra, S.Kom. (Instruktur)',
        ];
        $period = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id, 'name' => 'Periode Baru Sepuluh Dosen',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id,
        ]);
        $parsedRows = array_map(fn (string $name): array => [
            'normalized' => array_replace($this->payload(), ['lecturer_name' => $name]),
            'confidence' => 0.95,
        ], $names);
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'page_count' => 1, 'rows' => $parsedRows])]);

        $import = app(ScheduleImportService::class)->import(
            UploadedFile::fake()->createWithContent('sepuluh-dosen.pdf', '%PDF-1.7 ten-verified-lecturers'),
            $this->term,
            $this->admin
        );
        $this->assertSame(10, $import->rows()->where('validation_status', 'VALID')->count());
        $this->assertSame(10, $import->rows()->distinct()->count('matched_lecturer_id'));
        $this->assertSame(10, app(ScheduleCommitService::class)->commit($import, true));
        $this->assertSame(count($names), Lecturer::whereIn('name', $names)->count());
        $this->assertSame(10, TeachingSchedule::where('supervision_period_id', $period->id)->count());
    }

    public function test_deleted_lecturer_name_variants_are_silent_in_the_current_period_and_available_in_the_next(): void
    {
        $this->knownLecturer();
        $period = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id, 'name' => 'Periode Nama Berbeda',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id,
        ]);
        app(PeriodLecturerExclusions::class)->exclude($period->id, [
            'Fikih Yuda Sena, S.Kom.(Instruktur)',
            'Rido Febriansyah, S.Kom. (Instruktur)',
            'Samingan, S.Kom. (Instruktur)',
            'Turwan Aldi Putra, S.Kom. (Instruktur)',
        ], $this->admin->id);
        $pdfNames = ['Fikih Yuhada Sena, S.Kom.', 'Rido Febryansyah, S.Kom.', 'Rido Febryansyah, S.Kom.', 'Samingan, S.Kom.', 'Turwan Aldi Putra, S.Kom.'];
        $rows = [['normalized' => $this->payload(), 'confidence' => 0.95]];
        foreach ($pdfNames as $name) {
            $rows[] = ['normalized' => array_replace($this->payload(), ['lecturer_name' => $name]), 'confidence' => 0.95];
        }
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'page_count' => 1, 'rows' => $rows])]);
        $file = UploadedFile::fake()->createWithContent('variasi-nama.pdf', '%PDF-1.7 lecturer-name-variants');
        $import = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->post(route('lpm.import.store'), [
            'academic_term_id' => $this->term->id, 'schedule_pdf' => $file,
        ])->assertSessionHas('skipped_count', 0)
            ->assertSessionHas('success', 'Upload PDF berhasil! 1 jadwal perkuliahan telah aktif di sistem.');
        $this->assertSame(5, $import->rows()->where('skip_reason', PeriodLecturerExclusions::SKIP_REASON)->count());
        $this->assertDatabaseMissing('lecturers', ['name' => 'Fikih Yuda Sena, S.Kom.(Instruktur)']);
        $this->assertDatabaseMissing('lecturers', ['name' => 'Rido Febriansyah, S.Kom. (Instruktur)']);
        $this->assertDatabaseMissing('lecturers', ['name' => 'Samingan, S.Kom. (Instruktur)']);
        $this->assertDatabaseMissing('lecturers', ['name' => 'Turwan Aldi Putra, S.Kom. (Instruktur)']);

        $import->rows()->whereIn('row_no', [2, 3, 4, 5, 6])->update([
            'skip_reason' => 'Nama dosen dari PDF tidak terbaca atau tidak cocok dengan direktori dosen.',
        ]);
        $retried = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->assertSame($import->id, $retried->id);
        $this->assertSame(5, $retried->rows()->where('skip_reason', PeriodLecturerExclusions::SKIP_REASON)->count());
        $this->assertSame('COMMITTED', $retried->status);
        $this->assertSame(1, TeachingSchedule::where('supervision_period_id', $period->id)->count());
        $this->post(route('lpm.import.store'), [
            'academic_term_id' => $this->term->id, 'schedule_pdf' => $file,
        ])->assertSessionHas('skipped_count', 0)
            ->assertSessionHas('success', 'Upload jadwal berhasil! 1 jadwal dari PDF ini tetap aktif.');

        $period->update(['status' => 'CLOSED']);
        $nextPeriod = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id, 'name' => 'Periode Nama Berbeda Berikutnya',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id,
        ]);
        $nextImport = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->assertSame(5, $nextImport->rows()->where('validation_status', 'VALID')->whereIn('row_no', [2, 3, 4, 5, 6])->count());
        $this->assertSame(4, $nextImport->rows()->whereIn('row_no', [2, 3, 4, 5, 6])->distinct()->count('matched_lecturer_id'));
        $this->assertSame($nextPeriod->id, $nextImport->supervision_period_id);
    }

    public function test_lpm_deletion_excludes_a_lecturer_only_until_the_next_period(): void
    {
        $firstPeriod = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id, 'name' => 'Periode Pertama',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id,
        ]);
        $name = 'Budi Santosa, S.Kom.(Instruktur)';
        $payload = array_replace($this->payload(), ['lecturer_name' => $name]);
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'page_count' => 1, 'rows' => [
            ['normalized' => $payload, 'confidence' => 0.95],
        ]])]);
        $file = UploadedFile::fake()->createWithContent('periode-pertama.pdf', '%PDF-1.7 first-period-budi');
        $firstImport = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->assertSame(1, app(ScheduleCommitService::class)->commit($firstImport, true));
        $lecturer = Lecturer::where('name', $name)->sole();
        $this->admin->removeRole('superadmin');
        $this->admin->assignRole('lpm_admin');

        $this->delete(route('lpm.lecturers.destroy', $lecturer), [
            'period_id' => $firstPeriod->id, 'row_version' => $lecturer->row_version,
        ])->assertSessionHas('success');
        $this->assertDatabaseHas('period_lecturer_exclusions', [
            'supervision_period_id' => $firstPeriod->id,
            'normalized_name' => ScheduleImportReferences::normalizeName($name),
        ]);
        $sameFile = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->assertSame($firstImport->id, $sameFile->id);
        $this->assertDatabaseMissing('lecturers', ['name' => $name]);

        $anotherFile = UploadedFile::fake()->createWithContent('periode-pertama-lagi.pdf', '%PDF-1.7 first-period-budi-again');
        $samePeriod = app(ScheduleImportService::class)->import($anotherFile, $this->term, $this->admin);
        $this->assertSame('SKIPPED', $samePeriod->rows()->sole()->validation_status);
        $this->assertSame(PeriodLecturerExclusions::SKIP_REASON, $samePeriod->rows()->sole()->skip_reason);
        $this->assertDatabaseMissing('lecturers', ['name' => $name]);
        $expectedMessage = 'PDF berhasil diproses. Tidak ada jadwal aktif karena semua dosen pada PDF sudah dikeluarkan dari periode ini.';
        foreach ([1, 2] as $attempt) {
            $this->post(route('lpm.import.store'), [
                'academic_term_id' => $this->term->id, 'schedule_pdf' => $anotherFile,
            ])->assertSessionHas('skipped_count', 0)
                ->assertSessionHas('success', $expectedMessage);
        }
        $this->assertSame('COMMITTED', $samePeriod->fresh()->status);
        $this->assertSame(0, TeachingSchedule::where('source_import_id', $samePeriod->id)->count());

        $firstPeriod->update(['status' => 'CLOSED']);
        $nextPeriod = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id, 'name' => 'Periode Berikutnya',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id,
        ]);
        $nextImport = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->assertSame('VALID', $nextImport->rows()->sole()->validation_status);
        $this->assertSame(1, app(ScheduleCommitService::class)->commit($nextImport, true));
        $this->assertDatabaseHas('teaching_schedules', ['supervision_period_id' => $nextPeriod->id]);
        $this->assertDatabaseMissing('teaching_schedules', ['supervision_period_id' => $firstPeriod->id]);
    }

    public function test_reupload_retries_only_unmatched_rows_after_a_reference_is_verified(): void
    {
        $this->knownLecturer();
        SupervisionPeriod::create([
            'academic_term_id' => $this->term->id, 'name' => 'Periode Verifikasi Nama',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id,
        ]);
        $matchedLater = array_replace($this->payload(), ['lecturer_name' => 'Dosen Baru Terverifikasi']);
        $removedManually = array_replace($this->payload(), ['lecturer_name' => 'Dosen Sengaja Dihapus']);
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'page_count' => 1, 'rows' => [
            ['normalized' => $this->payload(), 'confidence' => 0.95],
            ['normalized' => $matchedLater, 'confidence' => 0.95],
            ['normalized' => $removedManually, 'confidence' => 0.95],
        ]])]);
        $file = UploadedFile::fake()->createWithContent('verified-later.pdf', '%PDF-1.7 verified-later');
        $import = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->assertSame(1, app(ScheduleCommitService::class)->commit($import, true));
        $this->assertSame(2, $import->rows()->where('validation_status', 'SKIPPED')->count());

        $import->rows()->where('row_no', 3)->update(['skip_reason' => 'Dosen dihapus manual dari Direktori Dosen.']);
        $references = app(ScheduleImportReferences::class);
        $references->rememberProfile(['name' => 'Dosen Baru Terverifikasi', 'external_code' => '0293333333', 'is_active' => true]);
        $references->rememberProfile(['name' => 'Dosen Sengaja Dihapus', 'external_code' => '0294444444', 'is_active' => true]);

        $retried = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->assertSame($import->id, $retried->id);
        $this->assertSame('PENDING_REVIEW', $retried->status);
        $response = $this->post(route('lpm.import.store'), [
            'academic_term_id' => $this->term->id,
            'schedule_pdf' => $file,
        ]);
        $response->assertRedirect(route('lpm.lecturers.index'))
            ->assertSessionHas('success', 'Upload PDF berhasil! 2 jadwal perkuliahan telah aktif di sistem.');
        $this->assertSame(2, $retried->rows()->where('validation_status', 'VALID')->count());
        $this->assertSame('SKIPPED', $retried->rows()->where('row_no', 3)->value('validation_status'));
        $this->assertSame(2, TeachingSchedule::where('source_import_id', $retried->id)->where('is_active', true)->count());
        $this->assertDatabaseMissing('lecturers', ['external_code' => '0294444444']);
    }

    public function test_catalog_restores_verified_lecturer_after_bulk_reset_and_reprocesses_same_pdf(): void
    {
        $period = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id, 'name' => 'Periode Katalog',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id,
        ]);
        $catalog = json_decode(file_get_contents(database_path('seeders/uti_lecturer_catalog.json')), true, 512, JSON_THROW_ON_ERROR);
        $profile = collect($catalog['lecturers'])->first(fn (array $item): bool => ! empty($item['external_code']) && ! empty($item['aliases']));
        $this->assertNotNull($profile);
        $valid = array_replace($this->payload(), ['lecturer_name' => $profile['aliases'][0]]);
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'page_count' => 1, 'rows' => [
            ['normalized' => $valid, 'confidence' => 0.95],
            ['normalized' => array_replace($valid, ['lecturer_name' => 'Nama Tidak Ada di Referensi']), 'confidence' => 0.95],
            ['normalized' => array_replace($valid, ['lecturer_name' => 'Team']), 'confidence' => 0.95],
        ]])]);
        $file = UploadedFile::fake()->createWithContent('katalog.pdf', '%PDF-1.7 catalog-period-reset');
        $import = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->assertSame(2, $import->rows()->count());
        $this->assertSame('VALID', $import->rows()->first()->validation_status);
        $this->assertSame(1, app(ScheduleCommitService::class)->commit($import, true));
        $this->assertDatabaseHas('lecturers', ['external_code' => $profile['external_code']]);
        $this->assertDatabaseHas('period_targets', ['supervision_period_id' => $period->id, 'scope_status' => 'INCLUDED']);

        $deletions = app(PermanentDeletionService::class);
        $impact = $deletions->previewBulk('lecturer');
        $deletions->deleteBulk('lecturer', $impact['phrase'], $impact['token'], $this->admin);
        $this->assertDatabaseCount('lecturers', 0);
        $this->assertDatabaseCount('lecturer_identity_references', 0);
        Http::fake();
        $retried = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->assertSame($import->id, $retried->id);
        $this->assertSame('VALID', $retried->rows()->first()->validation_status);
        $this->assertSame(1, app(ScheduleCommitService::class)->commit($retried, true));
        $this->assertDatabaseHas('lecturers', ['external_code' => $profile['external_code']]);
        $this->assertDatabaseCount('teaching_schedules', 1);
        Http::assertNothingSent();
    }

    public function test_same_pdf_creates_separate_imports_and_schedules_for_each_period(): void
    {
        $lecturer = $this->knownLecturer();
        $firstPeriod = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id, 'name' => 'Periode Pertama',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id,
        ]);
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'page_count' => 1, 'rows' => [
            ['normalized' => $this->payload(), 'confidence' => 0.95],
        ]])]);
        $file = UploadedFile::fake()->createWithContent('sama.pdf', '%PDF-1.7 same-pdf-new-period');
        $firstImport = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->assertSame(1, app(ScheduleCommitService::class)->commit($firstImport, true));
        $firstPeriod->update(['status' => 'CLOSED']);
        $secondPeriod = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id, 'name' => 'Periode Kedua',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id,
        ]);
        $this->assertDatabaseMissing('period_targets', ['supervision_period_id' => $secondPeriod->id, 'lecturer_id' => $lecturer->id]);
        $secondImport = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->assertNotSame($firstImport->id, $secondImport->id);
        $this->assertSame(1, app(ScheduleCommitService::class)->commit($secondImport, true));
        $this->assertDatabaseHas('teaching_schedules', ['supervision_period_id' => $firstPeriod->id, 'lecturer_id' => $lecturer->id]);
        $this->assertDatabaseHas('teaching_schedules', ['supervision_period_id' => $secondPeriod->id, 'lecturer_id' => $lecturer->id]);
        $this->assertDatabaseHas('period_targets', ['supervision_period_id' => $secondPeriod->id, 'lecturer_id' => $lecturer->id]);
        $this->assertDatabaseCount('teaching_schedules', 2);
    }

    public function test_period_deletion_blocks_reimport_only_in_that_period(): void
    {
        $lecturer = $this->knownLecturer();
        $firstPeriod = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id, 'name' => 'Periode Awal',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id,
        ]);
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'page_count' => 1, 'rows' => [
            ['normalized' => $this->payload(), 'confidence' => 0.95],
        ]])]);
        $file = UploadedFile::fake()->createWithContent('periode.pdf', '%PDF-1.7 period-exclusion');
        $firstImport = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->assertSame(1, app(ScheduleCommitService::class)->commit($firstImport, true));

        $deletions = app(PermanentDeletionService::class);
        $impact = $deletions->previewPeriodLecturer($lecturer->id, $firstPeriod->id);
        $deletions->deletePeriodLecturer($lecturer->id, $firstPeriod->id, $impact['name'], $impact['token'], $this->admin);
        $retried = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->assertNull($retried->rows()->sole()->matched_lecturer_id);
        $this->assertSame(0, app(ScheduleCommitService::class)->commit($retried, true));
        $samePeriodImport = app(ScheduleImportService::class)->import(
            UploadedFile::fake()->createWithContent('periode-lama-lagi.pdf', '%PDF-1.7 period-exclusion-new-file'),
            $this->term,
            $this->admin
        );
        $this->assertSame('SKIPPED', $samePeriodImport->rows()->sole()->validation_status);
        $this->assertDatabaseCount('teaching_schedules', 0);

        $firstPeriod->update(['status' => 'CLOSED']);
        $nextPeriod = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id, 'name' => 'Periode Baru',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id,
        ]);
        $newImport = app(ScheduleImportService::class)->import($file, $this->term, $this->admin);
        $this->assertSame(1, app(ScheduleCommitService::class)->commit($newImport, true));
        $this->assertDatabaseHas('teaching_schedules', ['supervision_period_id' => $nextPeriod->id, 'lecturer_id' => $lecturer->id]);
        $this->assertDatabaseMissing('teaching_schedules', ['supervision_period_id' => $firstPeriod->id, 'lecturer_id' => $lecturer->id]);
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
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'rows' => []])]);
        $import = app(ScheduleImportService::class)->import(new UploadedFile($pdf, basename($pdf), 'application/pdf', null, true), $this->term, $this->admin);
        $this->assertSame(420, $import->rows()->count());
        $this->assertSame(414, $import->rows()->where('validation_status', 'VALID')->count());
        $this->assertSame(414, app(ScheduleCommitService::class)->commit($import, true));
        $this->assertSame(414, TeachingSchedule::count());
        $this->assertSame(6, $import->rows()->where('validation_status', 'SKIPPED')->count());
        $this->assertSame('COMMITTED', $import->fresh()->status);
    }
}
