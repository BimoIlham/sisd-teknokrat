<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuperadminAccessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sisd_teknokrat_test', DB::connection()->getDatabaseName());
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_lpm_keeps_old_routes_but_cannot_access_permanent_deletion_or_account_management(): void
    {
        $lpm = User::factory()->create(['must_change_password' => false]);
        $lpm->assignRole('lpm_admin');
        $this->actingAs($lpm)->get(route('lpm.lecturers.index'))->assertOk();
        $this->actingAs($lpm)->get(route('superadmin.deletions.index'))->assertForbidden();
        $this->actingAs($lpm)->get(route('superadmin.users.index'))->assertForbidden();
        $this->actingAs($lpm)->delete(route('superadmin.deletions.destroy', ['type' => 'lecturer', 'id' => 1]))->assertForbidden();
        $this->actingAs($lpm)->get(route('superadmin.deletions.bulk.preview', ['type' => 'lecturer']))->assertForbidden();
        $this->actingAs($lpm)->delete(route('superadmin.deletions.bulk.destroy', ['type' => 'lecturer']))->assertForbidden();
    }

    public function test_supervisor_cannot_access_superadmin_routes(): void
    {
        $supervisor = User::factory()->create(['must_change_password' => false]);
        $supervisor->assignRole('supervisor');
        $this->actingAs($supervisor)->get(route('superadmin.deletions.index'))->assertForbidden();
        $this->actingAs($supervisor)->get(route('superadmin.users.index'))->assertForbidden();
        $this->actingAs($supervisor)->get(route('superadmin.deletions.bulk.preview', ['type' => 'supervisor']))->assertForbidden();
        $this->actingAs($supervisor)->delete(route('superadmin.deletions.bulk.destroy', ['type' => 'supervisor']))->assertForbidden();
    }

    public function test_legacy_superadmin_role_has_no_permanent_delete_access_before_bootstrap(): void
    {
        $legacyLpm = User::factory()->create(['must_change_password' => false]);
        $legacyLpm->assignRole('superadmin');
        $this->actingAs($legacyLpm)->get(route('lpm.lecturers.index'))->assertOk();
        $this->actingAs($legacyLpm)->get(route('superadmin.deletions.index'))->assertForbidden();
    }

    public function test_bootstrap_migrates_old_lpm_and_requires_new_superadmin_to_change_password(): void
    {
        $oldLpm = User::factory()->create();
        $oldLpm->assignRole('superadmin');
        $email = 'root-test-'.uniqid().'@example.test';
        $this->artisan('app:bootstrap-superadmin', ['--email' => $email, '--name' => 'Root Test'])->assertSuccessful();

        $this->assertTrue($oldLpm->fresh()->hasRole('lpm_admin'));
        $this->assertFalse($oldLpm->fresh()->hasRole('superadmin'));
        $newAdmin = User::where('email', $email)->firstOrFail();
        $this->assertTrue($newAdmin->hasRole('superadmin'));
        $this->assertTrue($newAdmin->must_change_password);
        $this->actingAs($newAdmin)->get(route('superadmin.deletions.index'))->assertRedirect(route('password.change'));
        $newAdmin->update(['must_change_password' => false]);
        $this->actingAs($newAdmin)->get(route('superadmin.deletions.index'))->assertOk();
        $this->artisan('app:bootstrap-superadmin', ['--email' => 'second@example.test'])->assertFailed();
    }

    public function test_superadmin_can_create_and_disable_lpm_account(): void
    {
        $admin = User::factory()->create(['must_change_password' => false, 'is_root_superadmin' => true]);
        $admin->assignRole('superadmin');
        $email = 'lpm-test-'.uniqid().'@example.test';
        $this->actingAs($admin)->post(route('superadmin.users.store'), ['name' => 'LPM Test', 'email' => $email])->assertRedirect();
        $lpm = User::where('email', $email)->firstOrFail();
        $this->assertTrue($lpm->hasRole('lpm_admin'));
        $this->assertTrue($lpm->must_change_password);
        $this->actingAs($admin)->put(route('superadmin.users.update', $lpm), ['name' => 'LPM Test', 'email' => $email, 'is_active' => 0])->assertRedirect();
        $this->assertFalse($lpm->fresh()->is_active);

        $this->actingAs($admin)->post(route('superadmin.users.reset_password', $lpm))->assertRedirect();
        $this->assertTrue($lpm->fresh()->must_change_password);
    }
}
