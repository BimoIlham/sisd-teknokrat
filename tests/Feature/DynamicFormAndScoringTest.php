<?php

namespace Tests\Feature;

use App\Models\FormTemplate;
use App\Models\Question;
use App\Models\ScoringPolicy;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\User;
use App\Services\Supervision\DraftService;
use App\Services\Supervision\EvidenceService;
use App\Services\Supervision\ScoringService;
use App\Services\Supervision\SubmitService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class DynamicFormAndScoringTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (User::count() === 0 || FormTemplate::count() === 0) {
            $this->seed(DatabaseSeeder::class);
        }

        if (SupervisionStage::count() === 0 || TeachingOccurrence::count() === 0) {
            $this->seed(UserSeeder::class);
            $this->seed(DemoAcademicSeeder::class);
        }

        SupervisionStage::query()->update([
            'status' => 'FINALIZED',
            'starts_on' => now('Asia/Jakarta')->subDay()->toDateString(),
            'ends_on' => now('Asia/Jakarta')->addDay()->toDateString(),
        ]);
    }

    public function test_draft_autosave_updates_row_version(): void
    {
        $draftService = app(DraftService::class);
        $stage = SupervisionStage::first();
        $supervisor = User::whereNotNull('lecturer_id')->first();
        $this->actingAs($supervisor);
        $occurrence = TeachingOccurrence::with('schedule')
            ->whereHas('schedule', fn ($query) => $query->where('lecturer_id', '<>', $supervisor->lecturer_id))->firstOrFail();
        $question = Question::with('options')
            ->where('form_template_id', FormTemplate::first()->id)
            ->whereHas('options')
            ->first();

        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id,
            'target_lecturer_id' => $occurrence->schedule->lecturer_id,
            'supervisor_lecturer_id' => $supervisor->lecturer_id,
            'teaching_occurrence_id' => $occurrence->id,
            'assignment_type' => 'MANUAL',
            'status' => 'FINAL',
        ]);

        $supervision = Supervision::create([
            'supervision_assignment_id' => $assignment->id,
            'form_template_id' => $question->form_template_id,
            'scoring_policy_id' => ScoringPolicy::first()->id,
            'status' => 'DRAFT',
            'row_version' => 1,
        ]);

        $result = $draftService->saveDraft($supervision, [
            $question->id => $question->options->first()->id,
        ], 1);

        $this->assertEquals(2, $result['row_version']);
        $this->assertEquals(2, $supervision->fresh()->row_version);
    }

    public function test_draft_autosave_rejects_stale_row_version_with_409(): void
    {
        $this->expectException(ConflictHttpException::class);

        $draftService = app(DraftService::class);
        $stage = SupervisionStage::first();
        $supervisor = User::whereNotNull('lecturer_id')->first();
        $this->actingAs($supervisor);
        $occurrence = TeachingOccurrence::with('schedule')
            ->whereHas('schedule', fn ($query) => $query->where('lecturer_id', '<>', $supervisor->lecturer_id))->firstOrFail();
        $question = Question::with('options')
            ->where('form_template_id', FormTemplate::first()->id)
            ->whereHas('options')
            ->first();

        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id,
            'target_lecturer_id' => $occurrence->schedule->lecturer_id,
            'supervisor_lecturer_id' => $supervisor->lecturer_id,
            'teaching_occurrence_id' => $occurrence->id,
            'assignment_type' => 'MANUAL',
            'status' => 'FINAL',
        ]);

        $supervision = Supervision::create([
            'supervision_assignment_id' => $assignment->id,
            'form_template_id' => $question->form_template_id,
            'scoring_policy_id' => ScoringPolicy::first()->id,
            'status' => 'DRAFT',
            'row_version' => 5, // Server is at version 5
        ]);

        // Client sends version 4
        $draftService->saveDraft($supervision, [$question->id => $question->options->first()->id], 4);
    }

    public function test_scoring_service_formulas(): void
    {
        $scoringService = app(ScoringService::class);

        // Test MEAN_0_4
        $resMean = $scoringService->applyFormula(3.5, 'MEAN_0_4', 10.5, 3);
        $this->assertEquals(3.5, $resMean);

        // Test LINEAR_1_4: 1.0 + (totalPoints / (N * 4)) * 3.0
        // e.g. total 12 out of 12 (max) -> 1.0 + 3.0 = 4.0
        $resLinearMax = $scoringService->applyFormula(4.0, 'LINEAR_1_4', 12.0, 3);
        $this->assertEquals(4.0, $resLinearMax);

        // e.g. total 0 out of 12 (min) -> 1.0 + 0.0 = 1.0
        $resLinearMin = $scoringService->applyFormula(0.0, 'LINEAR_1_4', 0.0, 3);
        $this->assertEquals(1.0, $resLinearMin);

        // Test FLOOR_1_4: max(1.0, rawAverage)
        $resFloorLow = $scoringService->applyFormula(0.5, 'FLOOR_1_4', 1.0, 2);
        $this->assertEquals(1.0, $resFloorLow);

        $resFloorHigh = $scoringService->applyFormula(3.2, 'FLOOR_1_4', 6.4, 2);
        $this->assertEquals(3.2, $resFloorHigh);
    }

    public function test_invariant_26_score_zero_and_false_are_valid_answers(): void
    {
        $scoringService = app(ScoringService::class);
        $policyMean = ScoringPolicy::where('strategy', 'MEAN_0_4')->first();

        // Find question 1 and its options
        $q1 = Question::with('options')->where('is_scored', true)->first();
        $zeroOption = $q1->options->firstWhere('numeric_score', 0.0);

        if ($zeroOption) {
            $result = $scoringService->calculate([
                $q1->id => $zeroOption->id,
            ], $policyMean);

            $this->assertEquals(1, $result['n_items']);
            $this->assertEquals(0.0, $result['raw_score']);
            $this->assertEquals(0.0, $result['final_score']);
        }
    }

    public function test_submit_service_freezes_snapshot_and_makes_form_immutable(): void
    {
        Storage::fake('local');
        $submitService = app(SubmitService::class);
        $draftService = app(DraftService::class);
        $stage = SupervisionStage::first();
        $user = User::where('email', 'supervisor1@teknokrat.ac.id')->first();
        $this->actingAs($user);
        $occurrence = TeachingOccurrence::with('schedule')
            ->whereHas('schedule', fn ($query) => $query->where('lecturer_id', '<>', $user->lecturer_id))->firstOrFail();

        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id,
            'target_lecturer_id' => $occurrence->schedule->lecturer_id,
            'supervisor_lecturer_id' => $user->lecturer_id,
            'teaching_occurrence_id' => $occurrence->id,
            'assignment_type' => 'MANUAL',
            'status' => 'FINAL',
        ]);

        $template = FormTemplate::with('sections.questions.options')->first();
        $supervision = Supervision::create([
            'supervision_assignment_id' => $assignment->id,
            'form_template_id' => $template->id,
            'scoring_policy_id' => ScoringPolicy::first()->id,
            'status' => 'DRAFT',
            'row_version' => 1,
        ]);

        // Answer all questions
        $answers = [];
        foreach ($template->sections->flatMap->questions as $q) {
            if ($q->question_type === 'SCORE' || $q->question_type === 'YES_NO') {
                $answers[$q->id] = $q->options->last()->id;
            } elseif ($q->question_type === 'LINK') {
                $answers[$q->id] = 'https://lms.teknokrat.ac.id/course/view.php?id=101';
            } elseif ($q->question_type === 'TEXTAREA') {
                $answers[$q->id] = 'Evaluasi perkuliahan sangat baik dan interaktif.';
            } elseif ($q->question_type === 'FILE') {
                app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->image('evidence_photo.jpg'), $q->id);
            }
        }

        $draftService->saveDraft($supervision, $answers, $supervision->fresh()->row_version);

        // Submit form
        $preview = $submitService->preview($supervision);
        $submitted = $submitService->submit($supervision->fresh(), $user, $preview['row_version']);

        $this->assertEquals('SUBMITTED', $submitted->status);
        $this->assertNotNull($submitted->submitted_at);
        $this->assertNotNull($submitted->snapshot_json);
        $this->assertGreaterThan(0, $submitted->final_score);

        // Submitted forms are rejected by the shared mutation authorization rule.
        $this->expectException(AuthorizationException::class);
        $draftService->saveDraft($submitted->fresh(), [$template->sections->first()->questions->first()->id => 1], $submitted->fresh()->row_version);
    }
}
