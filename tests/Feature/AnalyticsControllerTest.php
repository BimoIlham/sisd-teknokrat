<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\SupervisionPeriod;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create([
            'name' => 'LPM Administrator',
            'email' => 'admin@teknokrat.ac.id',
        ]);
        $this->admin->assignRole('superadmin');
    }

    public function test_superadmin_can_access_analytics_when_no_periods_exist(): void
    {
        $response = $this->actingAs($this->admin)->get(route('lpm.analytics.index'));

        $response->assertStatus(200);
        $response->assertSee('Perbandingan Hasil Supervisi');
        $response->assertSee('Belum ada periode supervisi yang siap dianalisis');
        $response->assertViewHas('progress', fn ($progress) => $progress['total_required'] === 0);
    }

    public function test_superadmin_can_access_analytics_with_active_period(): void
    {
        $term = AcademicTerm::create([
            'academic_year' => '2026/2027',
            'semester' => 'GANJIL',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-01-31',
            'is_active' => true,
        ]);

        $period = SupervisionPeriod::create([
            'academic_term_id' => $term->id,
            'name' => 'Supervisi Semester Ganjil 2026/2027',
            'starts_on' => '2026-09-10',
            'ends_on' => '2026-12-20',
            'status' => 'ACTIVE',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('lpm.analytics.index', ['period_id' => $period->id]));

        $response->assertStatus(200);
        $response->assertSee('Semester Ganjil');
        $response->assertSee($period->semester_display);
        $response->assertDontSee('Tahun Akademik');
        $response->assertSee('Belum ada penilaian yang disubmit');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('lpm.analytics.index'));

        $response->assertRedirect(route('login'));
    }
}
