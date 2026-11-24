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
use Tests\TestCase;

class LeadershipPortalAccessTest extends TestCase
{
    use RefreshDatabase;

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
        $this->get(route('pimpinan.reports.supervision'))->assertRedirect(route('password.change'));
        $this->post(route('password.update'), [
            'current_password' => $temporaryPassword,
            'password' => 'AmanPimpinan2026!Sandi',
            'password_confirmation' => 'AmanPimpinan2026!Sandi',
        ])->assertRedirect(route('pimpinan.reports.supervision'));
        $this->get(route('login'))->assertRedirect(route('pimpinan.reports.supervision'));
    }

    public function test_leadership_can_read_and_filter_submitted_results_from_multiple_faculties(): void
    {
        $this->seed(CompleteDemoDataSeeder::class);
        $period = SupervisionPeriod::active()->sole();
        $firstFaculty = Faculty::where('code', 'FTIK')->sole();
        $secondFaculty = Faculty::where('code', 'FEB')->sole();
        $submitted = Supervision::where('status', 'SUBMITTED')
            ->whereHas('assignment.supervisionStage', fn ($stages) => $stages->where('supervision_period_id', $period->id));
        $resultForFaculty = fn (Faculty $faculty): Supervision => (clone $submitted)
            ->whereHas('assignment.targetLecturer', fn ($lecturers) => $lecturers->where('faculty_id', $faculty->id))
            ->firstOrFail();
        $first = $resultForFaculty($firstFaculty);
        $second = $resultForFaculty($secondFaculty);
        $expectedCount = $submitted->count();
        $leadership = $this->createAccount('pimpinan');

        $this->actingAs($leadership)->get(route('pimpinan.reports.supervision', ['period_id' => $period->id]))
            ->assertSee('Ringkasan semua fakultas')
            ->assertViewHas('summary', fn (array $summary): bool => $summary['submitted_count'] === $expectedCount)
            ->assertSee(route('pimpinan.supervisions.index', ['period_id' => $period->id, 'stage' => 'all']))
            ->assertDontSee('Ekspor Excel');

        foreach ([$first, $second] as $result) {
            $lecturer = $result->assignment->targetLecturer;
            $this->get(route('pimpinan.supervisions.index', [
                'period_id' => $period->id, 'faculty_id' => $lecturer->faculty_id, 'stage' => 1,
            ]))->assertSee(route('pimpinan.supervisions.pdf', $result), false)
                ->assertViewHas('supervisedLecturers', fn ($page): bool => $page->isNotEmpty()
                    && $page->every(fn ($row): bool => $row->faculty_id === $lecturer->faculty_id));
            $this->get(route('pimpinan.supervisions.pdf', $result))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
        $this->get(route('pimpinan.supervisions.index', ['period_id' => $period->id, 'stage' => 2]))
            ->assertViewHas('supervisedLecturers', fn ($page): bool => $page->total() === 0);
    }

    public function test_leadership_cannot_access_drafts_cancelled_results_or_admin_actions(): void
    {
        $this->seed(CompleteDemoDataSeeder::class);
        $period = SupervisionPeriod::active()->sole();
        [$draft, $cancelled] = Supervision::where('status', 'SUBMITTED')->orderBy('id')->limit(2)->get()->all();
        $draft->update(['status' => 'DRAFT']);
        $cancelled->assignment->update(['status' => 'CANCELLED']);
        $leadership = $this->createAccount('pimpinan');

        $this->actingAs($leadership)->get(route('pimpinan.supervisions.index', [
            'period_id' => $period->id, 'status' => 'draft', 'search' => $draft->assignment->targetLecturer->name,
        ]))->assertViewHas('supervisedLecturers', fn ($page): bool => $page->total() === 0);
        $this->get(route('pimpinan.supervisions.pdf', $draft))->assertForbidden();
        $this->get(route('pimpinan.supervisions.pdf', $cancelled))->assertForbidden();
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

        $this->actingAs($account)->get(route('pimpinan.reports.supervision'))->assertForbidden();
        $this->get(route('pimpinan.supervisions.index'))->assertForbidden();
    }

    private function createAccount(string $role): User
    {
        $account = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $account->assignRole($role);

        return $account;
    }
}
