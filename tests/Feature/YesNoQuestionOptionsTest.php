<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Supervision;
use App\Models\SupervisionAnswer;
use App\Models\SupervisionAssignment;
use App\Services\Supervision\DraftService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class YesNoQuestionOptionsTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_yes_no_questions_reject_scale_options_without_changing_the_bank(bool $create): void
    {
        $question = $this->invalidQuestion();
        $before = $this->instrument->formTemplate->load('sections.questions.options')->toArray();
        $payload = $question->only(['form_section_id', 'question_text', 'question_type', 'sort_order']);
        $payload['options'] = $question->options->toArray();

        $response = $create
            ? $this->postJson(route('lpm.instruments.questions.store', $question->template), $payload)
            : $this->putJson(route('lpm.instruments.questions.update', $question), $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors('options');
        $this->assertSame($before, $this->instrument->formTemplate->fresh()->load('sections.questions.options')->toArray());
    }

    #[TestWith([['YES', 'YES']])]
    #[TestWith([['0', '4']])]
    public function test_yes_no_questions_reject_duplicate_or_non_boolean_values(array $values): void
    {
        $question = $this->invalidQuestion();
        $payload = $question->only(['form_section_id', 'question_text', 'question_type', 'sort_order']);
        $payload['options'] = [
            ['value' => $values[0], 'label' => 'Ya'],
            ['value' => $values[1], 'label' => 'Tidak'],
        ];

        $this->putJson(route('lpm.instruments.questions.update', $question), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('options.0.value');
        $this->assertCount(5, $question->fresh()->options);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_missing_yes_no_options_use_defaults_on_create_and_update(bool $create): void
    {
        $question = $this->instrument->formTemplate->questions()->firstOrFail();
        $payload = $question->only(['form_section_id', 'question_text', 'sort_order']);
        $payload['question_type'] = 'YES_NO';

        $response = $create
            ? $this->postJson(route('lpm.instruments.questions.store', $question->template), $payload)
            : $this->putJson(route('lpm.instruments.questions.update', $question), $payload);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $savedQuestion = $create ? $question->template->questions()->latest('id')->firstOrFail() : $question->fresh();
        $this->assertTrue($savedQuestion->hasValidYesNoOptions());
        $this->assertSame(['4.00', '0.00'], $savedQuestion->options->pluck('numeric_score')->all());
    }

    #[TestWith(['YES', true, '4.00'])]
    #[TestWith(['NO', false, '0.00'])]
    public function test_switching_to_yes_no_renders_two_choices_and_saves_the_answer(string $value, bool $expectedBoolean, string $expectedScore): void
    {
        $question = $this->instrument->formTemplate->questions()->firstOrFail();
        $oldOptionId = $question->options()->firstOrFail()->id;
        $payload = $question->only(['form_section_id', 'question_text', 'sort_order']);
        $payload += ['question_type' => 'YES_NO', 'is_scored' => true, 'is_required' => true,
            'options' => [
                ['value' => 'YES', 'label' => 'Ya, materi tersedia', 'numeric_score' => 4],
                ['value' => 'NO', 'label' => 'Tidak ada materi', 'numeric_score' => 0],
            ]];
        $this->putJson(route('lpm.instruments.questions.update', $question), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('question_options', ['id' => $oldOptionId]);
        $supervision = $this->createSupervision();

        $response = $this->actingAs($this->supervisorUser)
            ->get(route('supervisor.supervisions.form', $supervision->assignment));
        $response->assertOk()->assertSee('Ya, materi tersedia')->assertSee('Tidak ada materi')->assertDontSee('Kurang Memenuhi');
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $radioInputs = (new DOMXPath($document))->query('//input[@type="radio"][@name="answers['.$question->id.']"]');
        $this->assertSame(2, $radioInputs->length);

        $selectedOption = $question->fresh()->options->firstWhere('value', $value);
        app(DraftService::class)->saveDraft($supervision, [$question->id => $selectedOption->id], 1);
        $answer = $supervision->answers()->firstOrFail();
        $this->assertSame($selectedOption->id, $answer->question_option_id);
        $this->assertSame($expectedBoolean, $answer->boolean_value);
        $this->assertSame($expectedScore, $answer->numeric_score);
        $this->assertSame($selectedOption->label, $answer->text_value);
    }

    public function test_repair_preview_does_not_write_and_apply_only_repairs_the_chosen_question(): void
    {
        $question = $this->invalidQuestion();
        $otherQuestion = $question->replicate();
        $otherQuestion->code = 'OTHER_YES_NO';
        $otherQuestion->sort_order = 2;
        $otherQuestion->save();
        $before = $question->options->toArray();
        $flags = $question->only(['is_scored', 'is_required']);

        $this->artisan('instruments:repair-yes-no')->expectsOutputToContain('Pratinjau saja')->assertSuccessful();
        $this->assertSame($before, $question->fresh()->options->toArray());
        $this->artisan('instruments:repair-yes-no', ['--apply' => true])->assertFailed();
        $this->assertSame($before, $question->fresh()->options->toArray());
        $this->artisan('instruments:repair-yes-no', ['--question' => $question->id, '--apply' => true])->assertSuccessful();
        $question->refresh();
        $this->assertTrue($question->hasValidYesNoOptions());
        $this->assertSame(['4.00', '0.00'], $question->options->pluck('numeric_score')->all());
        $this->assertSame($flags, $question->only(['is_scored', 'is_required']));
        $this->assertCount(0, $otherQuestion->fresh()->options);
        $optionIds = $question->options->modelKeys();
        $this->artisan('instruments:repair-yes-no', ['--question' => $question->id, '--apply' => true])->assertSuccessful();
        $this->assertSame($optionIds, $question->fresh()->options->modelKeys());
    }

    #[TestWith(['draft'])]
    #[TestWith(['answer'])]
    #[TestWith(['submitted'])]
    #[TestWith(['old_template'])]
    public function test_repair_does_not_change_instruments_with_history_or_old_versions(string $state): void
    {
        $question = $this->invalidQuestion();
        if ($state === 'old_template') {
            $this->legacyTemplateCopy($question->template);
        } else {
            $supervision = $this->createSupervision();
            if ($state === 'draft') {
                $supervision->update(['draft_started_at' => now()]);
            } elseif ($state === 'submitted') {
                $supervision->update(['status' => 'SUBMITTED']);
            } else {
                SupervisionAnswer::create(['supervision_id' => $supervision->id, 'question_id' => $question->id,
                    'question_option_id' => $question->options->first()->id, 'numeric_score' => 0]);
            }
        }
        $before = $question->fresh()->load('options', 'answers')->toArray();

        $this->artisan('instruments:repair-yes-no', ['--question' => $question->id, '--apply' => true])->assertFailed();
        $this->assertSame($before, $question->fresh()->load('options', 'answers')->toArray());
    }

    private function invalidQuestion(): Question
    {
        $question = $this->instrument->formTemplate->questions()->firstOrFail();
        $question->update(['question_type' => 'YES_NO']);
        $question->options()->delete();
        foreach (range(0, 4) as $score) {
            $question->options()->create(['value' => (string) $score, 'label' => 'Skala '.$score,
                'numeric_score' => $score, 'sort_order' => $score + 1]);
        }

        return $question->fresh();
    }

    private function createSupervision(): Supervision
    {
        $this->stage->update(['status' => 'FINALIZED']);
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'status' => 'FINAL', 'assignment_type' => 'MANUAL',
        ]);

        return Supervision::create([
            'supervision_assignment_id' => $assignment->id, 'stage_instrument_id' => $this->instrument->id,
            'form_template_id' => $this->instrument->form_template_id, 'scoring_policy_id' => $this->instrument->scoring_policy_id,
            'status' => 'DRAFT', 'row_version' => 1,
        ]);
    }
}
