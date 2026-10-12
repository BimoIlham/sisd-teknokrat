<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\Lecturer;
use App\Models\SupervisionPeriod;
use App\Models\TeachingSchedule;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SupervisionPeriodTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected AcademicTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        if (User::count() === 0) {
            $this->seed(DatabaseSeeder::class);
        }

        $this->admin = User::firstOrCreate([
            'email' => 'lpm@teknokrat.ac.id',
        ], [
            'name' => 'Administrator LPM',
            'password' => bcrypt('Password123!'),
        ]);

        if (! $this->admin->hasRole('superadmin')) {
            $this->seed(RoleAndPermissionSeeder::class);
            $this->admin->assignRole('superadmin');
        }

        $this->term = AcademicTerm::firstOrCreate([
            'academic_year' => '2026/2027',
            'semester' => 'GANJIL',
        ], [
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-01-31',
        ]);
    }

    public function test_guest_cannot_access_periods_page(): void
    {
        $response = $this->get(route('lpm.periods.index'));
        $response->assertRedirect(route('login'));
    }

    public function test_superadmin_can_view_periods_index_and_alias(): void
    {
        $response = $this->actingAs($this->admin)->get(route('lpm.periods.index'));
        $response->assertOk();
        $response->assertSee('Periode Supervisi');
        $response->assertSee('Kelola periode supervisi. Hanya satu periode yang dapat aktif pada satu waktu.');
        $response->assertSee('Tambah Periode');
        $response->assertSee('Tahun Ajaran');
        $response->assertSee('name="academic_year"', false);
        $response->assertDontSee('Batas Upload Dokumen Lembaga');
        $response->assertDontSee('name="document_deadline_at"', false);
        $response->assertSee('name="stage_1_starts_on"', false);
        $response->assertSee('name="stage_1_ends_on"', false);
        $response->assertSee('name="stage_2_starts_on"', false);
        $response->assertSee('name="stage_2_ends_on"', false);
        $response->assertDontSee('name="stage_no"', false);
        $response->assertDontSee('Gunakan Tambah Periode lagi');
        $response->assertSee('name="name"', false);
        $response->assertSee('x-teleport="body"', false);
        $response->assertDontSee('+ Tahap');

        // Test alias /lpm/periode-audit
        $aliasResponse = $this->actingAs($this->admin)->get(route('lpm.audit_periods.index'));
        $aliasResponse->assertOk();
        $aliasResponse->assertSee('Periode Supervisi');
    }

    public function test_superadmin_can_view_period_details(): void
    {
        $period = SupervisionPeriod::firstOrCreate([
            'name' => 'Supervisi Semester Ganjil 2026/2027',
        ], [
            'academic_term_id' => $this->term->id,
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-01-31',
            'status' => 'ACTIVE',
            'created_by' => $this->admin->id,
        ]);
        $response = $this->actingAs($this->admin)->get(route('lpm.periods.show', $period));
        $response->assertOk();
        $response->assertSee($period->name);
        $response->assertDontSee('DEMO_POLICY_MEAN');
        $response->assertDontSee('- DEMO');
    }

    public function test_superadmin_can_create_a_semester_period_with_an_academic_year(): void
    {
        $lecturer = Lecturer::create([
            'name' => 'Dosen Master Periode Lama',
            'external_code' => 'DSN-PREVIOUS-PERIOD-MASTER',
            'is_active' => true,
        ]);
        $term = AcademicTerm::firstOrCreate([
            'academic_year' => '2027/2028',
            'semester' => 'GANJIL',
        ], [
            'starts_on' => '2027-09-01',
            'ends_on' => '2028-01-31',
        ]);
        $course = Course::create(['name' => 'Mata Kuliah dari Data Sebelumnya']);
        TeachingSchedule::create([
            'academic_term_id' => $term->id,
            'lecturer_id' => $lecturer->id,
            'course_id' => $course->id,
            'class_name' => 'IF 27 A',
            'delivery_mode' => 'ONLINE',
            'day_of_week' => 1,
            'starts_local' => '07:00:00',
            'ends_local' => '08:40:00',
            'is_active' => true,
        ]);
        $payload = [
            'name' => 'Supervisi Mutu Ganjil 2027/2028',
            'academic_year' => '2027/2028',
            'semester' => 'GANJIL',
            'stage_1_starts_on' => '2027-09-01',
            'stage_1_ends_on' => '2027-10-31',
            'stage_2_starts_on' => '2027-11-01',
            'stage_2_ends_on' => '2028-01-31',
            'document_deadline_at' => 'invalid-legacy-value',
            'status' => 'ACTIVE',
        ];

        $response = $this->actingAs($this->admin)->post(route('lpm.periods.store'), $payload);
        $response->assertRedirect(route('lpm.periods.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('academic_terms', [
            'academic_year' => '2027/2028',
            'semester' => 'GANJIL',
        ]);

        $this->assertDatabaseHas('supervision_periods', [
            'name' => $payload['name'],
            'status' => 'ACTIVE',
        ]);

        // Auto-scaffolded stages
        $period = SupervisionPeriod::whereHas('academicTerm', fn ($query) => $query->where('academic_year', '2027/2028')->where('semester', 'GANJIL'))->first();
        $this->assertNotNull($period);
        $this->assertEquals(2, $period->stages()->count());
        foreach ($period->stages as $stage) {
            $this->assertSame($payload['stage_'.$stage->stage_no.'_starts_on'], $stage->starts_on->toDateString());
            $this->assertSame($payload['stage_'.$stage->stage_no.'_ends_on'], $stage->ends_on->toDateString());
            $this->assertSame(2, $stage->instruments()->count());
        }
        $this->assertSame(0, $period->targets()->count());
        $this->assertSame(0, $period->supervisors()->count());

        $this->get(route('lpm.assignments.index'))->assertOk();
        $this->assertSame(0, $period->targets()->count());
    }

    public function test_admin_can_name_a_semester_period_and_add_each_stage_separately(): void
    {
        Lecturer::create([
            'name' => 'Dosen Master Sebelum Tahap Baru',
            'external_code' => 'DSN-OLD-MASTER-NEW-STAGE',
            'is_active' => true,
        ]);
        $first = [
            'name' => 'Supervisi Ganjil 2030/2031',
            'academic_year' => '2030/2031',
            'semester' => 'GANJIL',
            'stage_no' => 1,
            'starts_on' => '2030-09-01',
            'ends_on' => '2030-10-31',
            'status' => 'ACTIVE',
        ];

        $this->actingAs($this->admin)->post(route('lpm.periods.store'), $first)
            ->assertRedirect(route('lpm.periods.index'))->assertSessionHasNoErrors();

        $period = SupervisionPeriod::where('name', $first['name'])->firstOrFail();
        $this->assertSame(1, $period->stages()->count());
        $this->assertSame(0, $period->targets()->count());
        $this->assertSame(0, $period->supervisors()->count());

        $second = [
            ...$first,
            'stage_no' => 2,
            'starts_on' => '2031-01-01',
            'ends_on' => '2031-01-31',
        ];
        $this->actingAs($this->admin)->post(route('lpm.periods.store'), $second)
            ->assertRedirect(route('lpm.periods.index'))->assertSessionHasNoErrors();

        $this->assertSame(2, $period->stages()->count());
        $this->assertSame(1, SupervisionPeriod::where('academic_term_id', $period->academic_term_id)->count());
        $this->assertSame('2031-01-31', $period->fresh()->ends_on->toDateString());
        $this->assertSame('2030/2031', $period->fresh('academicTerm')->academicTerm->academic_year);

        $this->actingAs($this->admin)->post(route('lpm.periods.store'), [
            ...$second,
            'period_id' => $period->id,
        ])->assertSessionHasErrors('stage_no');
        $this->actingAs($this->admin)->post(route('lpm.periods.store'), $second)
            ->assertSessionHasErrors('stage_no');
        $this->assertSame(2, $period->stages()->count());

        $this->actingAs($this->admin)->put(route('lpm.periods.update', $period), [
            ...$second,
            'name' => 'Supervisi Mutu Ganjil 2030/2031',
            'starts_on' => '2030-11-15',
        ])->assertRedirect(route('lpm.periods.index'))->assertSessionHasNoErrors();

        $this->assertSame('Supervisi Mutu Ganjil 2030/2031', $period->fresh()->name);
        $this->assertSame('2030-11-15', $period->stages()->where('stage_no', 2)->firstOrFail()->starts_on->toDateString());
    }

    public function test_stage_added_to_an_existing_period_cannot_overlap_the_other_stage(): void
    {
        $this->actingAs($this->admin)->post(route('lpm.periods.store'), [
            'name' => 'Supervisi Ganjil 2032/2033',
            'academic_year' => '2032/2033',
            'semester' => 'GANJIL',
            'stage_no' => 1,
            'starts_on' => '2032-09-01',
            'ends_on' => '2032-10-31',
            'status' => 'ACTIVE',
        ])->assertSessionHasNoErrors();

        $period = SupervisionPeriod::where('name', 'Supervisi Ganjil 2032/2033')->firstOrFail();

        $this->actingAs($this->admin)->post(route('lpm.periods.store'), [
            'name' => $period->name,
            'academic_year' => '2032/2033',
            'semester' => 'GANJIL',
            'stage_no' => 2,
            'starts_on' => '2032-10-31',
            'ends_on' => '2033-01-31',
            'status' => 'ACTIVE',
        ])->assertSessionHasErrors('starts_on');

        $this->actingAs($this->admin)->post(route('lpm.periods.store'), [
            'period_id' => $period->id,
            'name' => $period->name,
            'academic_year' => '2033/2034',
            'semester' => 'GANJIL',
            'stage_no' => 2,
            'starts_on' => '2033-09-01',
            'ends_on' => '2033-10-31',
            'status' => 'ACTIVE',
        ])->assertSessionHasErrors('academic_year');

        $this->assertSame(1, $period->stages()->count());
    }

    public function test_academic_year_is_required_and_must_match_the_stage_dates(): void
    {
        $payload = [
            'name' => 'Supervisi Ganjil 2034/2035',
            'semester' => 'GANJIL',
            'stage_no' => 1,
            'starts_on' => '2034-09-01',
            'ends_on' => '2034-10-31',
            'status' => 'ACTIVE',
        ];

        $this->actingAs($this->admin)->post(route('lpm.periods.store'), $payload)
            ->assertSessionHasErrors('academic_year');
        $this->actingAs($this->admin)->post(route('lpm.periods.store'), [
            ...$payload,
            'academic_year' => '2034/2036',
        ])->assertSessionHasErrors('academic_year');
        $this->actingAs($this->admin)->post(route('lpm.periods.store'), [
            ...$payload,
            'academic_year' => '2035/2036',
        ])->assertSessionHasErrors('starts_on');

        $this->assertDatabaseMissing('supervision_periods', ['name' => $payload['name']]);
    }

    public function test_same_semester_in_different_academic_years_uses_different_terms(): void
    {
        foreach ([2034, 2035] as $year) {
            $this->actingAs($this->admin)->post(route('lpm.periods.store'), [
                'name' => 'Supervisi Semester Ganjil',
                'academic_year' => $year.'/'.($year + 1),
                'semester' => 'GANJIL',
                'stage_no' => 1,
                'starts_on' => $year.'-09-01',
                'ends_on' => $year.'-10-31',
                'status' => 'CLOSED',
            ])->assertSessionHasNoErrors();
        }

        $periods = SupervisionPeriod::where('name', 'Supervisi Semester Ganjil')->with('academicTerm')->get();
        $this->assertCount(2, $periods);
        $this->assertNotSame($periods[0]->academic_term_id, $periods[1]->academic_term_id);
        $this->assertEqualsCanonicalizing(['2034/2035', '2035/2036'], $periods->pluck('academicTerm.academic_year')->all());
    }

    public function test_stage_two_must_start_after_stage_one_ends(): void
    {
        $periodCount = SupervisionPeriod::count();

        $this->actingAs($this->admin)->post(route('lpm.periods.store'), [
            'academic_year' => '2027/2028',
            'semester' => 'GANJIL',
            'stage_1_starts_on' => '2027-09-01',
            'stage_1_ends_on' => '2027-11-30',
            'stage_2_starts_on' => '2027-11-30',
            'stage_2_ends_on' => '2028-01-31',
            'status' => 'ACTIVE',
        ])->assertSessionHasErrors('stage_2_starts_on');

        $this->assertSame($periodCount, SupervisionPeriod::count());
    }

    public function test_both_stage_date_ranges_are_required_when_creating_a_period(): void
    {
        $periodCount = SupervisionPeriod::count();

        $this->actingAs($this->admin)->post(route('lpm.periods.store'), [
            'name' => 'Supervisi Belum Lengkap',
            'academic_year' => '2027/2028',
            'semester' => 'GANJIL',
            'stage_1_starts_on' => '2027-09-01',
            'stage_1_ends_on' => '2027-10-31',
            'status' => 'ACTIVE',
        ])->assertSessionHasErrors(['stage_2_starts_on', 'stage_2_ends_on']);

        $this->assertSame($periodCount, SupervisionPeriod::count());
    }

    public function test_creating_active_period_closes_other_active_periods(): void
    {
        $firstPeriod = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id,
            'name' => 'Periode Lama Aktif',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-01-31',
            'status' => 'ACTIVE',
            'created_by' => $this->admin->id,
        ]);

        $payload = [
            'academic_year' => '2028/2029',
            'semester' => 'GENAP',
            'stage_1_starts_on' => '2029-02-01',
            'stage_1_ends_on' => '2029-04-30',
            'stage_2_starts_on' => '2029-05-01',
            'stage_2_ends_on' => '2029-07-31',
            'status' => 'ACTIVE',
        ];

        $this->actingAs($this->admin)->post(route('lpm.periods.store'), $payload);

        $this->assertEquals('CLOSED', $firstPeriod->fresh()->status);
        $this->assertDatabaseHas('supervision_periods', [
            'name' => 'Supervisi Semester Genap',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_superadmin_can_edit_the_semester_and_dates_with_an_academic_year(): void
    {
        $period = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id,
            'name' => 'Periode Semester Ganjil',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-01-31',
            'status' => 'CLOSED',
            'created_by' => $this->admin->id,
        ]);

        $stageOne = $period->stages()->create([
            'stage_no' => 1,
            'name' => 'Tahap 1',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-10-31',
        ]);
        $payload = [
            'name' => 'Supervisi Mutu Genap 2026/2027',
            'academic_year' => '2026/2027',
            'semester' => 'GENAP',
            'stage_1_starts_on' => '2027-02-01',
            'stage_1_ends_on' => '2027-04-30',
            'stage_2_starts_on' => '2027-05-01',
            'stage_2_ends_on' => '2027-07-31',
            'document_deadline_at' => 'invalid-legacy-value',
            'status' => 'ACTIVE',
        ];
        $this->actingAs($this->admin)->put(route('lpm.periods.update', $period), $payload)
            ->assertRedirect(route('lpm.periods.index'))->assertSessionHasNoErrors();

        $period = $period->fresh('academicTerm');
        $this->assertSame('GENAP', $period->academicTerm->semester);
        $this->assertSame('2027-02-01', $period->starts_on->toDateString());
        $this->assertSame('2027-07-31', $period->ends_on->toDateString());
        $this->assertSame($payload['name'], $period->name);
        $this->assertSame(2, $period->stages()->count());
        $this->assertSame($stageOne->id, $period->stages()->where('stage_no', 1)->firstOrFail()->id);
        foreach ($period->stages as $stage) {
            $this->assertSame(2, $stage->instruments()->count());
        }
        $this->assertSame('2027-04-30', $period->stages()->where('stage_no', 1)->firstOrFail()->ends_on->toDateString());
        $this->assertSame('2027-05-01', $period->stages()->where('stage_no', 2)->firstOrFail()->starts_on->toDateString());
        $this->assertDatabaseHas('supervision_periods', ['id' => $period->id, 'status' => 'ACTIVE']);
        $this->actingAs($this->admin)->get(route('lpm.periods.index'))
            ->assertOk()->assertSee('Semester Genap')->assertSee('name="academic_year"', false);

        $stageIds = $period->stages()->orderBy('stage_no')->pluck('id')->all();
        $payload['stage_1_ends_on'] = '2027-04-15';
        $payload['stage_2_starts_on'] = '2027-04-16';
        $this->put(route('lpm.periods.update', $period), $payload)
            ->assertRedirect(route('lpm.periods.index'))->assertSessionHasNoErrors();
        $this->assertSame($stageIds, $period->stages()->orderBy('stage_no')->pluck('id')->all());
        $this->assertSame('2027-04-15', $stageOne->fresh()->ends_on->toDateString());
        $this->assertSame('2027-04-16', $period->stages()->where('stage_no', 2)->firstOrFail()->starts_on->toDateString());

        $this->put(route('lpm.periods.update', $period), [
            ...$payload,
            'name' => 'Perubahan Tidak Valid',
            'stage_2_starts_on' => '2027-04-15',
        ])->assertSessionHasErrors('stage_2_starts_on');
        $this->assertSame($payload['name'], $period->fresh()->name);
        $this->assertSame('2027-04-16', $period->stages()->where('stage_no', 2)->firstOrFail()->starts_on->toDateString());
    }

    public function test_superadmin_can_toggle_period_status(): void
    {
        $period = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id,
            'name' => 'Periode Uji Toggle',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-01-31',
            'status' => 'ACTIVE',
            'created_by' => $this->admin->id,
        ]);

        // Toggle from ACTIVE to CLOSED
        $response = $this->actingAs($this->admin)->post(route('lpm.periods.toggle_status', $period));
        $response->assertRedirect(route('lpm.periods.index'));
        $this->assertEquals('CLOSED', $period->fresh()->status);

        // Toggle back to ACTIVE
        $response = $this->actingAs($this->admin)->post(route('lpm.periods.toggle_status', $period));
        $response->assertRedirect(route('lpm.periods.index'));
        $this->assertEquals('ACTIVE', $period->fresh()->status);
    }

    public function test_superadmin_cannot_delete_active_period(): void
    {
        $period = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id,
            'name' => 'Periode Aktif Tidak Boleh Dihapus',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-01-31',
            'status' => 'ACTIVE',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->delete(route('lpm.periods.destroy', $period));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('supervision_periods', ['id' => $period->id]);
    }

    public function test_superadmin_can_delete_closed_period(): void
    {
        $period = SupervisionPeriod::create([
            'academic_term_id' => $this->term->id,
            'name' => 'Periode Ditutup Yang Dihapus',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-01-31',
            'status' => 'CLOSED',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->delete(route('lpm.periods.destroy', $period));
        $response->assertRedirect(route('lpm.periods.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('supervision_periods', ['id' => $period->id]);
    }

    public function test_schedule_import_automatically_binds_to_active_term(): void
    {
        $term = AcademicTerm::firstOrCreate([
            'academic_year' => '2026/2027',
            'semester' => 'GANJIL',
        ], [
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-01-31',
        ]);
        SupervisionPeriod::firstOrCreate([
            'academic_term_id' => $term->id,
            'name' => 'Periode Aktif Uji',
        ], [
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-01-31',
            'status' => 'ACTIVE',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('lpm.import.index'));
        $response->assertOk();
        $response->assertSee('Semester Supervisi', false);
        $response->assertDontSee('Tahun Akademik');
        $response->assertSee('Aktif');
        $response->assertDontSee('Kelola / Tambah Periode Supervisi');
        $response->assertSee('Semester Ganjil');
    }
}
