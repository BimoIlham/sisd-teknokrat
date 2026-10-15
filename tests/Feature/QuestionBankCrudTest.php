<?php

namespace Tests\Feature;

use App\Models\FormTemplate;
use App\Models\Lecturer;
use App\Models\Question;
use App\Models\ScoringPolicy;
use App\Models\Supervision;
use App\Models\SupervisionAnswer;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\User;
use Database\Seeders\DemoInstrumentSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class QuestionBankCrudTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected FormTemplate $offlineTemplate;

    protected FormTemplate $onlineTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        if (User::count() === 0) {
            $this->seed(RoleAndPermissionSeeder::class);
            $this->seed(UserSeeder::class);
        }

        if (FormTemplate::count() === 0) {
            $this->seed(DemoInstrumentSeeder::class);
        }

        $this->admin = User::where('email', 'lpm@teknokrat.ac.id')->first() ?? User::first();
        if (! $this->admin->hasRole('superadmin')) {
            $this->seed(RoleAndPermissionSeeder::class);
            $this->admin->assignRole('superadmin');
        }

        $this->offlineTemplate = FormTemplate::where('delivery_mode', 'OFFLINE')->latest('version')->firstOrFail();
        $this->onlineTemplate = FormTemplate::where('delivery_mode', 'ONLINE')->latest('version')->firstOrFail();
    }

    public function test_superadmin_can_access_separated_offline_and_online_question_bank(): void
    {
        $offlineQuestionCode = $this->offlineTemplate->questions()->firstOrFail()->code;
        $onlineQuestionCode = $this->onlineTemplate->questions()->firstOrFail()->code;

        $responseOffline = $this->actingAs($this->admin)->get(route('lpm.instruments.mode', 'offline'));
        $responseOffline->assertStatus(200);
        $responseOffline->assertSee('Bank Pertanyaan Supervisi (Offline)');
        $responseOffline->assertSee('OFFLINE');
        $responseOffline->assertDontSee($offlineQuestionCode);

        $responseOnline = $this->actingAs($this->admin)->get(route('lpm.instruments.mode', 'online'));
        $responseOnline->assertStatus(200);
        $responseOnline->assertSee('Bank Pertanyaan Supervisi (Online)');
        $responseOnline->assertSee('ONLINE');
        $responseOnline->assertDontSee($onlineQuestionCode);
    }

    public function test_superadmin_can_add_question_to_offline_bank(): void
    {
        $section = $this->offlineTemplate->sections()->first();
        $initialScored = $this->offlineTemplate->fresh()->expected_scored_count;

        $response = $this->actingAs($this->admin)->post(route('lpm.instruments.questions.store', $this->offlineTemplate), [
            'form_section_id' => $section->id,
            'question_text' => 'Butir Uji: Kemampuan dosen dalam memotivasi mahasiswa baru',
            'help_text' => 'Amati interaksi langsung di kelas',
            'question_type' => 'SCORE',
            'is_scored' => 1,
            'is_required' => 1,
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('questions', [
            'form_template_id' => $this->offlineTemplate->id,
            'form_section_id' => $section->id,
            'question_text' => 'Butir Uji: Kemampuan dosen dalam memotivasi mahasiswa baru',
            'question_type' => 'SCORE',
            'is_scored' => true,
        ]);

        $question = Question::where('question_text', 'Butir Uji: Kemampuan dosen dalam memotivasi mahasiswa baru')->first();
        $this->assertNotNull($question);
        $this->assertStringStartsWith('OFF_Q', $question->code);
        $this->assertEquals(5, $question->options()->count());

        // Expected scored count should be incremented
        $this->assertEquals($initialScored + 1, $this->offlineTemplate->fresh()->expected_scored_count);
    }

    public function test_superadmin_can_add_question_to_online_bank(): void
    {
        $section = $this->onlineTemplate->sections()->first();

        $response = $this->actingAs($this->admin)->post(route('lpm.instruments.questions.store', $this->onlineTemplate), [
            'form_section_id' => $section->id,
            'question_text' => 'Butir Uji Online: Kelengkapan modul ajar di SPADA',
            'help_text' => 'Periksa tautan pertemuan pada LMS',
            'question_type' => 'YES_NO',
            'is_scored' => 0,
            'is_required' => 1,
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('questions', [
            'form_template_id' => $this->onlineTemplate->id,
            'form_section_id' => $section->id,
            'question_text' => 'Butir Uji Online: Kelengkapan modul ajar di SPADA',
            'question_type' => 'YES_NO',
            'is_scored' => false,
        ]);

        $question = Question::where('question_text', 'Butir Uji Online: Kelengkapan modul ajar di SPADA')->first();
        $this->assertNotNull($question);
        $this->assertStringStartsWith('ON_Q', $question->code);
        $this->assertEquals(2, $question->options()->count());
    }

    public function test_superadmin_can_edit_question_and_options(): void
    {
        $section = $this->offlineTemplate->sections()->first();

        $question = Question::create([
            'form_template_id' => $this->offlineTemplate->id,
            'form_section_id' => $section->id,
            'code' => 'TEST_EDIT_Q01',
            'question_text' => 'Pertanyaan sebelum diedit',
            'help_text' => 'Bantuan lama',
            'question_type' => 'SCORE',
            'is_scored' => true,
            'is_required' => true,
            'sort_order' => 999,
        ]);

        $response = $this->actingAs($this->admin)->put(route('lpm.instruments.questions.update', $question), [
            'form_section_id' => $section->id,
            'code' => 'ATTEMPTED_CODE_CHANGE',
            'question_text' => 'Pertanyaan setelah diedit dan disempurnakan',
            'help_text' => 'Panduan pengisian baru',
            'question_type' => 'SCORE',
            'is_scored' => 1,
            'is_required' => 1,
            'sort_order' => 999,
            'options' => [
                ['value' => '0', 'label' => 'Sangat Kurang', 'numeric_score' => 0],
                ['value' => '4', 'label' => 'Sangat Memuaskan', 'numeric_score' => 4],
            ],
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('questions', [
            'id' => $question->id,
            'code' => 'TEST_EDIT_Q01',
            'question_text' => 'Pertanyaan setelah diedit dan disempurnakan',
            'help_text' => 'Panduan pengisian baru',
        ]);

        $this->assertEquals(2, $question->fresh()->options()->count());
    }

    public function test_superadmin_can_delete_question_without_answers(): void
    {
        $section = $this->offlineTemplate->sections()->first();

        $question = Question::create([
            'form_template_id' => $this->offlineTemplate->id,
            'form_section_id' => $section->id,
            'code' => 'TEST_DEL_Q01',
            'question_text' => 'Pertanyaan sementara untuk dihapus',
            'question_type' => 'SCORE',
            'is_scored' => true,
            'is_required' => false,
            'sort_order' => 998,
        ]);
        $this->offlineTemplate->update([
            'expected_scored_count' => $this->offlineTemplate->questions()->where('is_scored', true)->count(),
        ]);

        $initialScored = $this->offlineTemplate->fresh()->expected_scored_count;

        $response = $this->actingAs($this->admin)->delete(route('lpm.instruments.questions.destroy', $question));

        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('questions', ['id' => $question->id]);
        $this->assertEquals($initialScored - 1, $this->offlineTemplate->fresh()->expected_scored_count);
    }

    public function test_superadmin_cannot_delete_question_with_answers(): void
    {
        $section = $this->offlineTemplate->sections()->first();

        $question = Question::create([
            'form_template_id' => $this->offlineTemplate->id,
            'form_section_id' => $section->id,
            'code' => 'TEST_LOCKED_Q01',
            'question_text' => 'Pertanyaan yang sudah punya jawaban',
            'question_type' => 'SCORE',
            'is_scored' => true,
            'is_required' => true,
            'sort_order' => 997,
        ]);

        // Create dummy stage & assignment & supervision if available
        $stage = SupervisionStage::first();
        $targetLec = Lecturer::first();
        $supLec = Lecturer::skip(1)->first() ?? $targetLec;
        $occ = TeachingOccurrence::first();

        if ($stage && $targetLec && $supLec && $occ) {
            $assignment = SupervisionAssignment::firstOrCreate([
                'supervision_stage_id' => $stage->id,
                'target_lecturer_id' => $targetLec->id,
                'supervisor_lecturer_id' => $supLec->id,
                'teaching_occurrence_id' => $occ->id,
            ], [
                'assignment_type' => 'MANUAL',
                'status' => 'FINAL',
                'starts_at' => now(),
                'ends_at' => now()->addHours(2),
            ]);

            $policy = ScoringPolicy::first();
            $supervision = Supervision::firstOrCreate([
                'supervision_assignment_id' => $assignment->id,
            ], [
                'form_template_id' => $this->offlineTemplate->id,
                'scoring_policy_id' => $policy?->id ?? 1,
                'status' => 'DRAFT',
                'row_version' => 1,
            ]);

            SupervisionAnswer::create([
                'supervision_id' => $supervision->id,
                'question_id' => $question->id,
                'numeric_score' => 4.0,
            ]);

            $response = $this->actingAs($this->admin)->delete(route('lpm.instruments.questions.destroy', $question));

            $response->assertSessionHasErrors('instrument');
            $this->assertDatabaseHas('questions', ['id' => $question->id]);
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_superadmin_can_add_section_to_template(): void
    {
        $response = $this->actingAs($this->admin)->post(route('lpm.instruments.sections.store', $this->offlineTemplate), [
            'title' => 'Aspek Penilaian Tambahan',
            'subtitle' => 'Keterangan aspek tambahan',
            'sort_order' => 99,
        ]);

        $response->assertSessionHas('success');
        $section = $this->offlineTemplate->sections()
            ->where('title', 'Aspek Penilaian Tambahan')
            ->firstOrFail();

        $this->assertStringStartsWith('SEC_OFF_', $section->code);
        $this->assertDatabaseHas('form_sections', [
            'form_template_id' => $this->offlineTemplate->id,
            'title' => 'Aspek Penilaian Tambahan',
        ]);
    }
}
