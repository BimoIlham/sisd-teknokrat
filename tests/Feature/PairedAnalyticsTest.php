<?php

namespace Tests\Feature;

use App\Models\FormTemplate;
use App\Models\PeriodSupervisor;
use App\Models\PeriodTarget;
use App\Models\ScoringPolicy;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Services\Analytics\SupervisionAnalyticsService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PairedAnalyticsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(DemoAcademicSeeder::class);
    }

    public function test_invariant_38_progress_denominator(): void
    {
        $analytics = app(SupervisionAnalyticsService::class);
        $period = SupervisionPeriod::first();

        $includedTargets = PeriodTarget::where('supervision_period_id', $period->id)->where('scope_status', 'INCLUDED')->count();
        $stagesCount = SupervisionStage::where('supervision_period_id', $period->id)->count();

        $expectedTotal = $includedTargets * $stagesCount;

        $progress = $analytics->calculateProgress($period);

        $this->assertEquals($expectedTotal, $progress['total_required']);
        $this->assertEquals($includedTargets, $progress['included_targets_count']);
        $this->assertEquals($stagesCount, $progress['stages_count']);
    }

    public function test_invariant_36_and_37_paired_cohort_and_comparability(): void
    {
        $analytics = app(SupervisionAnalyticsService::class);
        $period = SupervisionPeriod::with('stages')->first();

        $t1Stage = $period->stages->firstWhere('stage_order', 1);
        $t2Stage = $period->stages->firstWhere('stage_order', 2);
        $offlineTemplate = FormTemplate::where('delivery_mode', 'OFFLINE')->first();
        $onlineTemplate = FormTemplate::where('delivery_mode', 'ONLINE')->first();
        $scoringPolicy = ScoringPolicy::first();

        $occurrences = TeachingOccurrence::with('teachingSchedule')
            ->get()
            ->unique(fn (TeachingOccurrence $occurrence) => $occurrence->teachingSchedule->lecturer_id)
            ->values();
        $occT1 = $occurrences->first();
        $occT1Lec7 = $occurrences->get(1);
        $targetA = $occT1->teachingSchedule->lecturer_id;
        $targetB = $occT1Lec7->teachingSchedule->lecturer_id;
        $supervisorIds = PeriodSupervisor::query()
            ->where('supervision_period_id', $period->id)
            ->pluck('lecturer_id')
            ->take(2);

        // Create T1 assignment & submitted supervision
        $assignT1 = SupervisionAssignment::create([
            'supervision_stage_id' => $t1Stage->id,
            'target_lecturer_id' => $targetA,
            'supervisor_lecturer_id' => $supervisorIds->first(),
            'teaching_occurrence_id' => $occT1->id,
            'assignment_type' => 'MANUAL',
            'status' => 'FINAL',
        ]);

        Supervision::create([
            'supervision_assignment_id' => $assignT1->id,
            'form_template_id' => $offlineTemplate->id,
            'scoring_policy_id' => $scoringPolicy->id,
            'status' => 'SUBMITTED',
            'row_version' => 1,
            'final_score' => 3.00,
        ]);

        // Case A: Target 6 has matching T2 (also OFFLINE) -> Comparable!
        $assignT2 = SupervisionAssignment::create([
            'supervision_stage_id' => $t2Stage->id,
            'target_lecturer_id' => $targetA,
            'supervisor_lecturer_id' => $supervisorIds->last(),
            'teaching_occurrence_id' => $occT1->id,
            'assignment_type' => 'MANUAL',
            'status' => 'FINAL',
        ]);

        Supervision::create([
            'supervision_assignment_id' => $assignT2->id,
            'form_template_id' => $offlineTemplate->id,
            'scoring_policy_id' => $scoringPolicy->id,
            'status' => 'SUBMITTED',
            'row_version' => 1,
            'final_score' => 3.50, // Delta is +0.50
        ]);

        $assignB1 = SupervisionAssignment::create([
            'supervision_stage_id' => $t1Stage->id,
            'target_lecturer_id' => $targetB,
            'supervisor_lecturer_id' => $supervisorIds->first(),
            'teaching_occurrence_id' => $occT1Lec7->id,
            'assignment_type' => 'MANUAL',
            'status' => 'FINAL',
        ]);

        Supervision::create([
            'supervision_assignment_id' => $assignB1->id,
            'form_template_id' => $offlineTemplate->id,
            'scoring_policy_id' => $scoringPolicy->id,
            'status' => 'SUBMITTED',
            'row_version' => 1,
            'final_score' => 3.20,
        ]);

        $assignB2 = SupervisionAssignment::create([
            'supervision_stage_id' => $t2Stage->id,
            'target_lecturer_id' => $targetB,
            'supervisor_lecturer_id' => $supervisorIds->last(),
            'teaching_occurrence_id' => $occT1Lec7->id,
            'assignment_type' => 'MANUAL',
            'status' => 'FINAL',
        ]);

        Supervision::create([
            'supervision_assignment_id' => $assignB2->id,
            'form_template_id' => $onlineTemplate->id,
            'scoring_policy_id' => $scoringPolicy->id,
            'status' => 'SUBMITTED',
            'row_version' => 1,
            'final_score' => 3.80,
        ]);

        $comparison = $analytics->computePairedComparison($period);

        // The first target must be in comparable_pairs
        $this->assertEquals(1, $comparison['comparable_count']);
        $this->assertEquals(0.50, $comparison['average_delta']);
        $this->assertEquals($targetA, $comparison['comparable_pairs'][0]['target_lecturer_id']);
        $this->assertEquals(0.50, $comparison['comparable_pairs'][0]['delta']);

        // The second target must be in incomparable_pairs
        $this->assertTrue(collect($comparison['incomparable_pairs'])->contains(fn ($pair) => str_contains($pair['reason'], 'Metode/Instrumen berbeda')));
    }
}
