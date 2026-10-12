<?php

namespace Tests\Feature;

use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Models\TeachingOccurrence;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SemesterSupervisionTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['GANJIL', '2026-09-01', '2027-01-31'])]
    #[TestWith(['GENAP', '2027-02-01', '2027-07-31'])]
    public function test_each_semester_creates_two_supervision_stages(string $semester, string $startsOn, string $endsOn): void
    {
        $admin = $this->createAdmin();
        $stageOneEndsOn = $semester === 'GANJIL' ? '2026-10-31' : '2027-04-30';
        $stageTwoStartsOn = $semester === 'GANJIL' ? '2026-11-01' : '2027-05-01';

        $response = $this->actingAs($admin)->post(route('lpm.periods.store'), [
            'academic_year' => '2026/2027',
            'semester' => $semester,
            'stage_1_starts_on' => $startsOn,
            'stage_1_ends_on' => $stageOneEndsOn,
            'stage_2_starts_on' => $stageTwoStartsOn,
            'stage_2_ends_on' => $endsOn,
            'status' => 'ACTIVE',
        ]);

        $response->assertRedirect(route('lpm.periods.index'))->assertSessionHasNoErrors();
        $period = SupervisionPeriod::with('academicTerm', 'stages.instruments')->firstOrFail();
        $this->assertSame($semester, $period->academicTerm->semester);
        $this->assertSame('Supervisi Semester '.ucfirst(strtolower($semester)), $period->name);
        $this->assertSame($startsOn, $period->starts_on->toDateString());
        $this->assertSame($endsOn, $period->ends_on->toDateString());
        $this->assertSame(['Tahap 1', 'Tahap 2'], $period->stages->sortBy('stage_no')->pluck('name')->all());
        $this->assertSame([$startsOn, $stageOneEndsOn], [$period->stages->firstWhere('stage_no', 1)->starts_on->toDateString(), $period->stages->firstWhere('stage_no', 1)->ends_on->toDateString()]);
        $this->assertSame([$stageTwoStartsOn, $endsOn], [$period->stages->firstWhere('stage_no', 2)->starts_on->toDateString(), $period->stages->firstWhere('stage_no', 2)->ends_on->toDateString()]);
        foreach ($period->stages as $stage) {
            $this->assertSame(2, $stage->instruments->count());
        }
    }

    #[TestWith(['GANJIL'])]
    #[TestWith(['GENAP'])]
    public function test_workspace_allows_both_stages_in_the_active_semester(string $semester): void
    {
        $admin = $this->createAdmin();
        $period = $this->createPeriod($admin, $semester);
        $secondStage = $period->stages()->where('stage_no', 2)->firstOrFail();

        $response = $this->actingAs($admin)->get(route('lpm.assignments.index', ['stage_id' => $secondStage->id]));

        $response->assertOk()->assertSee('Tahap 1')->assertSee('Tahap 2');
        $response->assertViewHas('stage', fn ($stage) => $stage->id === $secondStage->id);
        $response->assertViewHas('stagesSummary', fn ($stages) => $stages->pluck('stage_no')->all() === [1, 2]);
    }

    public function test_analytics_is_available_with_only_one_semester_and_keeps_periods_separate(): void
    {
        $admin = $this->createAdmin();
        $oddPeriod = $this->createPeriod($admin, 'GANJIL');

        $this->actingAs($admin)->get(route('lpm.analytics.index'))
            ->assertOk()
            ->assertViewHas('period', fn ($period) => $period->id === $oddPeriod->id)
            ->assertViewHas('analytics', fn ($analytics) => $analytics['has_two_stages'] === true);

        $evenPeriod = $this->createPeriod($admin, 'GENAP');
        $this->actingAs($admin)->get(route('lpm.analytics.index', ['period_id' => $oddPeriod->id]))
            ->assertOk()
            ->assertViewHas('period', fn ($period) => $period->id === $oddPeriod->id)
            ->assertViewHas('periods', fn ($periods) => $periods->contains('id', $evenPeriod->id));
    }

    public function test_reports_and_directory_count_both_stages_without_pairing_across_semesters(): void
    {
        $admin = $this->createAdmin();
        $this->seed([UserSeeder::class, DemoAcademicSeeder::class]);
        $oddPeriod = SupervisionPeriod::active()->firstOrFail();
        $evenPeriod = $this->createPeriod($admin, 'GENAP');
        $occurrence = TeachingOccurrence::with('teachingSchedule')->firstOrFail();
        $supervisor = User::where('email', 'supervisor1@teknokrat.ac.id')->firstOrFail();

        foreach ([[$oddPeriod, 1, 2.0], [$oddPeriod, 2, 4.0], [$evenPeriod, 2, 3.0]] as [$period, $stageNumber, $score]) {
            $stage = $period->stages()->where('stage_no', $stageNumber)->firstOrFail();
            $instrument = $stage->instruments()->firstOrFail();
            $assignment = SupervisionAssignment::create([
                'supervision_stage_id' => $stage->id,
                'target_lecturer_id' => $occurrence->teachingSchedule->lecturer_id,
                'supervisor_lecturer_id' => $supervisor->lecturer_id,
                'teaching_occurrence_id' => $occurrence->id,
                'assignment_type' => 'MANUAL',
                'status' => 'FINAL',
            ]);
            Supervision::create([
                'supervision_assignment_id' => $assignment->id,
                'stage_instrument_id' => $instrument->id,
                'form_template_id' => $instrument->form_template_id,
                'scoring_policy_id' => $instrument->scoring_policy_id,
                'status' => 'SUBMITTED',
                'final_score' => $score,
            ]);
        }

        $this->actingAs($admin)->get(route('lpm.reports.supervision', ['period_id' => $oddPeriod->id]))
            ->assertOk()
            ->assertViewHas('summary', fn ($summary) => $summary['submitted_count'] === 2 && $summary['count_t2'] === 1 && $summary['delta_avg'] === 2.0);

        $this->actingAs($admin)->get(route('lpm.reports.supervision', ['period_id' => $oddPeriod->id]))
            ->assertOk()
            ->assertViewHas('topImprovement', fn ($rows) => count($rows) === 1 && $rows[0]['t1'] === 2.0 && $rows[0]['t2'] === 4.0);

        $lecturerId = $occurrence->teachingSchedule->lecturer_id;
        $this->actingAs($admin)->get(route('lpm.dashboard'))
            ->assertOk()
            ->assertViewHas('completedCount', 1)
            ->assertViewHas('t1Count', 0)
            ->assertViewHas('t2Count', 1);

        $this->actingAs($admin)->get(route('lpm.lecturers.index', ['period_id' => $evenPeriod->id]))
            ->assertOk()
            ->assertViewHas('lecturers', fn ($lecturers) => $lecturers->isEmpty());

        $this->actingAs($admin)->get(route('lpm.lecturers.index', ['period_id' => $oddPeriod->id]))
            ->assertOk()
            ->assertViewHas('lecturers', fn ($lecturers) => $lecturers->contains('id', $lecturerId));

        $this->actingAs($admin)->get(route('lpm.lecturers.show', ['lecturer' => $lecturerId, 'period_id' => $evenPeriod->id]))
            ->assertOk()
            ->assertViewHas('t1Assignments', fn ($assignments) => $assignments->isEmpty())
            ->assertViewHas('t2Assignments', fn ($assignments) => $assignments->count() === 1);

        $this->actingAs($admin)->get(route('lpm.reports.supervision', ['period_id' => $oddPeriod->id, 'stage' => '1']))
            ->assertOk()
            ->assertViewHas('selectedStage', '1')
            ->assertViewHas('summary', fn ($summary) => $summary['submitted_count'] === 1 && $summary['average_score'] === 2.0 && $summary['count_t2'] === 0);
        $this->actingAs($admin)->get(route('lpm.reports.supervision', ['period_id' => $oddPeriod->id, 'stage' => '2']))
            ->assertOk()
            ->assertViewHas('selectedStage', '2')
            ->assertViewHas('summary', fn ($summary) => $summary['submitted_count'] === 1 && $summary['average_score'] === 4.0 && $summary['avg_t1'] === null);
        $this->travelTo($oddPeriod->stages->firstWhere('stage_no', 2)->starts_on->copy()->timezone('Asia/Jakarta'));
        $previousOddStatus = $oddPeriod->refresh()->status;
        $evenPeriod->update(['status' => 'CLOSED']);
        $oddPeriod->update(['status' => 'ACTIVE']);
        $this->withSession(['password_hash_web' => null])->actingAs($supervisor)->get(route('supervisor.assignments.index', ['period_id' => $oddPeriod->id, 'stage' => '2']))
            ->assertOk()
            ->assertViewHas('assignments', fn ($assignments) => $assignments->count() === 1 && $assignments->first()->supervisionStage->stage_no === 2);
        $oddPeriod->update(['status' => $previousOddStatus]);
        $evenPeriod->update(['status' => 'ACTIVE']);

        $this->withSession(['password_hash_web' => null]);
        $anotherOddPeriod = $this->createPeriod($admin, 'GANJIL');
        $this->actingAs($admin)->get(route('lpm.reports.supervision', ['period_id' => $anotherOddPeriod->id]))
            ->assertOk()
            ->assertSee('Belum ada hasil supervisi')
            ->assertViewHas('summary', fn ($summary) => $summary['submitted_count'] === 0);
        $this->actingAs($admin)->get(route('lpm.lecturers.index', ['period_id' => $anotherOddPeriod->id]))
            ->assertOk()
            ->assertViewHas('lecturers', fn ($lecturers) => $lecturers->isEmpty());
        $this->actingAs($admin)->get(route('lpm.lecturers.stage_pdf', ['lecturer' => $lecturerId, 'stageNo' => 2, 'period_id' => $anotherOddPeriod->id]))
            ->assertRedirect()->assertSessionHas('error');
    }

    public function test_a_missing_semester_selection_returns_404_instead_of_using_another_semester(): void
    {
        $admin = $this->createAdmin();
        $this->createPeriod($admin, 'GANJIL');

        $this->actingAs($admin)->get(route('lpm.reports.supervision', ['period_id' => 999999]))->assertNotFound();
        $this->actingAs($admin)->get(route('lpm.lecturers.index', ['period_id' => 999999]))->assertNotFound();
    }

    private function createAdmin(): User
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        return $admin;
    }

    private function createPeriod(User $admin, string $semester): SupervisionPeriod
    {
        $this->actingAs($admin)->post(route('lpm.periods.store'), [
            'academic_year' => '2026/2027',
            'semester' => $semester,
            'stage_1_starts_on' => $semester === 'GANJIL' ? '2026-09-01' : '2027-02-01',
            'stage_1_ends_on' => $semester === 'GANJIL' ? '2026-10-31' : '2027-04-30',
            'stage_2_starts_on' => $semester === 'GANJIL' ? '2026-11-01' : '2027-05-01',
            'stage_2_ends_on' => $semester === 'GANJIL' ? '2027-01-31' : '2027-07-31',
            'status' => 'ACTIVE',
        ])->assertSessionHasNoErrors();

        return SupervisionPeriod::active()->with('stages')->firstOrFail();
    }
}
