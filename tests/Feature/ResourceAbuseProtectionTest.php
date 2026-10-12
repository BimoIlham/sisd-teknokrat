<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Services\Supervision\EvidenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ResourceAbuseProtectionTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    private Supervision $supervision;

    private Question $fileQuestion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
        $this->stage->update(['status' => 'FINALIZED']);
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'status' => 'FINAL', 'assignment_type' => 'MANUAL',
        ]);
        $this->supervision = Supervision::create([
            'supervision_assignment_id' => $assignment->id, 'stage_instrument_id' => $this->instrument->id,
            'form_template_id' => $this->instrument->form_template_id, 'scoring_policy_id' => $this->instrument->scoring_policy_id,
            'status' => 'DRAFT', 'row_version' => 1,
        ]);
        $this->fileQuestion = $this->question('FILE', 'FILE-1');
        $this->actingAs($this->supervisorUser);
        Storage::fake('local');
    }

    private function question(string $type, string $code): Question
    {
        return Question::create([
            'form_template_id' => $this->instrument->form_template_id,
            'form_section_id' => $this->instrument->formTemplate->sections()->firstOrFail()->id,
            'code' => $code, 'question_text' => 'Pengamatan pengujian', 'question_type' => $type,
            'is_required' => false, 'is_scored' => false, 'sort_order' => $this->instrument->formTemplate->questions()->max('sort_order') + 1,
        ]);
    }

    private function upload(?Question $question = null, int $kilobytes = 1): TestResponse
    {
        return $this->postJson(route('supervisor.supervisions.upload_evidence', $this->supervision), [
            'question_id' => ($question ?? $this->fileQuestion)->id,
            'file' => UploadedFile::fake()->image('bukti.jpg')->size($kilobytes),
        ]);
    }

    public function test_normal_uploads_are_saved_and_the_fourth_file_for_a_question_is_rejected(): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->upload()->assertOk();
        }
        $before = Storage::disk('local')->allFiles();
        $version = $this->supervision->fresh()->row_version;
        $this->upload()->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame($before, Storage::disk('local')->allFiles());
        $this->assertSame(3, $this->supervision->evidence()->count());
        $this->assertSame($version, $this->supervision->fresh()->row_version);
    }

    public function test_oversized_file_is_rejected_without_files_or_version_changes(): void
    {
        $this->upload(kilobytes: 5121)->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, $this->supervision->evidence()->count());
        $this->assertSame(1, $this->supervision->fresh()->row_version);
    }

    public function test_total_byte_quota_includes_stored_evidence_and_preserves_existing_files(): void
    {
        foreach ([$this->fileQuestion, $this->question('FILE', 'FILE-2'), $this->question('FILE', 'FILE-3'), $this->question('FILE', 'FILE-4')] as $question) {
            $this->upload($question, 5120)->assertOk();
        }
        $before = Storage::disk('local')->allFiles();
        $version = $this->supervision->fresh()->row_version;
        $this->upload($this->question('FILE', 'FILE-5'))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame($before, Storage::disk('local')->allFiles());
        $this->assertSame(4, $this->supervision->evidence()->count());
        $this->assertSame($version, $this->supervision->fresh()->row_version);
    }

    public function test_quota_counts_legacy_evidence_even_on_other_question_types(): void
    {
        $score = $this->supervision->formTemplate->questions()->where('question_type', 'SCORE')->firstOrFail();
        $answer = $this->supervision->answers()->create(['question_id' => $score->id]);
        for ($number = 1; $number <= 3; $number++) {
            Storage::disk('local')->put('private/legacy-'.$number.'.jpg', 'stored evidence');
            $answer->evidence()->create(['uploaded_by' => $this->supervisorUser->id, 'evidence_type' => 'IMAGE',
                'storage_disk' => 'local', 'file_path' => 'private/legacy-'.$number.'.jpg', 'original_filename' => 'legacy.jpg',
                'size_bytes' => 15, 'status' => 'READY']);
        }
        $this->upload()->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame(['private/legacy-1.jpg', 'private/legacy-2.jpg', 'private/legacy-3.jpg'], Storage::disk('local')->allFiles());
        $this->assertSame(1, $this->supervision->fresh()->row_version);
    }

    public function test_upload_throttle_returns_429_with_retry_information(): void
    {
        $url = route('supervisor.supervisions.upload_evidence', $this->supervision);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson($url, [])->assertUnprocessable();
        }
        $this->postJson($url, [])->assertStatus(429)->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Terlalu banyak permintaan. Tunggu sebentar sebelum mencoba kembali.');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[TestWith(['TEXTAREA', 'oversized'])]
    #[TestWith(['LINK', 'oversized'])]
    #[TestWith(['LINK', 'javascript'])]
    #[TestWith(['LINK', 'file'])]
    #[TestWith(['LINK', 'ftp'])]
    #[TestWith(['TEXTAREA', 'nested'])]
    public function test_draft_rejects_excessive_text_and_invalid_link_protocols(string $type, string $invalid): void
    {
        $question = $this->question($type, 'VALIDATION');
        $value = match ($invalid) {
            'oversized' => str_repeat('a', $type === 'LINK' ? 2049 : 5001),
            'nested' => ['nested' => ['value']],
            default => $invalid.':example',
        };
        $this->postJson(route('supervisor.supervisions.draft', $this->supervision), [
            'answers' => [$question->id => $value], 'row_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('answers.'.$question->id);
        $this->assertSame(1, $this->supervision->fresh()->row_version);
        $this->assertSame(0, $this->supervision->answers()->count());
    }

    public function test_draft_rejects_arrays_larger_than_the_instrument_and_long_notes(): void
    {
        $this->postJson(route('supervisor.supervisions.draft', $this->supervision), [
            'answers' => range(1, 20), 'row_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('answers');
        $this->postJson(route('supervisor.supervisions.draft', $this->supervision), [
            'answers' => [], 'notes' => [$this->fileQuestion->id => str_repeat('a', 5001)], 'row_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('notes.'.$this->fileQuestion->id);
    }

    public function test_valid_link_and_text_at_the_limits_remain_usable(): void
    {
        $text = $this->question('TEXTAREA', 'TEXT');
        $link = $this->question('LINK', 'LINK');
        $this->postJson(route('supervisor.supervisions.draft', $this->supervision), [
            'answers' => [$text->id => str_repeat('a', 5000), $link->id => 'https://example.test/'.str_repeat('a', 2027)],
            'notes' => [$text->id => str_repeat('n', 5000)], 'row_version' => 1,
        ])->assertOk();
    }

    public function test_review_draft_uses_the_same_payload_limits(): void
    {
        $question = $this->question('TEXTAREA', 'REVIEW');
        $this->postJson(route('supervisor.supervisions.review_draft', $this->supervision), [
            'answers' => [$question->id => str_repeat('a', 5001)], 'row_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('answers.'.$question->id);
    }

    public function test_quota_is_enforced_for_direct_service_calls(): void
    {
        $this->fileQuestion->update(['validation_rules' => ['max_files' => 1]]);
        app(EvidenceService::class)->storeEvidence($this->supervision, UploadedFile::fake()->image('valid.jpg'), $this->fileQuestion->id);
        $this->expectException(ValidationException::class);
        app(EvidenceService::class)->storeEvidence($this->supervision, UploadedFile::fake()->image('second.jpg'), $this->fileQuestion->id);
    }
}
