<?php

namespace Tests\Feature;

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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class SupervisionEvidenceIntegrityTest extends TestCase
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

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_filename_payload_does_not_complete_a_file_question_or_delete_legacy_data(bool $legacy): void
    {
        [$user, $supervision] = $this->createSupervision();
        $this->saveCompleteAnswers($supervision, false);
        $question = $supervision->formTemplate->questions()->where('question_type', 'FILE')->firstOrFail();
        if ($legacy) {
            $supervision->answers()->updateOrCreate(['question_id' => $question->id], ['text_value' => 'legacy.pdf']);
        }
        $this->postJson(route('supervisor.supervisions.draft', $supervision), [
            'answers' => [$question->id => 'private/evidence/fake.pdf'],
            'row_version' => $supervision->fresh()->row_version,
        ])->assertOk();
        $this->assertSame($legacy ? 'legacy.pdf' : null, $supervision->answers()->where('question_id', $question->id)->firstOrFail()->text_value);

        $this->assertIncomplete($supervision, $user);
        $this->assertSame($legacy ? 'legacy.pdf' : null, $supervision->answers()->where('question_id', $question->id)->firstOrFail()->text_value);
    }

    #[TestWith(['missing_file'])]
    #[TestWith(['not_ready'])]
    #[TestWith(['no_path'])]
    #[TestWith(['no_disk'])]
    #[TestWith(['wrong_disk'])]
    #[TestWith(['other_answer'])]
    public function test_evidence_must_be_ready_stored_and_attached_to_the_correct_file_answer(string $invalid): void
    {
        [$user, $supervision] = $this->createSupervision();
        $this->saveCompleteAnswers($supervision);
        $evidence = $supervision->evidence()->with('answer')->firstOrFail();
        match ($invalid) {
            'missing_file' => Storage::disk('local')->delete($evidence->file_path),
            'not_ready' => $evidence->update(['status' => 'PENDING']),
            'no_path' => $evidence->update(['file_path' => null]),
            'no_disk' => $evidence->update(['storage_disk' => '']),
            'wrong_disk' => $evidence->update(['storage_disk' => 'public']),
            'other_answer' => $evidence->update(['supervision_answer_id' => $supervision->answers()->where('id', '<>', $evidence->supervision_answer_id)->firstOrFail()->id]),
        };

        $this->assertIncomplete($supervision, $user);
        $this->assertDatabaseHas('supervision_evidence', ['id' => $evidence->id]);
    }

    public function test_upload_and_delete_increment_versions_and_remove_the_empty_answer(): void
    {
        [, $supervision] = $this->createSupervision();
        $question = $supervision->formTemplate->questions()->where('question_type', 'FILE')->firstOrFail();
        $this->postJson(route('supervisor.supervisions.upload_evidence', $supervision), [
            'file' => UploadedFile::fake()->image('bukti.jpg'), 'question_id' => $question->id,
        ])->assertOk()->assertJsonPath('row_version', 2);
        $evidence = $supervision->evidence()->firstOrFail();
        $answerId = $evidence->supervision_answer_id;
        $this->get(route('supervisor.supervisions.download_evidence', $evidence))->assertDownload('bukti.jpg');
        $this->deleteJson(route('supervisor.supervisions.delete_evidence', $evidence))
            ->assertOk()->assertJsonPath('row_version', 3);
        $this->assertSame(3, $supervision->fresh()->row_version);
        $this->assertDatabaseMissing('supervision_answers', ['id' => $answerId]);
        $this->assertDatabaseMissing('supervision_evidence', ['id' => $evidence->id]);
        Storage::disk('local')->assertMissing($evidence->file_path);
    }

    #[TestWith(['upload'])]
    #[TestWith(['delete'])]
    public function test_evidence_change_invalidates_review_and_reviewing_again_allows_submission(string $operation): void
    {
        [, $supervision] = $this->createSupervision();
        $this->saveCompleteAnswers($supervision);
        $evidence = $supervision->evidence()->with('answer')->firstOrFail();
        if ($operation === 'delete') {
            app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->image('cadangan.jpg'), $evidence->answer->question_id);
        }
        $this->get(route('supervisor.supervisions.review', $supervision))->assertOk();
        $reviewVersion = $supervision->fresh()->row_version;
        if ($operation === 'upload') {
            app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->image('tambahan.jpg'), $evidence->answer->question_id);
        } else {
            app(EvidenceService::class)->deleteEvidence($evidence);
        }
        $this->assertSame($reviewVersion + 1, $supervision->fresh()->row_version);
        $this->post(route('supervisor.supervisions.submit', $supervision), [
            'review_confirmed' => '1', 'review_version' => $reviewVersion,
        ])->assertSessionHasErrors('review');
        $this->assertSame('DRAFT', $supervision->fresh()->status);
        $this->get(route('supervisor.supervisions.review', $supervision))->assertOk();
        $this->post(route('supervisor.supervisions.submit', $supervision), [
            'review_confirmed' => '1', 'review_version' => $supervision->fresh()->row_version,
        ])->assertRedirect(route('supervisor.supervisions.show', $supervision))->assertSessionHasNoErrors();
        $this->assertSame('SUBMITTED', $supervision->fresh()->status);
        $this->assertSame(4.0, (float) $supervision->fresh()->final_score);
    }

    public function test_service_rejects_an_old_review_version_even_with_a_stale_supervision_object(): void
    {
        [$user, $supervision] = $this->createSupervision();
        $this->saveCompleteAnswers($supervision);
        $preview = app(SubmitService::class)->preview($supervision);
        $evidence = $supervision->evidence()->with('answer')->firstOrFail();
        app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->image('tambahan.jpg'), $evidence->answer->question_id);
        try {
            app(SubmitService::class)->submit($supervision, $user, $preview['row_version']);
            $this->fail('Review lama harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review', $exception->errors());
        }
        $this->assertSame('DRAFT', $supervision->fresh()->status);
    }

    public function test_submit_reads_latest_answers_and_evidence_after_the_parent_lock(): void
    {
        [$user, $supervision] = $this->createSupervision();
        $this->saveCompleteAnswers($supervision);
        $supervision->load('answers.evidence');
        $oldEvidence = $supervision->evidence()->with('answer')->firstOrFail();
        $newEvidence = app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->image('terbaru.jpg'), $oldEvidence->answer->question_id);
        app(EvidenceService::class)->deleteEvidence($oldEvidence);
        DB::enableQueryLog();
        try {
            $submitted = app(SubmitService::class)->submit($supervision, $user, $supervision->fresh()->row_version);
            $queries = collect(DB::getQueryLog())->pluck('query')->values();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $lockIndex = $queries->search(fn (string $sql): bool => str_contains($sql, 'from `supervisions`') && str_contains($sql, 'for update'));
        $answerIndex = $queries->search(fn (string $sql): bool => str_contains($sql, 'from `supervision_answers`'));
        $this->assertIsInt($lockIndex);
        $this->assertIsInt($answerIndex);
        $this->assertGreaterThan($lockIndex, $answerIndex);
        $snapshotEvidence = collect($submitted->snapshot_json['evidence']);
        $this->assertTrue($snapshotEvidence->contains('id', $newEvidence->id));
        $this->assertFalse($snapshotEvidence->contains('id', $oldEvidence->id));
        $this->assertSame('terbaru.jpg', collect($submitted->snapshot_json['answers'])->firstWhere('question_id', $oldEvidence->answer->question_id)['text_value']);
    }

    public function test_stale_models_cannot_mutate_evidence_after_submission(): void
    {
        [$user, $supervision] = $this->createSupervision();
        $this->saveCompleteAnswers($supervision);
        $evidence = $supervision->evidence()->with('answer.supervision')->firstOrFail();
        app(SubmitService::class)->submit($supervision, $user, $supervision->fresh()->row_version);
        $version = $supervision->fresh()->row_version;
        foreach ([
            fn () => app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->image('ditolak.jpg'), $evidence->answer->question_id),
            fn () => app(EvidenceService::class)->deleteEvidence($evidence),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Supervisi terkirim tidak boleh berubah.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($version, $supervision->fresh()->row_version);
        Storage::disk('local')->assertExists($evidence->file_path);
    }

    public function test_upload_failure_rolls_back_answer_version_and_cleans_the_new_file(): void
    {
        [, $supervision] = $this->createSupervision();
        $question = $supervision->formTemplate->questions()->where('question_type', 'FILE')->firstOrFail();
        $this->failModelEvent('eloquent.creating: '.SupervisionEvidence::class, fn () => app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->image('gagal.jpg'), $question->id));
        $this->assertSame(1, $supervision->fresh()->row_version);
        $this->assertSame(0, $supervision->answers()->count());
        $this->assertSame(0, $supervision->evidence()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_delete_failure_rolls_back_database_records_and_keeps_the_physical_file(): void
    {
        [, $supervision] = $this->createSupervision();
        $question = $supervision->formTemplate->questions()->where('question_type', 'FILE')->firstOrFail();
        $evidence = app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->image('tetap.jpg'), $question->id);
        $this->failModelEvent('eloquent.updating: '.Supervision::class, fn () => app(EvidenceService::class)->deleteEvidence($evidence));
        $this->assertSame(2, $supervision->fresh()->row_version);
        $this->assertDatabaseHas('supervision_evidence', ['id' => $evidence->id]);
        $this->assertDatabaseHas('supervision_answers', ['id' => $evidence->supervision_answer_id]);
        Storage::disk('local')->assertExists($evidence->file_path);
    }

    public function test_upload_cleanup_also_runs_when_an_enclosing_transaction_rolls_back(): void
    {
        [, $supervision] = $this->createSupervision();
        $question = $supervision->formTemplate->questions()->where('question_type', 'FILE')->firstOrFail();
        DB::beginTransaction();
        try {
            $evidence = app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->image('rollback.jpg'), $question->id);
            Storage::disk('local')->assertExists($evidence->file_path);
        } finally {
            DB::rollBack();
        }
        Storage::disk('local')->assertMissing($evidence->file_path);
        $this->assertSame(1, $supervision->fresh()->row_version);
        $this->assertSame(0, $supervision->evidence()->count());
    }

    public function test_delete_waits_for_the_enclosing_commit_and_does_not_delete_on_rollback(): void
    {
        [, $supervision] = $this->createSupervision();
        $question = $supervision->formTemplate->questions()->where('question_type', 'FILE')->firstOrFail();
        $evidence = app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->image('tetap.jpg'), $question->id);
        DB::beginTransaction();
        try {
            app(EvidenceService::class)->deleteEvidence($evidence);
            Storage::disk('local')->assertExists($evidence->file_path);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseHas('supervision_evidence', ['id' => $evidence->id]);
        $this->assertSame(2, $supervision->fresh()->row_version);
        Storage::disk('local')->assertExists($evidence->file_path);
    }

    private function failModelEvent(string $event, callable $operation): void
    {
        $original = Model::getEventDispatcher();
        $dispatcher = clone $original;
        $dispatcher->listen($event, fn () => throw new RuntimeException('Simulasi kegagalan database.'));
        Model::setEventDispatcher($dispatcher);
        try {
            $operation();
            $this->fail('Operasi harus gagal pada simulasi rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulasi kegagalan database.', $exception->getMessage());
        } finally {
            Model::setEventDispatcher($original);
        }
    }

    private function assertIncomplete(Supervision $supervision, User $user): void
    {
        try {
            app(SubmitService::class)->submit($supervision, $user, $supervision->fresh()->row_version);
            $this->fail('FILE tanpa bukti nyata harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }
        $this->assertSame('DRAFT', $supervision->fresh()->status);
        $this->assertNull($supervision->fresh()->snapshot_json);
    }

    private function saveCompleteAnswers(Supervision $supervision, bool $uploadFiles = true): void
    {
        $answers = [];
        foreach ($supervision->formTemplate->questions()->with('options')->get() as $question) {
            if ($question->question_type === 'FILE') {
                if ($uploadFiles) {
                    app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->image('bukti.jpg'), $question->id);
                }
            } else {
                $answers[$question->id] = match ($question->question_type) {
                    'SCORE', 'YES_NO' => $question->options->last()->id,
                    'LINK' => 'https://lms.teknokrat.ac.id/course/101',
                    default => 'Pembelajaran berlangsung baik.',
                };
            }
        }
        app(DraftService::class)->saveDraft($supervision, $answers, $supervision->fresh()->row_version);
    }

    /** @return array{User, Supervision} */
    private function createSupervision(): array
    {
        $user = User::where('email', 'supervisor1@teknokrat.ac.id')->firstOrFail();
        $stage = SupervisionStage::firstOrFail();
        $stage->update(['status' => 'FINALIZED', 'starts_on' => now('Asia/Jakarta')->subDay()->toDateString(), 'ends_on' => now('Asia/Jakarta')->addDay()->toDateString()]);
        $occurrence = TeachingOccurrence::with('schedule')->whereHas('schedule', fn ($query) => $query->where('lecturer_id', '<>', $user->lecturer_id))->firstOrFail();
        $instrument = StageInstrument::where('supervision_stage_id', $stage->id)->firstOrFail();
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
        $this->actingAs($user);

        return [$user, $supervision];
    }
}
