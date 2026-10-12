<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Faculty;
use App\Models\Lecturer;
use App\Models\PeriodSupervisor;
use App\Models\StudyProgram;
use App\Models\SupervisionPeriod;
use App\Models\SystemAudit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupervisorDirectoryTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected User $supervisorUser;

    protected SupervisionPeriod $period;

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

        $this->supervisorUser = User::firstOrCreate([
            'email' => 'supervisor_test@teknokrat.ac.id',
        ], [
            'name' => 'Dosen Supervisor Test',
            'password' => bcrypt('Password123!'),
        ]);

        if (! $this->supervisorUser->hasRole('supervisor')) {
            $this->supervisorUser->assignRole('supervisor');
        }

        $term = AcademicTerm::firstOrCreate([
            'academic_year' => '2033/2034',
            'semester' => 'GANJIL',
        ], [
            'starts_on' => '2033-09-01',
            'ends_on' => '2034-01-31',
        ]);
        $this->period = SupervisionPeriod::create([
            'academic_term_id' => $term->id,
            'name' => 'Periode Supervisor Uji',
            'starts_on' => $term->starts_on,
            'ends_on' => $term->ends_on,
            'status' => 'ACTIVE',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_guest_cannot_access_supervisor_directory(): void
    {
        $response = $this->get(route('lpm.supervisors.index'));
        $response->assertRedirect(route('login'));
    }

    public function test_supervisor_cannot_access_lpm_supervisor_directory(): void
    {
        $response = $this->actingAs($this->supervisorUser)->get(route('lpm.supervisors.index'));
        $response->assertStatus(403);
    }

    public function test_superadmin_can_access_supervisor_directory_successfully(): void
    {
        $lecturer = Lecturer::create([
            'name' => 'Dosen Pencarian Supervisor, M.Kom.',
            'external_code' => '0912345678',
            'is_active' => true,
        ]);
        $response = $this->actingAs($this->admin)->get(route('lpm.supervisors.index'));

        $response->assertStatus(200);
        $response->assertViewIs('lpm.supervisors.index');
        $response->assertSee('Daftar Tim Supervisor');
        $response->assertSee('Tetapkan Supervisor Baru');
        $response->assertSee('Cari nama dosen atau NIDN...');
        $response->assertSee('role="combobox"', false);
        $response->assertSee('type="hidden" name="lecturer_id"', false);
        $response->assertDontSee($lecturer->name);
        $this->actingAs($this->admin)->getJson(route('lpm.supervisors.candidates', $this->period))
            ->assertOk()->assertJsonFragment(['id' => $lecturer->id, 'name' => $lecturer->name, 'nidn' => '0912345678']);
    }

    public function test_superadmin_can_assign_new_supervisor(): void
    {
        $faculty = Faculty::first() ?? Faculty::create(['name' => 'Fakultas Teknik', 'code' => 'FT']);
        $prodi = StudyProgram::first() ?? StudyProgram::create(['name' => 'Teknik Komputer', 'code' => 'TK', 'faculty_id' => $faculty->id]);

        $lecturer = Lecturer::create([
            'name' => 'Calon Supervisor Baru, M.T.',
            'external_code' => 'TEST_SUP_001',
            'faculty_id' => $faculty->id,
            'study_program_id' => $prodi->id,
            'is_active' => true,
        ]);

        $period = $this->period;

        $response = $this->actingAs($this->admin)->post(route('lpm.supervisors.store'), [
            'lecturer_id' => $lecturer->id,
            'email' => 'calon.supervisor@teknokrat.ac.id',
            'supervision_period_id' => $period->id,
        ]);

        $response->assertRedirect(route('lpm.supervisors.index', ['period_id' => $period->id]));
        $response->assertSessionHas('success');
        $response->assertSessionHas('supervisor_initial_password');
        $initialPassword = Crypt::decryptString(session('supervisor_initial_password'));

        $user = User::where('email', 'calon.supervisor@teknokrat.ac.id')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('supervisor'));
        $this->assertEquals($lecturer->id, $user->lecturer_id);
        $this->assertTrue($user->must_change_password);
        $this->assertGreaterThanOrEqual(20, strlen($initialPassword));
        $this->assertNotSame($initialPassword, $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check($initialPassword, $user->password));
        $logPath = storage_path('logs/laravel.log');
        if (is_file($logPath)) {
            $this->assertFalse(str_contains(file_get_contents($logPath), $initialPassword));
        }

        $firstView = $this->get(route('lpm.supervisors.index', ['period_id' => $period->id]));
        $firstView->assertOk();
        $firstView->assertViewHas('initialPassword', fn ($password) => $password === $initialPassword);
        $this->assertTrue(str_contains($firstView->getContent(), e($initialPassword)));
        $secondView = $this->get(route('lpm.supervisors.index', ['period_id' => $period->id]));
        $secondView->assertOk();
        $secondView->assertViewHas('initialPassword', null);

        $secondLecturer = Lecturer::create([
            'name' => 'Calon Supervisor Kedua, M.T.',
            'external_code' => 'TEST_SUP_002',
            'is_active' => true,
        ]);
        $this->post(route('lpm.supervisors.store'), [
            'lecturer_id' => $secondLecturer->id,
            'email' => 'calon.supervisor.kedua@teknokrat.ac.id',
            'supervision_period_id' => $period->id,
        ])->assertSessionHas('supervisor_initial_password');
        $secondInitialPassword = Crypt::decryptString(session('supervisor_initial_password'));
        $this->assertNotSame($initialPassword, $secondInitialPassword);

        $secondUser = User::where('lecturer_id', $secondLecturer->id)->firstOrFail();
        $this->assertTrue($secondUser->must_change_password);
        $this->assertTrue(Hash::check($secondInitialPassword, $secondUser->password));

        $this->assertDatabaseHas('period_supervisors', [
            'supervision_period_id' => $period->id,
            'lecturer_id' => $lecturer->id,
            'is_active' => true,
        ]);
    }

    public function test_new_supervisor_must_change_initial_password_before_using_supervisor_routes(): void
    {
        $lecturer = Lecturer::create([
            'name' => 'Supervisor Login Pertama',
            'external_code' => 'TEST_SUP_LOGIN_001',
            'is_active' => true,
        ]);
        $email = 'supervisor.login.pertama@teknokrat.ac.id';

        $this->actingAs($this->admin)->post(route('lpm.supervisors.store'), [
            'lecturer_id' => $lecturer->id,
            'email' => $email,
            'supervision_period_id' => $this->period->id,
        ])->assertSessionHas('supervisor_initial_password');
        $initialPassword = Crypt::decryptString(session('supervisor_initial_password'));
        $user = User::where('email', $email)->firstOrFail();

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('login'), [
            'email' => $email,
            'password' => $initialPassword,
        ])->assertRedirect(route('password.change'));
        $this->assertAuthenticatedAs($user);
        $this->get(route('supervisor.dashboard'))->assertRedirect(route('password.change'));
        $this->get(route('password.change'))->assertOk();

        $newPassword = Str::password(24);
        $this->post(route('password.update'), [
            'current_password' => $initialPassword,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertRedirect(route('supervisor.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check($newPassword, $user->fresh()->password));
        $this->assertFalse(Hash::check($initialPassword, $user->fresh()->password));

        foreach (SystemAudit::where('user_id', $user->id)->get() as $audit) {
            $this->assertFalse(str_contains(json_encode($audit->getAttributes()), $initialPassword));
        }

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('login'), [
            'email' => $email,
            'password' => $initialPassword,
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post(route('login'), [
            'email' => $email,
            'password' => $newPassword,
        ])->assertRedirect(route('supervisor.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_existing_email_owned_by_another_lecturer_cannot_be_assigned_supervisor_role(): void
    {
        $owner = Lecturer::create([
            'name' => 'Pemilik Akun',
            'external_code' => 'TEST_EMAIL_OWNER_001',
            'is_active' => true,
        ]);
        $target = Lecturer::create([
            'name' => 'Calon Supervisor Lain',
            'external_code' => 'TEST_EMAIL_TARGET_001',
            'is_active' => true,
        ]);
        $ownerUser = User::create([
            'lecturer_id' => $owner->id,
            'name' => $owner->name,
            'email' => 'test_email_target_001@teknokrat.ac.id',
            'password' => Hash::make(Str::password(24)),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->post(route('lpm.supervisors.store'), [
            'lecturer_id' => $target->id,
            'email' => $ownerUser->email,
            'supervision_period_id' => $this->period->id,
        ])->assertSessionHasErrors('email');

        $this->assertSame($owner->id, $ownerUser->fresh()->lecturer_id);
        $this->assertFalse($ownerUser->hasRole('supervisor'));
        $this->assertDatabaseMissing('users', ['lecturer_id' => $target->id]);
        $this->assertDatabaseMissing('period_supervisors', [
            'supervision_period_id' => $this->period->id,
            'lecturer_id' => $target->id,
        ]);

        $this->assertSame(
            'Email ini sudah digunakan akun lain. Gunakan alamat email berbeda.',
            session('errors')->first('email')
        );

        $this->from(route('lpm.supervisors.index'))
            ->followingRedirects()
            ->post(route('lpm.supervisors.store'), [
                'lecturer_id' => $target->id,
                'email' => $ownerUser->email,
                'supervision_period_id' => $this->period->id,
            ])->assertOk()->assertSee('Email ini sudah digunakan akun lain.');

        $this->post(route('lpm.supervisors.store'), [
            'lecturer_id' => $target->id,
            'supervision_period_id' => $this->period->id,
        ])->assertSessionHasErrors('email');
        $this->assertSame($owner->id, $ownerUser->fresh()->lecturer_id);
        $this->assertFalse($ownerUser->hasRole('supervisor'));
    }

    public function test_existing_lecturer_account_keeps_its_password_and_status_when_assigned(): void
    {
        $lecturer = Lecturer::create([
            'name' => 'Supervisor dengan Akun Lama',
            'external_code' => 'TEST_EXISTING_SUP_001',
            'is_active' => true,
        ]);
        $existingPassword = Str::password(24);
        $user = User::create([
            'lecturer_id' => $lecturer->id,
            'name' => $lecturer->name,
            'email' => 'supervisor.akun.lama@teknokrat.ac.id',
            'password' => Hash::make($existingPassword),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $existingHash = $user->password;
        $user->assignRole('supervisor');

        $this->actingAs($this->admin)->post(route('lpm.supervisors.store'), [
            'lecturer_id' => $lecturer->id,
            'email' => $user->email,
            'supervision_period_id' => $this->period->id,
        ])->assertRedirect(route('lpm.supervisors.index', ['period_id' => $this->period->id]));

        $this->assertSame($existingHash, $user->fresh()->password);
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue($user->hasRole('supervisor'));
        $this->assertDatabaseHas('period_supervisors', [
            'supervision_period_id' => $this->period->id,
            'lecturer_id' => $lecturer->id,
            'is_active' => true,
        ]);
        $this->assertNull(session('supervisor_initial_password'));

        $this->post(route('lpm.supervisors.store'), [
            'lecturer_id' => $lecturer->id,
            'email' => 'alamat.berbeda@teknokrat.ac.id',
            'supervision_period_id' => $this->period->id,
        ])->assertSessionHasErrors('email');
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_manually_supplied_initial_password_is_rejected(): void
    {
        $lecturer = Lecturer::create([
            'name' => 'Calon Supervisor tanpa Password Manual',
            'external_code' => 'TEST_NO_MANUAL_PASS_001',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->post(route('lpm.supervisors.store'), [
            'lecturer_id' => $lecturer->id,
            'email' => 'tanpa.password.manual@teknokrat.ac.id',
            'password' => Str::password(24),
            'supervision_period_id' => $this->period->id,
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['lecturer_id' => $lecturer->id]);
        $this->assertDatabaseMissing('period_supervisors', [
            'supervision_period_id' => $this->period->id,
            'lecturer_id' => $lecturer->id,
        ]);
    }

    public function test_superadmin_can_remove_supervisor_from_selected_period_without_erasing_history(): void
    {
        $faculty = Faculty::first() ?? Faculty::create(['name' => 'Fakultas Teknik', 'code' => 'FT']);
        $prodi = StudyProgram::first() ?? StudyProgram::create(['name' => 'Teknik Komputer', 'code' => 'TK', 'faculty_id' => $faculty->id]);

        $lecturer = Lecturer::create([
            'name' => 'Supervisor Dicabut, M.Kom.',
            'external_code' => 'TEST_REV_001',
            'faculty_id' => $faculty->id,
            'study_program_id' => $prodi->id,
            'is_active' => true,
        ]);

        $user = User::create([
            'name' => $lecturer->name,
            'email' => 'dicabut.supervisor@teknokrat.ac.id',
            'password' => bcrypt('Password123!'),
            'lecturer_id' => $lecturer->id,
            'is_active' => true,
        ]);
        $user->assignRole('supervisor');
        $period = $this->period;
        PeriodSupervisor::create([
            'supervision_period_id' => $period->id,
            'lecturer_id' => $lecturer->id,
            'selected_by' => $this->admin->id,
            'is_active' => true,
        ]);
        $previousPeriod = SupervisionPeriod::create([
            'academic_term_id' => $period->academic_term_id,
            'name' => 'Riwayat Supervisor Uji',
            'starts_on' => $period->starts_on->copy()->subYear(),
            'ends_on' => $period->ends_on->copy()->subYear(),
            'status' => 'CLOSED',
            'created_by' => $this->admin->id,
        ]);
        PeriodSupervisor::create([
            'supervision_period_id' => $previousPeriod->id,
            'lecturer_id' => $lecturer->id,
            'selected_by' => $this->admin->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->delete(route('lpm.supervisors.destroy', [
            'supervisor' => $lecturer,
            'period_id' => $period->id,
        ]));

        $response->assertRedirect(route('lpm.supervisors.index', ['period_id' => $period->id]));
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertTrue($user->hasRole('supervisor'));
        $this->assertDatabaseHas('period_supervisors', [
            'supervision_period_id' => $period->id,
            'lecturer_id' => $lecturer->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('period_supervisors', [
            'supervision_period_id' => $previousPeriod->id,
            'lecturer_id' => $lecturer->id,
            'is_active' => true,
        ]);
    }

    public function test_supervisor_directory_lists_only_assignments_from_selected_period(): void
    {
        $firstPeriod = $this->period;
        $secondPeriod = SupervisionPeriod::create([
            'academic_term_id' => $firstPeriod->academic_term_id,
            'name' => 'Periode Uji Berikutnya',
            'starts_on' => $firstPeriod->starts_on->copy()->addYear(),
            'ends_on' => $firstPeriod->ends_on->copy()->addYear(),
            'status' => 'ACTIVE',
            'created_by' => $this->admin->id,
        ]);
        $lecturer = Lecturer::create([
            'name' => 'Supervisor Khusus Periode Pertama',
            'external_code' => 'DSN-SUP-PERIOD-SCOPE',
            'is_active' => true,
        ]);
        $this->supervisorUser->update(['lecturer_id' => $lecturer->id]);
        PeriodSupervisor::create([
            'supervision_period_id' => $firstPeriod->id,
            'lecturer_id' => $lecturer->id,
            'selected_by' => $this->admin->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->get(route('lpm.supervisors.index', ['period_id' => $firstPeriod->id]))
            ->assertOk()
            ->assertViewHas('supervisors', fn ($supervisors) => $supervisors->contains('id', $lecturer->id));

        $this->actingAs($this->admin)->get(route('lpm.supervisors.index', ['period_id' => $secondPeriod->id]))
            ->assertOk()
            ->assertViewHas('supervisors', fn ($supervisors) => ! $supervisors->contains('id', $lecturer->id))
            ->assertViewMissing('availableLecturers');
        $this->getJson(route('lpm.supervisors.candidates', $secondPeriod))->assertOk()->assertJsonFragment(['id' => $lecturer->id]);
        $this->getJson(route('lpm.supervisors.candidates', $firstPeriod))->assertOk()->assertJsonMissing(['id' => $lecturer->id]);

        $this->get(route('lpm.supervisors.index'))
            ->assertOk()
            ->assertViewHas('selectedPeriod', fn ($period) => $period->id === $secondPeriod->id)
            ->assertViewHas('supervisors', fn ($supervisors) => $supervisors->isEmpty());

        $this->post(route('lpm.supervisors.store'), [
            'lecturer_id' => $lecturer->id,
            'supervision_period_id' => $secondPeriod->id,
        ])->assertRedirect(route('lpm.supervisors.index', ['period_id' => $secondPeriod->id]));

        foreach ([$firstPeriod, $secondPeriod] as $period) {
            $this->assertDatabaseHas('period_supervisors', [
                'supervision_period_id' => $period->id,
                'lecturer_id' => $lecturer->id,
                'is_active' => true,
            ]);
        }
    }
}
