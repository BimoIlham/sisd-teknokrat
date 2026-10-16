<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\SupervisionPeriod;
use App\Models\User;
use Database\Seeders\CompleteDemoDataSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecutiveReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create([
            'name' => 'LPM Administrator',
            'email' => 'admin@teknokrat.ac.id',
        ]);
        $this->admin->assignRole('superadmin');

        $this->supervisor = User::factory()->create([
            'name' => 'Supervisor User',
            'email' => 'supervisor@teknokrat.ac.id',
        ]);
        $this->supervisor->assignRole('supervisor');

        AcademicTerm::create([
            'academic_year' => '2026/2027',
            'semester' => 'GANJIL',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-01-31',
            'is_active' => true,
        ]);
    }

    public function test_superadmin_can_access_supervision_executive_report_page(): void
    {
        $response = $this->actingAs($this->admin)->get(route('lpm.reports.supervision'));

        $response->assertStatus(200);
        $response->assertSee('Laporan Hasil Supervisi Pembelajaran');
        $response->assertSee('Meningkatkan Kualitas Pembelajaran, Mewujudkan UTI Disiplin, Bermutu, Kreatif, Inovatif');
        $response->assertSee('Semester Supervisi');
        $response->assertSee('Tahap 1 dan Tahap 2');
        $response->assertSee('Belum ada semester supervisi');
        $response->assertSee('Belum ada hasil supervisi');
        $response->assertDontSee('Tahun Ajaran');
        $response->assertDontSee('Ganjil dan Genap');
        $response->assertDontSee('Dr. Ryan Randy Suryono, M.Kom');
    }

    public function test_superadmin_can_filter_executive_report_by_semester_and_stage(): void
    {
        $this->seed(CompleteDemoDataSeeder::class);
        $period = SupervisionPeriod::active()->firstOrFail();
        $response = $this->actingAs($this->admin)->get(route('lpm.reports.supervision', [
            'period_id' => $period->id,
            'stage' => '1',
        ]));

        $response->assertStatus(200);
        $response->assertViewHas('selectedPeriod', fn (SupervisionPeriod $selectedPeriod): bool => $selectedPeriod->id === $period->id);
        $response->assertViewHas('selectedStage', '1');
        $response->assertViewHas('reportScope', 'stage');
        $response->assertSee('10 Dosen Terbaik Tahap 1');
        $response->assertDontSee('Tahun Akademik');
        $response->assertViewHas('summary', fn (array $summary): bool => $summary['count_t2'] === 0 && $summary['avg_t1'] > 0);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('lpm.reports.supervision'));

        $response->assertRedirect(route('login'));
    }

    public function test_report_uses_submitted_supervision_data_instead_of_static_benchmark(): void
    {
        $this->seed(CompleteDemoDataSeeder::class);

        $response = $this->actingAs($this->admin)->get(route('lpm.reports.supervision'));

        $response->assertOk();
        $response->assertSee('Aditya Saputra');
        $response->assertSee('Temuan Umum dalam Supervisi');
        $response->assertSee('Belum ada hasil yang dikirim');
        $response->assertSee('Belum tersedia');
        $response->assertDontSee('Dr. Ryan Randy Suryono, M.Kom');
        $response->assertViewHas('summary', fn (array $summary) => $summary['total_supervised'] === 26
            && $summary['count_t2'] === 0
            && $summary['avg_t1'] > 0
            && $summary['avg_t2'] === null
            && $summary['delta_avg'] === null);
        $response->assertViewHas('hasStageTwoData', false);
        $response->assertViewHas('commonFindings', fn ($findings) => collect($findings)->count() > 0);
    }
}
