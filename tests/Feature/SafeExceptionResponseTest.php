<?php

namespace Tests\Feature;

use App\Models\ScheduleRun;
use App\Models\StageInstrument;
use App\Models\Supervision;
use App\Models\SupervisionAnswer;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionEvidence;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\User;
use App\Services\ScheduleImport\ScheduleImportService;
use App\Services\Scheduling\ApplyRunService;
use App\Services\Scheduling\StageFinalizationService;
use App\Services\Supervision\DraftService;
use App\Services\Supervision\EvidenceService;
use App\Services\Supervision\SubmitService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class SafeExceptionResponseTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(DemoAcademicSeeder::class);
        Storage::fake('local');
    }

    #[TestWith(['draft'])]
    #[TestWith(['review_draft'])]
    #[TestWith(['review'])]
    #[TestWith(['upload_evidence'])]
    #[TestWith(['delete_evidence'])]
    #[TestWith(['submit'])]
    public function test_supervisor_unexpected_errors_are_safe_and_have_a_logged_reference(string $operation): void
    {
        [$user, $supervision] = $this->createSupervision();
        $this->actingAs($user);
        $exception = new \RuntimeException('SQLSTATE[23000] internal_table C:\\laragon\\www\\sisd-teknokrat\\config\\app.php private-value');
        $service = match ($operation) {
            'draft', 'review_draft' => DraftService::class,
            'review', 'submit' => SubmitService::class,
            default => EvidenceService::class,
        };
        $method = match ($operation) {
            'draft', 'review_draft' => 'saveDraft', 'review' => 'preview',
            'upload_evidence' => 'storeEvidence', 'delete_evidence' => 'deleteEvidence', 'submit' => 'submit',
        };
        $this->mock($service)->shouldReceive($method)->once()->andThrow($exception);
        Log::spy();
        $url = route('supervisor.supervisions.'.$operation, $supervision);
        $data = ['answers' => [], 'row_version' => 1];
        if ($operation === 'delete_evidence') {
            $question = $supervision->formTemplate->questions()->where('question_type', 'FILE')->firstOrFail();
            $answer = SupervisionAnswer::create(['supervision_id' => $supervision->id, 'question_id' => $question->id]);
            $evidence = SupervisionEvidence::create(['supervision_answer_id' => $answer->id, 'uploaded_by' => $user->id, 'evidence_type' => 'FILE', 'storage_disk' => 'local', 'file_path' => 'test.pdf', 'original_filename' => 'test.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'status' => 'READY']);
            $response = $this->deleteJson(route('supervisor.supervisions.delete_evidence', $evidence))->assertStatus(500);
        } elseif ($operation === 'upload_evidence') {
            $data = ['file' => UploadedFile::fake()->image('bukti.jpg'), 'question_id' => $supervision->formTemplate->questions()->where('question_type', 'FILE')->value('id')];
            $response = $this->postJson($url, $data)->assertStatus(500);
        } elseif ($operation === 'review') {
            $response = $this->from('/supervisor/assignments')->get($url)->assertRedirect('/supervisor/assignments');
        } elseif ($operation === 'submit') {
            $response = $this->withSession(['supervision_reviews' => [$supervision->id => 1]])->post($url, ['review_confirmed' => '1', 'review_version' => 1])->assertRedirect();
        } elseif ($operation === 'draft') {
            $response = $this->postJson($url, $data)->assertStatus(500);
        } else {
            $response = $this->post($url, $data)->assertRedirect();
        }
        $isJson = str_contains((string) $response->headers->get('Content-Type'), 'application/json');
        $reference = $isJson ? $response->json('reference') : session('error_reference');
        $message = $isJson ? $response->json('message') : session('error');
        $this->assertSafeError($message, $reference, $exception->getMessage());
    }

    public function test_validation_and_version_conflicts_keep_useful_responses(): void
    {
        [$user, $supervision] = $this->createSupervision();
        $this->actingAs($user)->postJson(route('supervisor.supervisions.draft', $supervision), ['answers' => []])->assertUnprocessable()->assertJsonValidationErrors('row_version');
        $this->postJson(route('supervisor.supervisions.draft', $supervision), ['answers' => [], 'row_version' => 99])->assertStatus(409)->assertJsonPath('conflict', true)->assertJsonMissingPath('reference');
        $this->postJson(route('supervisor.supervisions.upload_evidence', $supervision), ['file' => UploadedFile::fake()->image('bukti.jpg'), 'question_id' => -1])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_workspace_unexpected_error_is_safe_and_conflicts_are_preserved(): void
    {
        $stage = SupervisionStage::firstOrFail();
        $admin = User::where('email', 'lpm@teknokrat.ac.id')->firstOrFail();
        $exception = new \RuntimeException('SQLSTATE internal_table C:/private/config.php');
        $this->mock(StageFinalizationService::class)->shouldReceive('finalize')->once()->andThrow($exception);
        Log::spy();
        $this->actingAs($admin)->post(route('lpm.assignments.finalize_stage', $stage))->assertRedirect();
        $this->assertSafeError(session('error'), session('error_reference'), $exception->getMessage());
        $run = ScheduleRun::create(['supervision_stage_id' => $stage->id, 'created_by_user_id' => $admin->id, 'status' => 'FEASIBLE', 'run_type' => 'AUTOMATIC']);
        $this->mock(ApplyRunService::class)->shouldReceive('apply')->once()->andThrow(new ConflictHttpException('Usulan sudah kedaluwarsa. Buat usulan terbaru.'));
        $this->post(route('lpm.assignments.apply_run', $run), ['revision' => 1])->assertSessionHas('error', 'Usulan sudah kedaluwarsa. Buat usulan terbaru.');
    }

    public function test_import_unexpected_error_is_safe(): void
    {
        $stage = SupervisionStage::firstOrFail();
        $exception = new \RuntimeException('SQLSTATE internal_table C:/private/config.php');
        $this->mock(ScheduleImportService::class)->shouldReceive('import')->once()->andThrow($exception);
        Log::spy();
        $this->actingAs(User::where('email', 'lpm@teknokrat.ac.id')->firstOrFail())->post(route('lpm.import.store'), [
            'academic_term_id' => $stage->period->academic_term_id, 'schedule_pdf' => UploadedFile::fake()->create('jadwal.pdf', 10, 'application/pdf'),
        ])->assertRedirect();
        $this->assertSafeError(session('error'), session('error_reference'), $exception->getMessage());
    }

    private function assertSafeError(string $message, string $reference, string $internalMessage): void
    {
        $this->assertTrue(Str::isUuid($reference));
        $this->assertStringContainsString($reference, $message);
        foreach (['SQLSTATE', 'internal_table', 'C:/private', 'C:\\laragon\\www', $internalMessage] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $message);
        }
        Log::shouldHaveReceived('error')->once()->with('Application operation failed', \Mockery::on(function (array $context) use ($reference): bool {
            $this->assertSame(['reference', 'operation', 'exception_class', 'source', 'user_id'], array_keys($context));
            $this->assertStringNotContainsString('private-value', json_encode($context));

            return $context['reference'] === $reference;
        }));
    }

    #[TestWith([403])]
    #[TestWith([404])]
    public function test_workspace_preserves_authorization_and_missing_object_statuses(int $status): void
    {
        $stage = SupervisionStage::firstOrFail();
        $exception = $status === 403 ? new AuthorizationException : new ModelNotFoundException;
        $this->mock(StageFinalizationService::class)->shouldReceive('finalize')->once()->andThrow($exception);
        Log::spy();
        $this->actingAs(User::where('email', 'lpm@teknokrat.ac.id')->firstOrFail())
            ->post(route('lpm.assignments.finalize_stage', $stage))->assertStatus($status);
        $this->assertNull(session('error_reference'));
        Log::shouldNotHaveReceived('error');
    }

    /** @return array{User, Supervision} */
    private function createSupervision(): array
    {
        $user = User::where('email', 'supervisor1@teknokrat.ac.id')->firstOrFail();
        $stage = SupervisionStage::firstOrFail();
        $stage->update(['status' => 'FINALIZED', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString()]);
        $occurrence = TeachingOccurrence::with('schedule')->whereHas('schedule', fn ($query) => $query->where('lecturer_id', '<>', $user->lecturer_id))->firstOrFail();
        $instrument = StageInstrument::where('supervision_stage_id', $stage->id)->where('delivery_mode', strtoupper($occurrence->schedule->delivery_mode))->firstOrFail();
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id, 'target_lecturer_id' => $occurrence->schedule->lecturer_id,
            'supervisor_lecturer_id' => $user->lecturer_id, 'teaching_occurrence_id' => $occurrence->id,
            'assignment_type' => 'MANUAL', 'status' => 'FINAL',
        ]);
        $supervision = Supervision::create([
            'supervision_assignment_id' => $assignment->id, 'stage_instrument_id' => $instrument->id,
            'form_template_id' => $instrument->form_template_id, 'scoring_policy_id' => $instrument->scoring_policy_id,
            'status' => 'DRAFT', 'row_version' => 1,
        ]);

        return [$user, $supervision];
    }
}
