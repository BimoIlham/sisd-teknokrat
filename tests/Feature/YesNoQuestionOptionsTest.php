<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Supervision;
use App\Models\SupervisionAnswer;
use App\Models\SupervisionAssignment;
use App\Services\Reporting\SupervisionResultPdfService;
use App\Services\Supervision\DraftService;
use App\Services\Supervision\SubmitService;
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

    #[TestWith(['other_question', '0 jawaban terisi (0 draf, 0 final), 0 baris kosong/catatan.'])]
    #[TestWith(['blank', '0 jawaban terisi (0 draf, 0 final), 1 baris kosong/catatan.'])]
    #[TestWith(['zero_draft', '1 jawaban terisi (1 draf, 0 final), 0 baris kosong/catatan.'])]
    #[TestWith(['zero_submitted', '1 jawaban terisi (0 draf, 1 final), 0 baris kosong/catatan.'])]
    public function test_question_preview_distinguishes_its_answers_from_other_questions_without_writing(string $state, string $expectedSummary): void
    {
        $question = $this->invalidQuestion();
        $supervision = $this->createSupervision();
        $supervision->update(['draft_started_at' => now()]);
        $answeredQuestion = $question;
        if ($state === 'other_question') {
            $answeredQuestion = $question->replicate();
            $answeredQuestion->code = 'OTHER_ANSWERED';
            $answeredQuestion->sort_order = 2;
            $answeredQuestion->save();
        }
        SupervisionAnswer::create([
            'supervision_id' => $supervision->id, 'question_id' => $answeredQuestion->id,
            'numeric_score' => $state === 'blank' ? null : 0,
            'boolean_value' => $state === 'blank' ? null : false,
            'notes' => 'Catatan pribadi yang tidak perlu ditampilkan.',
        ]);
        if ($state === 'zero_submitted') {
            $supervision->update(['status' => 'SUBMITTED']);
        }
        $before = $question->fresh()->load('options', 'answers')->toArray();
        $supervisionBefore = $supervision->fresh()->toArray();

        $this->artisan('instruments:repair-yes-no', ['--question' => $question->id])
            ->expectsOutput("Soal {$question->id}: {$expectedSummary}")
            ->expectsOutputToContain('Pratinjau saja')->assertSuccessful();

        $this->assertSame($before, $question->fresh()->load('options', 'answers')->toArray());
        $this->assertSame($supervisionBefore, $supervision->fresh()->toArray());
    }

    public function test_used_yes_no_question_displays_two_choices_without_changing_its_legacy_options(): void
    {
        $question = $this->invalidQuestion();
        $supervision = $this->createSupervision();
        $supervision->update(['draft_started_at' => now()]);
        $before = $question->fresh()->load('options')->toArray();

        $this->get(route('lpm.instruments.mode', 'offline'))->assertOk()
            ->assertSee('Pilihan Ya / Tidak')->assertSee('Ya, tersedia')->assertSee('Tidak tersedia')
            ->assertDontSee('Skala 2');
        $response = $this->actingAs($this->supervisorUser)
            ->get(route('supervisor.supervisions.form', $supervision->assignment));
        $response->assertOk()->assertSee('Ya, tersedia')->assertSee('Tidak tersedia')
            ->assertDontSee('Skala 2')
            ->assertViewHas('scoredQuestionsPayload', fn ($questions): bool => $questions->first()['options']->pluck('id')->all() === ['YES', 'NO']);
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $inputs = (new DOMXPath($document))->query('//input[@type="radio"][@name="answers['.$question->id.']"]');
        $this->assertSame(2, $inputs->length);
        $this->assertSame('YES', $inputs->item(0)->getAttribute('value'));
        $this->assertSame('NO', $inputs->item(1)->getAttribute('value'));
        $this->assertStringContainsString("== 'YES'", $inputs->item(0)->parentNode->getAttribute(':class'));
        $this->assertSame($before, $question->fresh()->load('options')->toArray());
        $this->assertSame(0, $supervision->answers()->count());
    }

    #[TestWith(['YES', true, '4.00'])]
    #[TestWith(['NO', false, '0.00'])]
    public function test_default_yes_no_choices_save_reload_and_submit_with_the_correct_score(string $value, bool $boolean, string $score): void
    {
        $question = $this->invalidQuestion();
        $supervision = $this->createSupervision();
        $optionsBefore = $question->options->toArray();
        $this->actingAs($this->supervisorUser);

        app(DraftService::class)->saveDraft($supervision, [$question->id => $value], 1);
        $answer = $supervision->answers()->firstOrFail();
        $this->assertNull($answer->selected_option_id);
        $this->assertSame($boolean, $answer->boolean_value);
        $this->assertSame($score, $answer->numeric_score);
        $this->get(route('supervisor.supervisions.form', $supervision->assignment))
            ->assertOk()->assertViewHas('existingAnswersPayload', fn ($answers): bool => $answers->get($question->id) === $value);
        $preview = app(SubmitService::class)->preview($supervision->fresh());
        $this->assertSame(1, $preview['n_items']);
        $this->assertSame((float) $score, $preview['final_score']);
        $submitted = app(SubmitService::class)->submit($supervision->fresh(), $this->supervisorUser, $supervision->fresh()->row_version);
        $this->assertSame('SUBMITTED', $submitted->status);
        $this->assertSame($boolean, $submitted->submission_snapshot['answers'][0]['boolean_value']);
        $this->assertSame((float) $score, (float) $submitted->final_score);
        $this->get(route('supervisor.supervisions.show', $submitted))->assertOk()->assertSee($boolean ? 'Ya' : 'Tidak');
        $this->assertSame($optionsBefore, $question->fresh()->options->toArray());
    }

    public function test_legacy_draft_answer_is_preserved_until_the_supervisor_selects_yes_or_no(): void
    {
        $question = $this->invalidQuestion();
        $supervision = $this->createSupervision();
        $legacyOption = $question->options->firstWhere('value', '2');
        $answer = SupervisionAnswer::create([
            'supervision_id' => $supervision->id, 'question_id' => $question->id,
            'question_option_id' => $legacyOption->id, 'numeric_score' => 2, 'boolean_value' => true,
            'notes' => 'Catatan pengamatan lama.',
        ]);
        $before = $answer->fresh()->toArray();
        $this->actingAs($this->supervisorUser)
            ->get(route('supervisor.supervisions.form', $supervision->assignment))
            ->assertOk()->assertSee('Pilih ulang Ya atau Tidak sebelum mengirim penilaian.')
            ->assertViewHas('existingAnswersPayload', fn ($answers): bool => ! $answers->has($question->id));
        $this->assertSame($before, $answer->fresh()->toArray());

        app(DraftService::class)->saveDraft($supervision, [], 1, [$question->id => 'Catatan tetap disimpan.']);
        $this->assertSame($legacyOption->id, $answer->fresh()->selected_option_id);
        $this->get(route('supervisor.supervisions.review', $supervision))
            ->assertRedirect(route('supervisor.supervisions.form', $supervision->assignment))
            ->assertSessionHasErrors('form');
        $this->assertSame('DRAFT', $supervision->fresh()->status);

        app(DraftService::class)->saveDraft($supervision->fresh(), [$question->id => 'NO'], $supervision->fresh()->row_version);
        $this->assertFalse($answer->fresh()->boolean_value);
        $this->assertSame('0.00', $answer->fresh()->numeric_score);
        $this->assertSame('Catatan tetap disimpan.', $answer->fresh()->notes);
        $this->assertSame(0.0, app(SubmitService::class)->preview($supervision->fresh())['final_score']);
    }

    public function test_default_yes_no_choices_reject_scale_option_ids_and_invalid_values(): void
    {
        $question = $this->invalidQuestion();
        $supervision = $this->createSupervision();
        $this->actingAs($this->supervisorUser);
        foreach ([$question->options->first()->id, $question->options->last()->id, '1', 0, 'skala'] as $value) {
            $this->postJson(route('supervisor.supervisions.draft', $supervision), [
                'answers' => [$question->id => $value], 'row_version' => 1,
            ])->assertUnprocessable()->assertJsonValidationErrors('answers.'.$question->id);
        }
        $this->assertSame(0, $supervision->answers()->count());
        $this->assertSame(1, $supervision->fresh()->row_version);
    }

    public function test_an_unanswered_yes_no_question_is_not_silently_saved_as_no(): void
    {
        $question = $this->invalidQuestion();
        $supervision = $this->createSupervision();
        $this->actingAs($this->supervisorUser);
        app(DraftService::class)->saveDraft($supervision, [$question->id => null], 1);
        $answer = $supervision->answers()->firstOrFail();
        $this->assertNull($answer->boolean_value);
        $this->assertNull($answer->numeric_score);
        $this->assertNull($answer->text_value);
        $this->get(route('supervisor.supervisions.review', $supervision))
            ->assertRedirect(route('supervisor.supervisions.form', $supervision->assignment))
            ->assertSessionHasErrors('form');
    }

    public function test_final_legacy_answers_scores_and_snapshots_keep_their_original_scale_values(): void
    {
        $question = $this->invalidQuestion();
        $supervision = $this->createSupervision();
        SupervisionAnswer::create([
            'supervision_id' => $supervision->id, 'question_id' => $question->id,
            'question_option_id' => $question->options->firstWhere('value', '2')->id,
            'numeric_score' => 2, 'boolean_value' => true,
        ]);
        $supervision->update([
            'status' => 'SUBMITTED', 'raw_score' => 2, 'scored_question_count' => 1, 'final_score' => 2,
            'submission_snapshot' => ['legacy' => 'Original historical snapshot'],
        ]);
        $before = $supervision->fresh()->load('answers')->toArray();
        $optionsBefore = $question->options->toArray();
        $reportBefore = app(SupervisionResultPdfService::class)->reportData($supervision->fresh());

        $this->get(route('lpm.instruments.mode', 'offline'))->assertOk()->assertSee('Ya, tersedia');
        $this->actingAs($this->supervisorUser)->get(route('supervisor.supervisions.show', $supervision))
            ->assertOk()->assertSee('Skala 2');

        $reportAfter = app(SupervisionResultPdfService::class)->reportData($supervision->fresh());
        $this->assertSame($reportBefore['structuredSections'], $reportAfter['structuredSections']);
        $this->assertSame($before, $supervision->fresh()->load('answers')->toArray());
        $this->assertSame($optionsBefore, $question->fresh()->options->toArray());
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
