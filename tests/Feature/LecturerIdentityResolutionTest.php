<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Lecturer;
use App\Models\PeriodTarget;
use App\Models\ScheduleImport;
use App\Models\SupervisionPeriod;
use App\Models\TeachingSchedule;
use App\Models\User;
use App\Services\ScheduleImport\LecturerIdentityResolver;
use App\Services\ScheduleImport\PeriodLecturerExclusions;
use App\Services\ScheduleImport\ScheduleImportService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LecturerIdentityResolutionTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private AcademicTerm $term;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sisd_teknokrat_test', DB::connection()->getDatabaseName());
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->admin->assignRole('lpm_admin');
        $this->actingAs($this->admin);
        $this->term = AcademicTerm::firstOrCreate(['academic_year' => '2027/2028', 'semester' => 'GANJIL'], ['starts_on' => '2027-09-01', 'ends_on' => '2028-01-31']);
        config(['services.python.secret' => Str::random(48), 'services.python.url' => 'http://127.0.0.1:18100']);
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private function payload(string $name): array
    {
        return ['lecturer_name' => $name, 'course_name' => 'Pemrograman', 'class_name' => 'IF 27 A',
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '09:40',
            'room_name' => 'ONLINE', 'delivery_mode' => 'ONLINE'];
    }

    public function test_verified_alias_and_instructor_note_share_a_single_identity(): void
    {
        $resolver = app(LecturerIdentityResolver::class);
        $first = $resolver->resolve('M. Fithratullah, M.A.', null, true);
        $this->assertNotNull($first);
        $this->assertSame('Muhammad Fithratullah, S.S., M.Hum.', $first->name);
        $this->assertSame($first->id, $resolver->resolve('Muhammad Fithratullah, S.S., M.Hum. (Instruktur)', null, true)?->id);
        $this->assertSame(1, Lecturer::where('external_code', '0225098602')->count());
    }

    public function test_degree_mismatch_and_new_abbreviation_require_review_but_nidn_conflict_is_rejected(): void
    {
        $lecturer = Lecturer::create(['name' => 'Nama Pengajar Contoh, M.Kom.', 'external_code' => '0298888888', 'is_active' => true]);
        $resolver = app(LecturerIdentityResolver::class);
        $this->assertNull($resolver->resolve('Nama Pengajar Contoh, M.Pd.', null, true));
        $this->assertNull($resolver->resolve('N. Pengajar Contoh, M.Kom.', null, true));
        $this->assertNull($resolver->resolve('Nama Pengajar Contoh, M.Kom.', '0299999999', true));
        $this->assertSame($lecturer->id, $resolver->resolve('Nama Pengajar Contoh, M.Kom. (Instruktur)', null, true)?->id);
        Lecturer::create(['name' => 'Dosen Kedua Berbeda, M.Kom.', 'external_code' => '0295555555', 'is_active' => true]);
        $this->assertNull($resolver->resolve('Dosen Kedua Berbeda, M.Kom.', '0298888888', true));
    }

    public function test_two_equally_strong_candidates_are_not_matched_or_restored(): void
    {
        $first = Lecturer::create(['name' => 'Nama Sama, M.Kom.', 'external_code' => '0293333333', 'is_active' => true]);
        Lecturer::create(['name' => 'Nama Sama, M.Kom.', 'external_code' => '0294444444', 'is_active' => true]);
        $this->assertNull(app(LecturerIdentityResolver::class)->resolve('Nama Sama, M.Kom.', null, true));
        $this->expectException(ValidationException::class);
        app(LecturerIdentityResolver::class)->verifyAlias($first, 'Nama Sama, M.Kom.', $this->admin->id);
    }

    public function test_lpm_verified_alias_survives_operational_reference_reset(): void
    {
        $lecturer = Lecturer::create(['name' => 'Dosen Alias Resmi, M.Pd.', 'is_active' => true]);
        $resolver = app(LecturerIdentityResolver::class);
        $resolver->verifyAlias($lecturer, 'D. Alias Resmi, M.Pd.', $this->admin->id);
        DB::table('lecturers')->where('id', $lecturer->id)->delete();
        DB::table('lecturer_identity_references')->delete();
        $restored = $resolver->resolve('D. Alias Resmi, M.Pd.', null, true);
        $this->assertNotNull($restored);
        $this->assertSame('Dosen Alias Resmi, M.Pd.', $restored->name);
    }

    public function test_manual_rename_keeps_approved_alias_on_the_same_identity(): void
    {
        $lecturer = Lecturer::create(['name' => 'Nama Sebelum Koreksi, M.Pd.', 'is_active' => true]);
        $resolver = app(LecturerIdentityResolver::class);
        $resolver->verifyAlias($lecturer, 'N. Sebelum Koreksi, M.Pd.', $this->admin->id);
        $lecturer->update(['name' => 'Nama Setelah Koreksi, M.Pd.',
            'identity_sources' => ['name' => ['source' => 'manual', 'value' => 'Nama Setelah Koreksi, M.Pd.']]]);
        $this->assertDatabaseCount('lecturer_identity_redirects', 1);
        $this->assertSame($lecturer->id, $resolver->resolve('N. Sebelum Koreksi, M.Pd.')?->id);
    }

    public function test_lpm_review_persists_alias_and_commits_without_changing_canonical_name(): void
    {
        SupervisionPeriod::create(['academic_term_id' => $this->term->id, 'name' => 'Periode Identitas',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id]);
        $lecturer = Lecturer::create(['name' => 'Dosen Nama Lengkap, M.Kom.', 'external_code' => '0297777777', 'is_active' => true]);
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'page_count' => 1,
            'rows' => [['normalized' => $this->payload('D. Nama Lengkap, M.Kom.'), 'confidence' => 0.95, 'source' => ['page' => 2]]]])]);
        $response = $this->post(route('lpm.import.store'), ['academic_term_id' => $this->term->id,
            'schedule_pdf' => UploadedFile::fake()->createWithContent('jadwal.pdf', '%PDF-1.7 identity-'.Str::random(16))]);
        $import = ScheduleImport::latest('id')->firstOrFail();
        $response->assertRedirect(route('lpm.import.names.show', $import));
        $row = $import->rows()->sole();
        $this->assertNull($row->matched_lecturer_id);
        $this->get(route('lpm.import.names.show', $import))->assertOk()->assertSee('D. Nama Lengkap, M.Kom.');
        $this->post(route('lpm.import.names.resolve', [$import, $row]), ['action' => 'same', 'lecturer_id' => $lecturer->id])
            ->assertRedirect(route('lpm.import.names.show', $import));
        $this->assertSame($lecturer->id, $row->fresh()->matched_lecturer_id);
        $this->assertSame('Dosen Nama Lengkap, M.Kom.', $lecturer->fresh()->name);
        $this->assertSame(1, TeachingSchedule::where('source_import_id', $import->id)->count());
        $this->assertDatabaseHas('lecturer_verified_aliases', ['alias' => 'D. Nama Lengkap, M.Kom.']);
        $this->assertSame($lecturer->id, app(LecturerIdentityResolver::class)->resolve('D. Nama Lengkap, M.Kom.')?->id);
    }

    public function test_lpm_can_search_and_restore_a_verified_catalog_candidate_after_operational_reset(): void
    {
        SupervisionPeriod::create(['academic_term_id' => $this->term->id, 'name' => 'Periode Referensi',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id]);
        $this->assertDatabaseMissing('lecturers', ['external_code' => '0214099002']);
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'page_count' => 1,
            'rows' => [['normalized' => $this->payload('Sugama Maskar, M.PMat.'), 'confidence' => 0.95, 'source' => ['page' => 8]]]])]);
        $this->post(route('lpm.import.store'), ['academic_term_id' => $this->term->id,
            'schedule_pdf' => UploadedFile::fake()->createWithContent('sugama.pdf', '%PDF-1.7 reference-'.Str::random(16))]);
        $import = ScheduleImport::latest('id')->firstOrFail();
        $row = $import->rows()->sole();
        $this->assertNull($row->matched_lecturer_id);
        $this->get(route('lpm.import.names.show', $import))->assertOk()
            ->assertSee('reference:code:0214099002')->assertSee('Cari nama dosen atau NIDN...');

        $this->post(route('lpm.import.names.resolve', [$import, $row]),
            ['action' => 'same', 'reference_key' => 'code:0299999999'])->assertSessionHasErrors('reference_key');
        $this->assertDatabaseMissing('lecturers', ['external_code' => '0214099002']);

        $this->post(route('lpm.import.names.resolve', [$import, $row]),
            ['action' => 'same', 'reference_key' => 'code:0214099002'])
            ->assertRedirect(route('lpm.import.names.show', $import));
        $lecturer = Lecturer::where('external_code', '0214099002')->sole();
        $this->assertSame('Dr. Sugama Maskar, M.PMat.', $lecturer->name);
        $this->assertSame($lecturer->id, $row->fresh()->matched_lecturer_id);
        $this->assertSame(1, TeachingSchedule::where('source_import_id', $import->id)->count());
    }

    public function test_new_lecturer_requires_review_and_deferred_name_remains_available(): void
    {
        SupervisionPeriod::create(['academic_term_id' => $this->term->id, 'name' => 'Periode Tinjauan',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id]);
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'page_count' => 1,
            'rows' => [['normalized' => $this->payload('Dosen Belum Dikenal, M.Kom.'), 'confidence' => 0.95]]])]);
        $this->post(route('lpm.import.store'), ['academic_term_id' => $this->term->id,
            'schedule_pdf' => UploadedFile::fake()->createWithContent('baru.pdf', '%PDF-1.7 new-'.Str::random(16))]);
        $import = ScheduleImport::latest('id')->firstOrFail();
        $row = $import->rows()->sole();
        $this->assertNull($row->matched_lecturer_id);
        $this->post(route('lpm.import.names.resolve', [$import, $row]), ['action' => 'defer'])
            ->assertRedirect(route('lpm.import.names.show', $import));
        $this->get(route('lpm.import.names.show', $import))->assertOk()->assertSee('Ditunda oleh LPM');
        $this->assertDatabaseMissing('lecturers', ['name' => 'Dosen Belum Dikenal, M.Kom.']);
        $this->post(route('lpm.import.names.resolve', [$import, $row]), ['action' => 'new'])
            ->assertRedirect(route('lpm.import.names.show', $import));
        $this->assertDatabaseHas('lecturers', ['name' => 'Dosen Belum Dikenal, M.Kom.']);
        $this->assertSame(1, TeachingSchedule::where('source_import_id', $import->id)->count());
    }

    public function test_reviewing_a_committed_import_adds_only_the_newly_matched_rows(): void
    {
        SupervisionPeriod::create(['academic_term_id' => $this->term->id, 'name' => 'Periode Sebagian Aktif',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id]);
        Lecturer::create(['name' => 'Dosen Sudah Dikenal, M.Kom.', 'is_active' => true]);
        $second = Lecturer::create(['name' => 'Dosen Nama Kedua, M.Kom.', 'is_active' => true]);
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'page_count' => 1,
            'rows' => [
                ['normalized' => $this->payload('Dosen Sudah Dikenal, M.Kom.'), 'confidence' => 0.95],
                ['normalized' => array_replace($this->payload('D. Nama Kedua, M.Kom.'), ['class_name' => 'IF 27 B']), 'confidence' => 0.95],
            ]])]);
        $this->post(route('lpm.import.store'), ['academic_term_id' => $this->term->id,
            'schedule_pdf' => UploadedFile::fake()->createWithContent('dua.pdf', '%PDF-1.7 two-'.Str::random(16))]);
        $import = ScheduleImport::latest('id')->firstOrFail();
        $this->assertSame('COMMITTED', $import->status);
        $this->assertSame(1, TeachingSchedule::where('source_import_id', $import->id)->count());
        $unmatched = $import->rows()->whereNull('matched_lecturer_id')->sole();
        $this->post(route('lpm.import.names.resolve', [$import, $unmatched]), ['action' => 'same', 'lecturer_id' => $second->id])
            ->assertRedirect(route('lpm.import.names.show', $import));
        $this->assertSame(2, TeachingSchedule::where('source_import_id', $import->id)->count());
        $this->assertSame(2, $import->rows()->whereNotNull('committed_schedule_id')->count());
    }

    public function test_lpm_confirmation_records_pdf_nidn_on_an_identity_without_nidn(): void
    {
        SupervisionPeriod::create(['academic_term_id' => $this->term->id, 'name' => 'Periode NIDN',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id]);
        $lecturer = Lecturer::create(['name' => 'Nama Dosen Terverifikasi, M.Pd.', 'is_active' => true]);
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'page_count' => 1,
            'rows' => [['normalized' => array_replace($this->payload('N. Dosen Terverifikasi, M.Pd.'),
                ['lecturer_external_code' => '0296666666']), 'confidence' => 0.95]]])]);
        $this->post(route('lpm.import.store'), ['academic_term_id' => $this->term->id,
            'schedule_pdf' => UploadedFile::fake()->createWithContent('nidn.pdf', '%PDF-1.7 nidn-'.Str::random(16))]);
        $import = ScheduleImport::latest('id')->firstOrFail();
        $row = $import->rows()->sole();
        $this->post(route('lpm.import.names.resolve', [$import, $row]), ['action' => 'same', 'lecturer_id' => $lecturer->id])
            ->assertRedirect(route('lpm.import.names.show', $import));
        $this->assertSame('0296666666', $lecturer->fresh()->nidn);
        $this->assertSame($lecturer->id, app(LecturerIdentityResolver::class)->resolve('N. Dosen Terverifikasi, M.Pd.', '0296666666')?->id);
    }

    public function test_old_parser_response_is_rejected_instead_of_being_cached_as_new(): void
    {
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.2.0'], 'page_count' => 1,
            'rows' => [['normalized' => $this->payload('Dosen Lama, M.Kom.'), 'confidence' => 0.95]]])]);
        $this->expectException(ValidationException::class);
        app(ScheduleImportService::class)->import(
            UploadedFile::fake()->createWithContent('parser-lama.pdf', '%PDF-1.7 parser-old-'.Str::random(16)),
            $this->term, $this->admin
        );
    }

    public function test_confirmed_alias_does_not_reactivate_a_lecturer_excluded_from_the_period(): void
    {
        $period = SupervisionPeriod::create(['academic_term_id' => $this->term->id, 'name' => 'Periode Pengecualian',
            'starts_on' => $this->term->starts_on, 'ends_on' => $this->term->ends_on,
            'status' => 'ACTIVE', 'created_by' => $this->admin->id]);
        $lecturer = Lecturer::create(['name' => 'Dosen Tidak Diikutkan, M.Kom.', 'is_active' => true]);
        PeriodTarget::create(['supervision_period_id' => $period->id, 'lecturer_id' => $lecturer->id,
            'scope_status' => 'EXCLUDED', 'selected_by' => $this->admin->id]);
        Http::fake(['*' => Http::response(['parser' => ['version' => '1.3.0'], 'page_count' => 1,
            'rows' => [['normalized' => $this->payload('D. Tidak Diikutkan, M.Kom.'), 'confidence' => 0.95]]])]);
        $this->post(route('lpm.import.store'), ['academic_term_id' => $this->term->id,
            'schedule_pdf' => UploadedFile::fake()->createWithContent('excluded.pdf', '%PDF-1.7 excluded-'.Str::random(16))]);
        $import = ScheduleImport::latest('id')->firstOrFail();
        $row = $import->rows()->sole();
        $this->post(route('lpm.import.names.resolve', [$import, $row]), ['action' => 'same', 'lecturer_id' => $lecturer->id])
            ->assertRedirect(route('lpm.import.names.show', $import));
        $this->assertSame(PeriodLecturerExclusions::SKIP_REASON, $row->fresh()->skip_reason);
        $this->assertSame(0, TeachingSchedule::where('source_import_id', $import->id)->count());
        $this->get(route('lpm.import.names.show', $import))->assertOk()->assertDontSee('D. Tidak Diikutkan, M.Kom.');
    }
}
