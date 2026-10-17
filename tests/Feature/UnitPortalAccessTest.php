<?php

namespace Tests\Feature;

use App\Models\Faculty;
use App\Models\Lecturer;
use App\Models\PeriodTarget;
use App\Models\StudyProgram;
use App\Models\Supervision;
use App\Models\SupervisionPeriod;
use App\Models\User;
use Database\Seeders\CompleteDemoDataSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UnitPortalAccessTest extends TestCase
{
    use DatabaseTransactions;

    private User $lpm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sisd_teknokrat_test', DB::connection()->getDatabaseName());
        $this->seed(RoleAndPermissionSeeder::class);
        $this->lpm = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->lpm->assignRole('lpm_admin');
    }

    public function test_lpm_creates_and_manages_accounts_with_one_required_unit(): void
    {
        $faculty = Faculty::create(['code' => 'UNIT-FTIK', 'name' => 'Fakultas Unit FTIK', 'is_active' => true]);
        $program = StudyProgram::create(['faculty_id' => $faculty->id, 'code' => 'UNIT-IF', 'name' => 'Prodi Unit IF', 'is_active' => true]);
        $otherProgram = StudyProgram::create(['faculty_id' => $faculty->id, 'code' => 'UNIT-SI', 'name' => 'Prodi Unit SI', 'is_active' => true]);
        $this->actingAs($this->lpm)->get(route('lpm.unit_accounts.index'))->assertOk()->assertSee('Akun Fakultas dan Prodi');

        $creation = $this->post(route('lpm.unit_accounts.store'), ['name' => 'Akun Fakultas', 'email' => 'unit-fakultas@example.test',
            'role' => 'fakultas', 'faculty_id' => $faculty->id]);
        $creation->assertRedirect()->assertSessionHas('temporary_password.email', 'unit-fakultas@example.test');
        $this->assertStringEndsWith('#temporary-password', $creation->headers->get('Location'));
        $temporaryPassword = session('temporary_password.password');
        $this->assertNotEmpty($temporaryPassword);
        $facultyUser = User::where('email', 'unit-fakultas@example.test')->sole();
        $this->assertTrue(Hash::check($temporaryPassword, $facultyUser->password));
        $this->get(route('lpm.unit_accounts.index'))->assertOk()
            ->assertSee('Kata sandi sementara akun')->assertSee($temporaryPassword);
        $this->get(route('lpm.unit_accounts.index'))->assertOk()->assertDontSee($temporaryPassword);
        $this->assertTrue($facultyUser->hasRole('fakultas'));
        $this->assertSame($faculty->id, $facultyUser->faculty_id);
        $this->assertNull($facultyUser->study_program_id);
        $this->assertTrue($facultyUser->must_change_password);

        $this->post(route('lpm.unit_accounts.store'), ['name' => 'Tidak Valid', 'email' => 'invalid@example.test',
            'role' => 'prodi', 'study_program_id' => 999999])->assertSessionHasErrors('study_program_id');
        $this->assertDatabaseMissing('users', ['email' => 'invalid@example.test']);

        $this->post(route('lpm.unit_accounts.store'), ['name' => 'Akun Prodi', 'email' => 'unit-prodi@example.test',
            'role' => 'prodi', 'study_program_id' => $program->id])->assertRedirect();
        $programUser = User::where('email', 'unit-prodi@example.test')->sole();
        $this->assertTrue($programUser->hasRole('prodi'));
        $this->assertSame($program->id, $programUser->study_program_id);
        $this->assertSame($faculty->id, $programUser->faculty_id);

        $this->post(route('lpm.unit_accounts.store'), ['name' => 'Duplikat Fakultas', 'email' => 'duplikat-fakultas@example.test',
            'role' => 'fakultas', 'faculty_id' => $faculty->id])->assertSessionHasErrors('faculty_id');
        $this->post(route('lpm.unit_accounts.store'), ['name' => 'Duplikat Prodi', 'email' => 'duplikat-prodi@example.test',
            'role' => 'prodi', 'study_program_id' => $program->id])->assertSessionHasErrors('study_program_id');
        $this->assertDatabaseMissing('users', ['email' => 'duplikat-fakultas@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'duplikat-prodi@example.test']);

        $this->put(route('lpm.unit_accounts.update', $facultyUser), ['name' => 'Akun Prodi Baru',
            'email' => $facultyUser->email, 'role' => 'prodi', 'study_program_id' => $program->id, 'is_active' => 1])
            ->assertSessionHasErrors('study_program_id');
        $this->assertTrue($facultyUser->fresh()->hasRole('fakultas'));

        $this->put(route('lpm.unit_accounts.update', $facultyUser), ['name' => 'Akun Prodi Baru',
            'email' => $facultyUser->email, 'role' => 'prodi', 'study_program_id' => $otherProgram->id, 'is_active' => 1])
            ->assertRedirect(route('lpm.unit_accounts.show', $facultyUser));
        $this->assertTrue($facultyUser->fresh()->hasRole('prodi'));
        $this->assertSame($otherProgram->id, $facultyUser->fresh()->study_program_id);
        $this->get(route('lpm.unit_accounts.show', $facultyUser))->assertOk()
            ->assertSee('Detail Akun Unit')->assertSee('Reset kata sandi');
        $reset = $this->post(route('lpm.unit_accounts.reset_password', $facultyUser));
        $reset->assertRedirect(route('lpm.unit_accounts.show', $facultyUser).'#temporary-password')
            ->assertSessionHas('temporary_password.email', 'unit-fakultas@example.test');
        $this->assertStringEndsWith('#temporary-password', $reset->headers->get('Location'));
        $resetPassword = session('temporary_password.password');
        $this->assertTrue(Hash::check($resetPassword, $facultyUser->fresh()->password));
        $this->get(route('lpm.unit_accounts.show', $facultyUser))->assertOk()->assertSee($resetPassword);
        $this->get(route('lpm.unit_accounts.show', $facultyUser))->assertOk()->assertDontSee($resetPassword);
        $this->assertTrue($facultyUser->fresh()->must_change_password);
    }

    public function test_lpm_deletes_only_unit_accounts_and_clears_login_credentials(): void
    {
        $faculty = Faculty::create(['code' => 'DELETE-FTIK', 'name' => 'Fakultas Hapus Akun', 'is_active' => true]);
        $unitAccount = User::factory()->create(['faculty_id' => $faculty->id, 'is_active' => true, 'must_change_password' => false]);
        $unitAccount->assignRole('fakultas');
        $otherAccount = User::factory()->create(['faculty_id' => $faculty->id]);
        $otherAccount->assignRole('prodi');
        $protectedAccount = User::factory()->create(['faculty_id' => $faculty->id]);
        $protectedAccount->assignRole(['fakultas', 'lpm_admin']);

        DB::table('sessions')->insert([
            'id' => 'unit-account-delete-session', 'user_id' => $unitAccount->id,
            'payload' => 'test', 'last_activity' => now()->timestamp,
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $unitAccount->email, 'token' => 'test', 'created_at' => now(),
        ]);

        $this->actingAs($unitAccount)->delete(route('lpm.unit_accounts.destroy', $otherAccount))->assertForbidden();
        $this->actingAs($this->lpm)->delete(route('lpm.unit_accounts.destroy', $this->lpm))->assertNotFound();
        $this->delete(route('lpm.unit_accounts.destroy', $protectedAccount))->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $protectedAccount->id]);

        $this->delete(route('lpm.unit_accounts.destroy', $unitAccount))->assertRedirect(route('lpm.unit_accounts.index'));
        $this->assertDatabaseMissing('users', ['id' => $unitAccount->id]);
        $this->assertDatabaseMissing('model_has_roles', ['model_id' => $unitAccount->id, 'model_type' => User::class]);
        $this->assertDatabaseMissing('sessions', ['id' => 'unit-account-delete-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $unitAccount->email]);
        $this->assertDatabaseHas('system_audits', ['event' => 'UNIT_ACCOUNT_DELETED', 'entity_id' => $unitAccount->id]);
        $this->assertDatabaseHas('users', ['id' => $otherAccount->id]);
        $this->assertDatabaseHas('faculties', ['id' => $faculty->id]);

        $this->post(route('lpm.unit_accounts.store'), ['name' => 'Akun Fakultas Pengganti',
            'email' => 'pengganti-unit@example.test', 'role' => 'fakultas',
            'faculty_id' => $faculty->id])->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'pengganti-unit@example.test', 'faculty_id' => $faculty->id]);
    }

    public function test_inactive_and_legacy_duplicate_accounts_occupy_their_unit_until_removed(): void
    {
        $faculty = Faculty::create(['code' => 'OCCUPIED-FTIK', 'name' => 'Fakultas Akun Lama', 'is_active' => true]);
        $program = StudyProgram::create(['faculty_id' => $faculty->id, 'code' => 'OCCUPIED-IF', 'name' => 'Prodi Akun Lama', 'is_active' => true]);
        $inactive = User::factory()->create(['faculty_id' => $faculty->id, 'is_active' => false]);
        $inactive->assignRole('fakultas');
        $duplicate = User::factory()->create(['faculty_id' => $faculty->id]);
        $duplicate->assignRole('fakultas');

        $this->actingAs($this->lpm)->get(route('lpm.unit_accounts.index', ['role' => 'fakultas']))
            ->assertOk()->assertSee('Perlu ditinjau')->assertSee('Sudah memiliki akun');
        $this->put(route('lpm.unit_accounts.update', $inactive), ['name' => 'Nama Koreksi',
            'email' => $inactive->email, 'role' => 'fakultas', 'faculty_id' => $faculty->id, 'is_active' => 0])
            ->assertRedirect(route('lpm.unit_accounts.show', $inactive));
        $this->assertSame('Nama Koreksi', $inactive->fresh()->name);

        $this->delete(route('lpm.unit_accounts.destroy', $duplicate))->assertRedirect();
        $this->post(route('lpm.unit_accounts.store'), ['name' => 'Pengganti', 'email' => 'pengganti@example.test',
            'role' => 'fakultas', 'faculty_id' => $faculty->id])->assertSessionHasErrors('faculty_id');
        $this->post(route('lpm.unit_accounts.store'), ['name' => 'Akun Prodi', 'email' => 'prodi-occupied@example.test',
            'role' => 'prodi', 'study_program_id' => $program->id])->assertRedirect();
    }

    public function test_role_filter_keeps_the_selection_across_paginated_account_tables(): void
    {
        $faculty = Faculty::create(['code' => 'FILTER-FTIK', 'name' => 'Fakultas Filter', 'is_active' => true]);
        $facultyUser = User::factory()->create(['name' => 'Akun Fakultas Filter', 'faculty_id' => $faculty->id]);
        $facultyUser->assignRole('fakultas');
        for ($index = 1; $index <= 21; $index++) {
            $program = StudyProgram::create(['faculty_id' => $faculty->id, 'code' => 'FILTER-'.$index,
                'name' => 'Program Filter '.$index, 'is_active' => true]);
            $account = User::factory()->create(['name' => sprintf('Akun Prodi %02d', $index),
                'faculty_id' => $faculty->id, 'study_program_id' => $program->id]);
            $account->assignRole('prodi');
        }

        $this->actingAs($this->lpm)->get(route('lpm.unit_accounts.index', ['role' => 'fakultas']))
            ->assertOk()->assertSee($facultyUser->email)->assertDontSee('Akun Prodi 01');
        $this->get(route('lpm.unit_accounts.index', ['role' => 'prodi', 'page' => 2]))
            ->assertOk()->assertDontSee($facultyUser->email)->assertSee('Akun Prodi 21')
            ->assertViewHas('users', fn ($accounts): bool => $accounts->total() === 21 && $accounts->currentPage() === 2)
            ->assertSee('role=prodi');
    }

    public function test_unit_pages_only_show_lecturers_and_results_within_the_assigned_scope(): void
    {
        $this->seed(CompleteDemoDataSeeder::class);
        $faculty = Faculty::where('code', 'FTIK')->firstOrFail();
        $otherFaculty = Faculty::where('code', 'FEB')->firstOrFail();
        $program = StudyProgram::where('code', 'IF')->firstOrFail();
        $otherProgram = StudyProgram::where('code', 'SI')->firstOrFail();
        $period = SupervisionPeriod::active()->firstOrFail();
        $included = fn ($query) => $query->where('supervision_period_id', $period->id)->included();
        $insideFaculty = Lecturer::where('study_program_id', $program->id)->whereHas('periodTargets', $included)->firstOrFail();
        $sameFacultyOtherProgram = Lecturer::where('study_program_id', $otherProgram->id)->whereHas('periodTargets', $included)->firstOrFail();
        $outsideFaculty = Lecturer::where('faculty_id', $otherFaculty->id)->whereHas('periodTargets', $included)->firstOrFail();
        $facultyUser = User::factory()->create(['faculty_id' => $faculty->id, 'is_active' => true, 'must_change_password' => false]);
        $facultyUser->assignRole('fakultas');
        $programUser = User::factory()->create(['faculty_id' => $faculty->id, 'study_program_id' => $program->id,
            'is_active' => true, 'must_change_password' => false]);
        $programUser->assignRole('prodi');

        $this->actingAs($facultyUser)->get(route('unit.lecturers.index'))->assertOk()
            ->assertSee($insideFaculty->name)->assertSee($sameFacultyOtherProgram->name)->assertDontSee($outsideFaculty->name);
        $this->get(route('unit.lecturers.index', ['search' => $outsideFaculty->name]))
            ->assertOk()->assertViewHas('lecturers', fn ($page): bool => $page->total() === 0);
        $this->get(route('unit.dashboard'))->assertOk()
            ->assertViewHas('lecturerCount', Lecturer::where('faculty_id', $faculty->id)->where('is_active', true)->count());
        $this->get(route('unit.analytics.index', ['faculty_id' => $otherFaculty->id]))->assertOk()
            ->assertViewHas('analytics', fn (array $analytics): bool => collect($analytics['stage_results'])
                ->every(fn (array $row): bool => Lecturer::whereKey($row['target_lecturer_id'])->where('faculty_id', $faculty->id)->exists()));
        $expectedFacultySubmissions = Supervision::where('status', 'SUBMITTED')
            ->whereHas('assignment.supervisionStage', fn ($query) => $query->where('supervision_period_id', $period->id))
            ->whereHas('assignment.targetLecturer', fn ($query) => $query->where('faculty_id', $faculty->id))->count();
        $this->assertGreaterThan(0, $expectedFacultySubmissions);
        $this->assertLessThan(Supervision::where('status', 'SUBMITTED')->count(), $expectedFacultySubmissions);
        $this->get(route('unit.reports.supervision'))->assertOk()
            ->assertViewHas('summary', fn (array $summary): bool => $summary['submitted_count'] === $expectedFacultySubmissions);

        $this->actingAs($programUser)->get(route('unit.lecturers.index'))->assertOk()
            ->assertSee($insideFaculty->name)->assertDontSee($sameFacultyOtherProgram->name)->assertDontSee($outsideFaculty->name);
        $this->get(route('unit.analytics.index', ['study_program_id' => $otherProgram->id]))->assertOk()
            ->assertViewHas('analytics', fn (array $analytics): bool => collect($analytics['stage_results'])
                ->every(fn (array $row): bool => Lecturer::whereKey($row['target_lecturer_id'])->where('study_program_id', $program->id)->exists()));
        $expectedProgramSubmissions = Supervision::where('status', 'SUBMITTED')
            ->whereHas('assignment.supervisionStage', fn ($query) => $query->where('supervision_period_id', $period->id))
            ->whereHas('assignment.targetLecturer', fn ($query) => $query->where('study_program_id', $program->id))->count();
        $this->assertGreaterThan(0, $expectedProgramSubmissions);
        $this->get(route('unit.reports.supervision'))->assertOk()
            ->assertViewHas('summary', fn (array $summary): bool => $summary['submitted_count'] === $expectedProgramSubmissions);
    }

    public function test_unit_directory_and_detail_are_read_only_and_period_scoped(): void
    {
        $this->seed(CompleteDemoDataSeeder::class);
        $period = SupervisionPeriod::active()->firstOrFail();
        $faculty = Faculty::where('code', 'FTIK')->firstOrFail();
        $program = StudyProgram::where('code', 'IF')->firstOrFail();
        $otherProgram = StudyProgram::where('code', 'SI')->firstOrFail();
        $included = fn ($query) => $query->where('supervision_period_id', $period->id)->included();
        $lecturer = Lecturer::where('study_program_id', $program->id)->whereHas('periodTargets', $included)->firstOrFail();
        $otherLecturer = Lecturer::where('study_program_id', $otherProgram->id)->whereHas('periodTargets', $included)->firstOrFail();
        $outsideLecturer = Lecturer::where('faculty_id', '<>', $faculty->id)->whereHas('periodTargets', $included)->firstOrFail();
        $facultyUser = User::factory()->create(['faculty_id' => $faculty->id, 'is_active' => true, 'must_change_password' => false]);
        $facultyUser->assignRole('fakultas');
        $programUser = User::factory()->create(['faculty_id' => $faculty->id, 'study_program_id' => $program->id,
            'is_active' => true, 'must_change_password' => false]);
        $programUser->assignRole('prodi');

        $this->actingAs($facultyUser)->get(route('unit.lecturers.index', ['period_id' => $period->id]))
            ->assertOk()->assertSee($lecturer->name)->assertSee($otherLecturer->name)
            ->assertDontSee($outsideLecturer->name)->assertSee('Kelengkapan Data')
            ->assertSee('Semua Program Studi')->assertDontSee('Tambah Dosen')
            ->assertDontSee('Edit dosen '.$lecturer->name)->assertDontSee('Hapus dosen '.$lecturer->name);
        $this->get(route('unit.lecturers.index', ['period_id' => $period->id, 'study_program_id' => $program->id]))
            ->assertOk()->assertSee($lecturer->name)->assertDontSee($otherLecturer->name);
        $this->get(route('unit.lecturers.index', ['period_id' => $period->id, 'study_program_id' => $outsideLecturer->study_program_id]))
            ->assertNotFound();
        $this->get(route('unit.lecturers.show', ['lecturer' => $lecturer, 'period_id' => $period->id]))
            ->assertOk()->assertSee('Jadwal Perkuliahan Dosen')->assertSee('Temuan Umum Supervisi')
            ->assertDontSee('Tambah Jadwal')->assertDontSee('Edit jadwal');
        $this->get(route('unit.lecturers.show', ['lecturer' => $outsideLecturer, 'period_id' => $period->id]))
            ->assertNotFound();
        $this->post(route('lpm.lecturers.store'), [])->assertForbidden();
        $this->patch(route('lpm.lecturers.update_profile', $lecturer), [])->assertForbidden();
        $this->delete(route('lpm.lecturers.destroy', $lecturer))->assertForbidden();

        $pastPeriod = SupervisionPeriod::create([
            'academic_term_id' => $period->academic_term_id, 'name' => 'Periode Arsip Unit',
            'starts_on' => '2025-10-01', 'ends_on' => '2025-11-30', 'status' => 'CLOSED',
            'created_by' => $this->lpm->id,
        ]);
        PeriodTarget::create(['supervision_period_id' => $pastPeriod->id, 'lecturer_id' => $lecturer->id,
            'scope_status' => 'INCLUDED', 'selected_by' => $this->lpm->id]);
        $futurePeriod = SupervisionPeriod::create([
            'academic_term_id' => $period->academic_term_id, 'name' => 'Periode Berikutnya Unit',
            'starts_on' => '2027-10-01', 'ends_on' => '2027-11-30', 'status' => 'DRAFT',
            'created_by' => $this->lpm->id,
        ]);

        $this->get(route('unit.lecturers.index', ['period_id' => $pastPeriod->id]))
            ->assertOk()->assertSee($lecturer->name)->assertDontSee($otherLecturer->name);
        $this->get(route('unit.lecturers.show', ['lecturer' => $lecturer, 'period_id' => $pastPeriod->id]))->assertOk();
        $this->get(route('unit.lecturers.show', ['lecturer' => $otherLecturer, 'period_id' => $pastPeriod->id]))->assertNotFound();
        $this->get(route('unit.lecturers.index', ['period_id' => $futurePeriod->id]))
            ->assertOk()->assertViewHas('lecturers', fn ($page): bool => $page->total() === 0);
        $this->get(route('unit.lecturers.show', ['lecturer' => $lecturer, 'period_id' => $futurePeriod->id]))->assertNotFound();
        $this->get(route('unit.dashboard', ['period_id' => $pastPeriod->id]))
            ->assertOk()->assertViewHas('period', fn ($selected): bool => $selected->id === $pastPeriod->id);
        $this->get(route('unit.dashboard', ['period_id' => $futurePeriod->id]))
            ->assertOk()->assertViewHas('submittedCount', 0);
        foreach (['unit.lecturers.index', 'unit.dashboard'] as $routeName) {
            $this->get(route($routeName, ['period_id' => 999999]))->assertNotFound();
        }
        $this->get(route('unit.lecturers.show', ['lecturer' => $lecturer, 'period_id' => 999999]))->assertNotFound();

        $this->actingAs($programUser)->get(route('unit.lecturers.index', ['period_id' => $period->id]))
            ->assertOk()->assertSee($lecturer->name)->assertDontSee($otherLecturer->name);
        $this->get(route('unit.lecturers.show', ['lecturer' => $otherLecturer, 'period_id' => $period->id]))->assertNotFound();

        $period->update(['status' => 'CLOSED']);
        $historicalSubmissions = Supervision::where('status', 'SUBMITTED')
            ->whereHas('assignment.supervisionStage', fn ($query) => $query->where('supervision_period_id', $period->id))
            ->whereHas('assignment.targetLecturer', fn ($query) => $query->where('faculty_id', $faculty->id))
            ->count();
        $this->actingAs($facultyUser)->get(route('unit.dashboard', ['period_id' => $period->id]))
            ->assertOk()->assertViewHas('submittedCount', $historicalSubmissions);
        $this->get(route('unit.lecturers.show', ['lecturer' => $lecturer, 'period_id' => $period->id]))
            ->assertOk()->assertSee('Hasil Supervisi');
    }

    public function test_unit_pdf_download_checks_target_scope_and_period_membership(): void
    {
        $this->seed(CompleteDemoDataSeeder::class);
        $period = SupervisionPeriod::active()->firstOrFail();
        $faculty = Faculty::where('code', 'FTIK')->firstOrFail();
        $facultyUser = User::factory()->create(['faculty_id' => $faculty->id, 'is_active' => true, 'must_change_password' => false]);
        $facultyUser->assignRole('fakultas');
        $inside = Supervision::where('status', 'SUBMITTED')
            ->whereHas('assignment.targetLecturer', fn ($query) => $query->where('faculty_id', $faculty->id))
            ->firstOrFail();
        $outside = Supervision::where('status', 'SUBMITTED')
            ->whereHas('assignment.targetLecturer', fn ($query) => $query->where('faculty_id', '<>', $faculty->id))
            ->firstOrFail();

        $this->actingAs($facultyUser)->get(route('unit.supervisions.pdf', $inside))->assertOk();
        $this->get(route('unit.supervisions.pdf', $outside))->assertForbidden();
        $program = $inside->assignment->targetLecturer->studyProgram;
        $programUser = User::factory()->create(['faculty_id' => $faculty->id, 'study_program_id' => $program->id,
            'is_active' => true, 'must_change_password' => false]);
        $programUser->assignRole('prodi');
        $this->actingAs($programUser)->get(route('unit.supervisions.pdf', $inside))->assertOk();
        $this->get(route('unit.supervisions.pdf', $outside))->assertForbidden();
        $sameFacultyOtherProgram = Supervision::where('status', 'SUBMITTED')
            ->whereHas('assignment.targetLecturer', fn ($query) => $query
                ->where('faculty_id', $faculty->id)->where('study_program_id', '<>', $program->id))
            ->firstOrFail();
        $this->get(route('unit.supervisions.pdf', $sameFacultyOtherProgram))->assertForbidden();
        $this->actingAs($facultyUser);
        PeriodTarget::where('supervision_period_id', $period->id)
            ->where('lecturer_id', $inside->assignment->target_lecturer_id)->update(['scope_status' => 'EXCLUDED']);
        $this->get(route('unit.supervisions.pdf', $inside))->assertForbidden();
        $this->get(route('lpm.supervisions.pdf', $inside))->assertForbidden();
        $this->actingAs($this->lpm)->get(route('lpm.supervisions.pdf', $inside))->assertOk();
    }

    public function test_unit_users_cannot_access_lpm_or_other_account_management_and_missing_scope_is_denied(): void
    {
        $faculty = Faculty::create(['code' => 'UNIT-FSIP', 'name' => 'Fakultas Unit FSIP', 'is_active' => true]);
        $facultyUser = User::factory()->create(['faculty_id' => $faculty->id, 'is_active' => true, 'must_change_password' => false]);
        $facultyUser->assignRole('fakultas');

        $this->actingAs($facultyUser)->get(route('lpm.dashboard'))->assertForbidden();
        $this->get(route('lpm.unit_accounts.index'))->assertForbidden();
        $this->post(route('lpm.unit_accounts.store'), ['name' => 'Tidak Boleh'])->assertForbidden();
        $this->get(route('lpm.unit_accounts.show', $facultyUser))->assertForbidden();
        $this->post(route('lpm.unit_accounts.reset_password', $facultyUser))->assertForbidden();
        $this->put(route('lpm.unit_accounts.update', $facultyUser), [])->assertForbidden();
        $this->delete(route('lpm.unit_accounts.destroy', $facultyUser))->assertForbidden();
        $this->get(route('unit.dashboard'))->assertOk();
        $this->get(route('login'))->assertRedirect(route('unit.dashboard'));

        $facultyUser->update(['faculty_id' => null]);
        $this->get(route('unit.dashboard'))->assertForbidden();

        $this->actingAs($this->lpm)->get(route('unit.dashboard'))->assertForbidden();
        $this->get(route('lpm.dashboard'))->assertOk();

        $supervisor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $supervisor->assignRole('supervisor');
        $this->actingAs($supervisor)->get(route('lpm.unit_accounts.index'))->assertForbidden();
        $this->get(route('lpm.unit_accounts.show', $facultyUser))->assertForbidden();
        $this->post(route('lpm.unit_accounts.store'), ['name' => 'Tidak Boleh'])->assertForbidden();
        $this->put(route('lpm.unit_accounts.update', $facultyUser), [])->assertForbidden();
        $this->post(route('lpm.unit_accounts.reset_password', $facultyUser))->assertForbidden();
        $this->delete(route('lpm.unit_accounts.destroy', $facultyUser))->assertForbidden();
    }
}
