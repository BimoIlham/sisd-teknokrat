<?php

namespace Tests\Feature;

use App\Models\PeriodSupervisor;
use App\Models\StageInstrument;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionEvidence;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\User;
use App\Services\Supervision\DraftService;
use App\Services\Supervision\EvidenceService;
use App\Services\Supervision\SubmitService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SupervisorWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(DemoAcademicSeeder::class);
    }

    public function test_supervisor_can_upload_download_and_delete_evidence_for_a_file_question(): void
    {
        Storage::fake('local');

        [$supervisor, $supervision] = $this->createSupervision();
        $fileQuestion = $supervision->formTemplate->questions()->where('question_type', 'FILE')->firstOrFail();

        $uploadResponse = $this->actingAs($supervisor)->post(
            route('supervisor.supervisions.upload_evidence', $supervision),
            [
                'file' => UploadedFile::fake()->image('bukti-kelas.jpg'),
                'question_id' => $fileQuestion->id,
            ],
            ['Accept' => 'application/json']
        );

        $uploadResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('evidence.question_id', $fileQuestion->id)
            ->assertJsonPath('evidence.file_name', 'bukti-kelas.jpg');

        $evidence = SupervisionEvidence::with('answer')->firstOrFail();
        $this->assertSame($supervision->id, $evidence->answer->supervision_id);
        $this->assertSame($fileQuestion->id, $evidence->answer->question_id);
        Storage::disk('local')->assertExists($evidence->file_path);

        $this->actingAs($supervisor)
            ->get(route('supervisor.supervisions.download_evidence', $evidence))
            ->assertDownload('bukti-kelas.jpg');

        $this->actingAs($supervisor)
            ->deleteJson(route('supervisor.supervisions.delete_evidence', $evidence))
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('local')->assertMissing($evidence->file_path);
        $this->assertDatabaseMissing('supervision_evidence', ['id' => $evidence->id]);
    }

    public function test_supervisor_form_renders_the_autosave_and_file_controls(): void
    {
        [$supervisor, $supervision] = $this->createSupervision();
        $fileQuestion = $supervision->formTemplate->questions()->where('question_type', 'FILE')->firstOrFail();

        $response = $this->actingAs($supervisor)->get(
            route('supervisor.supervisions.form', $supervision->assignment)
        );

        $response->assertOk()
            ->assertSee('Tersimpan')
            ->assertSee('Catatan untuk nilai butir ini (opsional)')
            ->assertSee('uploadEvidenceFile($event, '.$fileQuestion->id.')', false)
            ->assertSee('name="row_version"', false)
            ->assertDontSee('Versi Baris')
            ->assertDontSee($fileQuestion->code);
    }

    public function test_supervisor_workspace_pages_render_successfully(): void
    {
        $supervisor = User::where('email', 'supervisor1@teknokrat.ac.id')->firstOrFail();

        $this->actingAs($supervisor)->get(route('supervisor.dashboard'))->assertOk();
        $this->actingAs($supervisor)->get(route('supervisor.schedule'))->assertOk();
        $this->actingAs($supervisor)->get(route('supervisor.assignments.index'))->assertOk();
    }

    public function test_submit_persists_latest_form_answers_and_freezes_result(): void
    {
        Storage::fake('local');

        [$supervisor, $supervision] = $this->createSupervision();
        $template = $supervision->formTemplate()->with('sections.questions.options')->firstOrFail();
        $questionCode = $template->sections->flatMap->questions->firstOrFail()->code;
        $answers = [];

        foreach ($template->sections->flatMap->questions as $question) {
            if (in_array($question->question_type, ['SCORE', 'YES_NO'], true)) {
                $answers[$question->id] = $question->options->last()->id;
            } elseif ($question->question_type === 'LINK') {
                $answers[$question->id] = 'https://lms.teknokrat.ac.id/course/101';
            } elseif ($question->question_type === 'TEXTAREA') {
                $answers[$question->id] = 'Pembelajaran berjalan baik dan interaktif.';
            } elseif ($question->question_type === 'FILE') {
                $this->actingAs($supervisor)->post(
                    route('supervisor.supervisions.upload_evidence', $supervision),
                    [
                        'file' => UploadedFile::fake()->create('rps.pdf', 120, 'application/pdf'),
                        'question_id' => $question->id,
                    ],
                    ['Accept' => 'application/json']
                )->assertOk();
            }
        }

        $this->actingAs($supervisor)->post(
            route('supervisor.supervisions.review_draft', $supervision),
            [
                'answers' => $answers,
                'row_version' => $supervision->fresh()->row_version,
            ]
        )->assertRedirect(route('supervisor.supervisions.review', $supervision));

        $this->actingAs($supervisor)->get(route('supervisor.supervisions.review', $supervision))->assertOk();
        $response = $this->actingAs($supervisor)->post(route('supervisor.supervisions.submit', $supervision), [
            'review_confirmed' => '1',
            'review_version' => $supervision->fresh()->row_version,
        ]);

        $response->assertRedirect(route('supervisor.supervisions.show', $supervision));

        $submitted = $supervision->fresh();
        $this->assertSame('SUBMITTED', $submitted->status);
        $this->assertSame($supervisor->id, $submitted->submitted_by);
        $this->assertNotNull($submitted->submission_snapshot);
        $this->assertGreaterThan(0, $submitted->scored_question_count);
        $this->assertSame(count($answers), $submitted->answers()->whereIn('question_id', array_keys($answers))->count());

        $this->actingAs($supervisor)
            ->get(route('supervisor.supervisions.show', $submitted))
            ->assertOk()
            ->assertDontSee('Cetak PDF Nilai')
            ->assertSee('rps.pdf')
            ->assertDontSee($questionCode);

        $this->actingAs($supervisor)
            ->get(route('supervisor.supervisions.pdf', $submitted))
            ->assertForbidden();
    }

    #[TestWith(['revoked'])]
    #[TestWith(['missing_membership'])]
    #[TestWith(['other_period'])]
    #[TestWith(['other_owner'])]
    #[TestWith(['self'])]
    #[TestWith(['no_role'])]
    #[TestWith(['no_lecturer'])]
    #[TestWith(['draft'])]
    #[TestWith(['cancelled'])]
    #[TestWith(['stage_draft'])]
    #[TestWith(['period_closed'])]
    #[TestWith(['not_started'])]
    #[TestWith(['ended'])]
    public function test_every_input_endpoint_rejects_ineligible_assignments(string $restriction): void
    {
        Storage::fake('local');
        [$supervisor, $supervision] = $this->createSupervision();
        $this->actingAs($supervisor);
        $question = $supervision->formTemplate->questions()->where('question_type', 'FILE')->firstOrFail();
        $evidence = app(EvidenceService::class)->storeEvidence(
            $supervision, UploadedFile::fake()->image('bukti.jpg'), $question->id
        );
        $evidenceVersion = $supervision->fresh()->row_version;
        $this->restrictInput($restriction, $supervisor, $supervision);

        $this->get(route('supervisor.supervisions.form', $supervision->assignment))->assertForbidden();
        $this->postJson(route('supervisor.supervisions.draft', $supervision), [
            'answers' => [], 'row_version' => 1,
        ])->assertForbidden();
        $this->post(route('supervisor.supervisions.review_draft', $supervision), [
            'answers' => [], 'row_version' => 1,
        ])->assertForbidden();
        $this->get(route('supervisor.supervisions.review', $supervision))->assertForbidden();
        $this->postJson(route('supervisor.supervisions.upload_evidence', $supervision), [
            'question_id' => $question->id,
            'file' => UploadedFile::fake()->image('ditolak.jpg'),
        ])->assertForbidden();
        $this->deleteJson(route('supervisor.supervisions.delete_evidence', $evidence))->assertForbidden();
        $this->withSession(['supervision_reviews' => [$supervision->id => 1]])
            ->post(route('supervisor.supervisions.submit', $supervision), [
                'review_confirmed' => '1', 'review_version' => 1,
            ])->assertForbidden();

        $this->assertSame('DRAFT', $supervision->fresh()->status);
        $this->assertSame($evidenceVersion, $supervision->fresh()->row_version);
        $this->assertSame(1, $supervision->answers()->count());
        $this->assertSame(1, $supervision->evidence()->count());
        Storage::disk('local')->assertExists($evidence->file_path);
    }

    #[TestWith(['revoked'])]
    #[TestWith(['other_owner'])]
    #[TestWith(['draft'])]
    #[TestWith(['stage_draft'])]
    #[TestWith(['inactive'])]
    #[TestWith(['no_role'])]
    public function test_services_recheck_access_even_when_called_with_stale_models(string $restriction): void
    {
        Storage::fake('local');
        [$supervisor, $supervision] = $this->createSupervision();
        $this->actingAs($supervisor);
        $question = $supervision->formTemplate->questions()->where('question_type', 'FILE')->firstOrFail();
        $evidence = app(EvidenceService::class)->storeEvidence(
            $supervision, UploadedFile::fake()->image('bukti.jpg'), $question->id
        );
        $evidenceVersion = $supervision->fresh()->row_version;
        $supervision->load('assignment.supervisionStage.period');
        $evidence->load('answer.supervision.assignment.supervisionStage.period');
        $this->assertTrue(Gate::allows('update', $supervision));
        $this->restrictInput($restriction, $supervisor, $supervision);

        $operations = [
            fn () => app(DraftService::class)->saveDraft($supervision, [], 1),
            fn () => app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->image('ditolak.jpg'), $question->id),
            fn () => app(EvidenceService::class)->deleteEvidence($evidence),
            fn () => app(SubmitService::class)->preview($supervision),
            fn () => app(SubmitService::class)->submit($supervision, $supervisor, 1),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('Mutasi harus ditolak setelah akses dicabut atau status berubah.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($evidenceVersion, $supervision->fresh()->row_version);
        $this->assertSame('DRAFT', $supervision->fresh()->status);
        $this->assertSame(1, $supervision->evidence()->count());
        Storage::disk('local')->assertExists($evidence->file_path);
    }

    public function test_service_authorization_failure_is_returned_as_403_after_initial_policy_passes(): void
    {
        [$supervisor, $supervision] = $this->createSupervision();
        $service = app(DraftService::class);
        $this->mock(DraftService::class, function ($mock) use ($service, $supervisor, $supervision): void {
            $mock->shouldReceive('saveDraft')->once()->andReturnUsing(function (...$arguments) use ($service, $supervisor, $supervision) {
                $this->restrictInput('revoked', $supervisor, $supervision);

                return $service->saveDraft(...$arguments);
            });
        });

        $this->actingAs($supervisor)->postJson(route('supervisor.supervisions.draft', $supervision), [
            'answers' => [], 'row_version' => 1,
        ])->assertForbidden();
        $this->assertSame(1, $supervision->fresh()->row_version);
    }

    public function test_revoked_supervisor_can_still_read_owned_history_and_admin_read_access_is_preserved(): void
    {
        Storage::fake('local');
        [$supervisor, $supervision] = $this->createSupervision();
        $this->actingAs($supervisor);
        $question = $supervision->formTemplate->questions()->where('question_type', 'FILE')->firstOrFail();
        $evidence = app(EvidenceService::class)->storeEvidence(
            $supervision, UploadedFile::fake()->image('arsip.jpg'), $question->id
        );
        $supervision->update(['status' => 'SUBMITTED', 'final_score' => 3, 'submitted_at' => now()]);
        $this->restrictInput('other_period', $supervisor, $supervision);

        $this->get(route('supervisor.supervisions.show', $supervision))->assertOk();
        $this->get(route('supervisor.supervisions.download_evidence', $evidence))->assertDownload('arsip.jpg');
        $this->assertTrue($supervisor->fresh()->hasRole('supervisor'));

        $admin = User::where('email', 'lpm@teknokrat.ac.id')->firstOrFail();
        $this->assertTrue(Gate::forUser($admin)->allows('view', $supervision));
        $this->assertTrue(Gate::forUser($admin)->allows('downloadPdf', $supervision));
        $this->assertTrue(Gate::forUser($admin)->allows('download', $evidence));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $supervision->assignment));
    }

    private function restrictInput(string $restriction, User $supervisor, Supervision $supervision): void
    {
        $assignment = $supervision->assignment;
        $stage = $assignment->supervisionStage;
        $membership = PeriodSupervisor::where('supervision_period_id', $stage->supervision_period_id)
            ->where('lecturer_id', $supervisor->lecturer_id);

        switch ($restriction) {
            case 'revoked':
                $membership->update(['is_active' => false]);
                break;
            case 'missing_membership':
                $membership->delete();
                break;
            case 'other_period':
                $membership->update(['is_active' => false]);
                $otherPeriod = $stage->period->replicate();
                $otherPeriod->name = 'Periode Membership Lain';
                $otherPeriod->save();
                PeriodSupervisor::create([
                    'supervision_period_id' => $otherPeriod->id,
                    'lecturer_id' => $supervisor->lecturer_id,
                    'is_active' => true,
                    'selected_by' => $stage->period->created_by,
                ]);
                break;
            case 'other_owner':
                SupervisionAssignment::whereKey($assignment->id)->update([
                    'supervisor_lecturer_id' => User::where('email', 'supervisor2@teknokrat.ac.id')->firstOrFail()->lecturer_id,
                ]);
                break;
            case 'self':
                SupervisionAssignment::whereKey($assignment->id)->update(['target_lecturer_id' => $supervisor->lecturer_id]);
                break;
            case 'no_role':
                $supervisor->removeRole('supervisor');
                break;
            case 'no_lecturer':
                $supervisor->update(['lecturer_id' => null]);
                break;
            case 'inactive':
                User::whereKey($supervisor->id)->update(['is_active' => false]);
                break;
            case 'draft':
            case 'cancelled':
                SupervisionAssignment::whereKey($assignment->id)->update(['status' => strtoupper($restriction)]);
                break;
            case 'stage_draft':
                SupervisionStage::whereKey($stage->id)->update(['status' => 'DRAFT']);
                break;
            case 'period_closed':
                $stage->period->update(['status' => 'CLOSED']);
                break;
            case 'not_started':
                $stage->update(['starts_on' => now('Asia/Jakarta')->addDay(), 'ends_on' => now('Asia/Jakarta')->addDays(2)]);
                break;
            case 'ended':
                $stage->update(['starts_on' => now('Asia/Jakarta')->subDays(2), 'ends_on' => now('Asia/Jakarta')->subDay()]);
                break;
        }
    }

    /**
     * @return array{User, Supervision}
     */
    private function createSupervision(): array
    {
        $supervisor = User::where('email', 'supervisor1@teknokrat.ac.id')->firstOrFail();
        $stage = SupervisionStage::firstOrFail();
        $stage->update([
            'status' => 'FINALIZED',
            'starts_on' => now('Asia/Jakarta')->subDay()->toDateString(),
            'ends_on' => now('Asia/Jakarta')->addDay()->toDateString(),
        ]);
        $occurrence = TeachingOccurrence::with('schedule')
            ->whereHas('schedule', fn ($query) => $query->where('lecturer_id', '<>', $supervisor->lecturer_id))
            ->firstOrFail();
        $stageInstrument = StageInstrument::where('supervision_stage_id', $stage->id)
            ->where('delivery_mode', strtoupper($occurrence->schedule->delivery_mode))
            ->first()
            ?? StageInstrument::where('supervision_stage_id', $stage->id)->firstOrFail();

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
            'stage_instrument_id' => $stageInstrument->id,
            'form_template_id' => $stageInstrument->form_template_id,
            'scoring_policy_id' => $stageInstrument->scoring_policy_id,
            'status' => 'DRAFT',
            'row_version' => 1,
        ]);

        return [$supervisor, $supervision];
    }
}
