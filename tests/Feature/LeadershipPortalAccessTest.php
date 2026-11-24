<?php

namespace Tests\Feature;

use App\Models\Faculty;
use App\Models\Supervision;
use App\Models\SupervisionPeriod;
use App\Models\User;
use Database\Seeders\CompleteDemoDataSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeadershipPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_accounts_remain_available_before_the_leadership_role_is_installed(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        Role::findByName('pimpinan', 'web')->delete();
        $admin = $this->createAccount('lpm_admin');
        $facultyAccount = $this->createAccount('fakultas');
        $accountsUrl = route('lpm.unit_accounts.index');

        $this->actingAs($admin)->get($accountsUrl)->assertOk()
            ->assertSee($facultyAccount->email)
            ->assertSee('Akun Pimpinan tersedia setelah migrasi peran dijalankan.')
            ->assertSee('<option value="pimpinan" disabled>', false);
        $this->get(route('lpm.unit_accounts.index', ['role' => 'pimpinan']))
            ->assertOk()->assertDontSee($facultyAccount->email);
        $this->from($accountsUrl)->post(route('lpm.unit_accounts.store'), [
            'role' => 'pimpinan', 'name' => 'Wakil Rektor', 'email' => 'pending@example.test',
        ])->assertRedirect($accountsUrl)->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'pending@example.test']);
    }

    public function test_lpm_can_create_a_leadership_account_that_opens_the_report_after_password_change(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = $this->createAccount('lpm_admin');
        $email = 'pimpinan@example.test';

        $this->actingAs($admin)->from(route('lpm.unit_accounts.index'))->post(route('lpm.unit_accounts.store'), [
            'role' => 'pimpinan', 'name' => 'Wakil Rektor', 'email' => $email,
        ])->assertRedirect()->assertSessionHas('temporary_password.email', $email);
        $temporaryPassword = session('temporary_password.password');
        $leadership = User::where('email', $email)->sole();
        $this->assertSame(['pimpinan'], $leadership->getRoleNames()->all());
        $this->assertNull($leadership->faculty_id);
        $this->assertNull($leadership->study_program_id);

        $this->get(route('lpm.unit_accounts.index', ['role' => 'pimpinan']))
            ->assertSee($email)->assertSee('Semua Fakultas');
        $this->get(route('lpm.unit_accounts.show', $leadership))->assertOk()->assertSee('Detail Akun Pimpinan');
        $facultyAccount = $this->createAccount('fakultas');
        $this->put(route('lpm.unit_accounts.update', $facultyAccount), [
            'role' => 'pimpinan', 'name' => $facultyAccount->name, 'email' => $facultyAccount->email, 'is_active' => true,
        ])->assertSessionHasErrors('role');
        $this->assertTrue($facultyAccount->fresh()->hasRole('fakultas'));
        $this->post(route('logout'))->assertRedirect();

        $this->post(route('login'), ['email' => $email, 'password' => $temporaryPassword])
            ->assertRedirect(route('password.change'));
        $this->get(route('pimpinan.faculties.index'))->assertRedirect(route('password.change'));
        $this->post(route('password.update'), [
            'current_password' => $temporaryPassword,
            'password' => 'AmanPimpinan2026!Sandi',
            'password_confirmation' => 'AmanPimpinan2026!Sandi',
        ])->assertRedirect(route('pimpinan.faculties.index'));
        $this->get(route('login'))->assertRedirect(route('pimpinan.faculties.index'));
    }

    public function test_leadership_can_open_each_faculty_and_filter_its_cohort_and_reports_by_program(): void
    {
        $this->seed(CompleteDemoDataSeeder::class);
        $period = SupervisionPeriod::active()->sole();
        $faculties = Faculty::whereIn('code', ['FTIK', 'FEB', 'FSIP'])->get();
        $faculty = $faculties->firstWhere('code', 'FTIK');
        $outsideFaculty = $faculties->firstWhere('code', 'FEB');
        $program = $faculty->studyPrograms()->where('code', 'IF')->sole();
        $outsideProgram = $outsideFaculty->studyPrograms()->where('code', 'MN')->sole();
        $submitted = Supervision::where('status', 'SUBMITTED')
            ->whereHas('assignment.supervisionStage', fn ($stages) => $stages->where('supervision_period_id', $period->id))
            ->whereHas('assignment.targetLecturer', fn ($lecturers) => $lecturers->where('study_program_id', $program->id));
        $expectedCount = $submitted->count();
        $result = $submitted->firstOrFail();
        $lecturer = $result->assignment->targetLecturer;
        $leadership = $this->createAccount('pimpinan');
        $filters = ['faculty' => $faculty, 'period_id' => $period->id, 'study_program_id' => $program->id];

        $directory = $this->actingAs($leadership)->get(route('pimpinan.faculties.index'))->assertOk();
        foreach ($faculties as $listedFaculty) {
            $directory->assertSee($listedFaculty->name)->assertSee(route('pimpinan.faculties.dashboard', $listedFaculty));
            $this->get(route('pimpinan.faculties.dashboard', $listedFaculty))->assertOk()
                ->assertSee('Dashboard '.$listedFaculty->name)
                ->assertSee(route('pimpinan.faculties.analytics.index', ['faculty' => $listedFaculty, 'period_id' => $period->id]));
        }
        $this->get(route('pimpinan.supervisions.index'))->assertRedirect(route('pimpinan.faculties.index'));

        $this->get(route('pimpinan.faculties.dashboard', $filters))->assertOk()->assertViewHas('submittedCount', $expectedCount);
        $this->get(route('pimpinan.faculties.analytics.index', $filters))->assertOk()->assertSee('Analisis Kohort T1/T2')
            ->assertSee($lecturer->name)->assertSee($program->name)->assertDontSee($outsideProgram->name)
            ->assertViewHas('analytics', fn (array $analytics): bool => collect($analytics['stage_results'])->isNotEmpty()
                && collect($analytics['stage_results'])->every(fn (array $row): bool => $row['prodi'] === $program->name));
        $this->get(route('pimpinan.faculties.reports.supervision', $filters))->assertOk()
            ->assertViewHas('summary', fn (array $summary): bool => $summary['submitted_count'] === $expectedCount)
            ->assertDontSee('Ekspor Excel');
        $this->get(route('pimpinan.faculties.lecturers.index', $filters))->assertOk()
            ->assertSee(route('pimpinan.faculties.lecturers.show', $filters + ['lecturer' => $lecturer]))
            ->assertDontSee($outsideProgram->name)->assertDontSee('Tambah Dosen');
        $this->get(route('pimpinan.faculties.lecturers.show', $filters + ['lecturer' => $lecturer]))->assertOk()
            ->assertSee(route('pimpinan.faculties.supervisions.pdf', ['faculty' => $faculty, 'supervision' => $result, 'study_program_id' => $program->id]))
            ->assertDontSee('Tambah Jadwal');
        $this->get(route('pimpinan.faculties.supervisions.pdf', ['faculty' => $faculty, 'supervision' => $result]))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('pimpinan.faculties.supervisions.pdf', ['faculty' => $outsideFaculty, 'supervision' => $result]))->assertNotFound();
        $this->get(route('pimpinan.faculties.lecturers.show', ['faculty' => $outsideFaculty, 'lecturer' => $lecturer]))->assertNotFound();
        foreach (['dashboard', 'analytics.index', 'reports.supervision', 'lecturers.index'] as $section) {
            $this->get(route('pimpinan.faculties.'.$section, ['faculty' => $faculty, 'study_program_id' => $outsideProgram->id]))->assertNotFound();
        }
    }

    public function test_leadership_cannot_access_drafts_cancelled_results_or_admin_actions(): void
    {
        $this->seed(CompleteDemoDataSeeder::class);
        $period = SupervisionPeriod::active()->sole();
        [$draft, $cancelled] = Supervision::where('status', 'SUBMITTED')->orderBy('id')->limit(2)->get()->all();
        $draft->update(['status' => 'DRAFT']);
        $cancelled->assignment->update(['status' => 'CANCELLED']);
        $leadership = $this->createAccount('pimpinan');

        $this->actingAs($leadership)->get(route('pimpinan.faculties.supervisions.pdf', [
            'faculty' => $draft->assignment->targetLecturer->faculty_id, 'supervision' => $draft,
        ]))->assertForbidden();
        $this->get(route('pimpinan.faculties.supervisions.pdf', [
            'faculty' => $cancelled->assignment->targetLecturer->faculty_id, 'supervision' => $cancelled,
        ]))->assertForbidden();
        $this->get(route('lpm.dashboard'))->assertForbidden();
        $this->get(route('lpm.reports.supervision.excel', ['period_id' => $period->id]))->assertForbidden();
        $this->post(route('lpm.unit_accounts.store'), ['role' => 'pimpinan'])->assertForbidden();
        $this->patch(route('lpm.lecturers.update_profile', $draft->assignment->targetLecturer), [])->assertForbidden();
        $this->get(route('supervisor.dashboard'))->assertForbidden();
    }

    #[TestWith(['fakultas'])]
    #[TestWith(['prodi'])]
    #[TestWith(['supervisor'])]
    public function test_non_leadership_roles_cannot_open_the_cross_faculty_portal(string $role): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $account = $this->createAccount($role);

        $this->actingAs($account)->get(route('pimpinan.faculties.index'))->assertForbidden();
    }

    private function createAccount(string $role): User
    {
        $account = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $account->assignRole($role);

        return $account;
    }
}
