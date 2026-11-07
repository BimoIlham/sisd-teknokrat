<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Js;
use Tests\TestCase;

class EvidenceUploadResponseTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
        Storage::fake('local');
    }

    public function test_a_real_two_megabyte_png_can_be_uploaded_and_deleted(): void
    {
        [$supervision, $question] = $this->createSupervision();
        $png = UploadedFile::fake()->image('bukti.png');
        $pngBytes = file_get_contents($png->getRealPath());
        $payload = UploadedFile::fake()->createWithContent('bukti.png', $pngBytes.str_repeat("\0", 2 * 1024 * 1024 - strlen($pngBytes)));
        $file = new UploadedFile($payload->getRealPath(), 'Bukti.PNG', 'image/png', UPLOAD_ERR_OK, true);
        $this->assertSame('image/png', $file->getMimeType());
        $this->assertSame(2 * 1024 * 1024, $file->getSize());

        $response = $this->actingAs($this->supervisorUser)->postJson(route('supervisor.supervisions.upload_evidence', $supervision), [
            'file' => $file, 'question_id' => $question->id,
        ]);
        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('evidence.file_name', 'Bukti.PNG')
            ->assertJsonPath('evidence.file_size', 2 * 1024 * 1024);
        $evidence = $supervision->evidence()->firstOrFail();
        Storage::disk('local')->assertExists($evidence->file_path);
        $this->assertSame('image/png', $evidence->mime_type);

        $this->deleteJson(route('supervisor.supervisions.delete_evidence', $evidence))
            ->assertOk()->assertJsonPath('success', true);
        Storage::disk('local')->assertMissing($evidence->file_path);
        $this->assertSame(0, $supervision->evidence()->count());
    }

    public function test_a_php_upload_failure_returns_a_readable_json_validation_error(): void
    {
        [$supervision, $question] = $this->createSupervision();
        $png = UploadedFile::fake()->image('bukti.png');
        $file = new UploadedFile($png->getRealPath(), 'bukti.png', 'image/png', UPLOAD_ERR_INI_SIZE, true);
        $response = $this->actingAs($this->supervisorUser)->postJson(route('supervisor.supervisions.upload_evidence', $supervision), [
            'file' => $file, 'question_id' => $question->id,
        ]);
        $response->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertStringContainsString('Berkas gagal diterima server.', $response->json('errors.file.0'));
        $this->assertSame(0, $supervision->evidence()->count());
        $this->assertSame(1, $supervision->fresh()->row_version);
    }

    public function test_oversized_files_keep_the_five_megabyte_application_limit(): void
    {
        [$supervision, $question] = $this->createSupervision();
        $this->actingAs($this->supervisorUser)->postJson(route('supervisor.supervisions.upload_evidence', $supervision), [
            'file' => UploadedFile::fake()->image('bukti.png')->size(5121), 'question_id' => $question->id,
        ])->assertUnprocessable()->assertJsonPath('errors.file.0', 'Ukuran berkas maksimal 5 MB.');
        $this->assertSame(0, $supervision->evidence()->count());
    }

    public function test_delete_url_keeps_the_public_subdirectory_from_the_application_root(): void
    {
        [$supervision] = $this->createSupervision();
        URL::forceRootUrl('https://example.test/public');
        URL::forceScheme('https');

        try {
            $this->actingAs($this->supervisorUser)
                ->get('https://example.test/supervisor/assignments/'.$supervision->supervision_assignment_id.'/form')
                ->assertOk()
                ->assertSee('deleteEvidenceUrlTemplate: '.Js::from('https://example.test/public/supervisor/supervisions/evidence/__EVIDENCE_ID__')->toHtml(), false);
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
        }
    }

    /**
     * @return array{Supervision, Question}
     */
    private function createSupervision(): array
    {
        $this->stage->update(['status' => 'FINALIZED']);
        $template = $this->instrument->formTemplate;
        $question = $template->sections()->firstOrFail()->questions()->create([
            'form_template_id' => $template->id, 'code' => 'FILE', 'question_text' => 'Bukti pelaksanaan supervisi',
            'question_type' => 'FILE', 'is_required' => true, 'is_scored' => false, 'sort_order' => 2,
        ]);
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'status' => 'FINAL', 'assignment_type' => 'MANUAL',
        ]);
        $supervision = Supervision::create([
            'supervision_assignment_id' => $assignment->id, 'stage_instrument_id' => $this->instrument->id,
            'form_template_id' => $template->id, 'scoring_policy_id' => $this->instrument->scoring_policy_id,
            'status' => 'DRAFT', 'row_version' => 1,
        ]);

        return [$supervision, $question];
    }
}
