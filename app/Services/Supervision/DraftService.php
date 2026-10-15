<?php

namespace App\Services\Supervision;

use App\Models\Question;
use App\Models\Supervision;
use App\Models\SupervisionAnswer;
use App\Services\Scheduling\SchedulingLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DraftService
{
    public function __construct(private SupervisionAccessService $access) {}

    /**
     * Save draft answers with optimistic locking on row_version.
     *
     * @param  array<int, mixed>  $answers
     * @param  array<int, string|null>  $notes
     * @return array{row_version: int, updated_at: string}
     */
    public function saveDraft(Supervision $supervision, array $answers, int $expectedRowVersion, array $notes = []): array
    {
        return DB::transaction(function () use ($supervision, $answers, $expectedRowVersion, $notes) {
            app(SchedulingLockService::class)->state();
            // Optimistic concurrency check
            $currentSupervision = Supervision::with('assignment.supervisionStage.period')
                ->lockForUpdate()
                ->findOrFail($supervision->id);
            $this->access->authorizeMutation($currentSupervision);

            if ($currentSupervision->row_version !== $expectedRowVersion) {
                throw new ConflictHttpException("Data formulir telah diperbarui di sesi atau tab lain. Versi lokal: {$expectedRowVersion}, Versi server: {$currentSupervision->row_version}.");
            }

            $questionIds = array_unique([...array_keys($answers), ...array_keys($notes)]);
            $questionCount = $currentSupervision->formTemplate->questions()->count();
            if (count($questionIds) > $questionCount) {
                throw ValidationException::withMessages(['answers' => 'Jumlah jawaban melebihi jumlah butir formulir.']);
            }

            $questions = Question::query()
                ->where('form_template_id', $currentSupervision->form_template_id)
                ->whereIn('id', $questionIds)
                ->with('options')
                ->get()
                ->keyBy('id');
            Validator::make(['notes' => $notes], ['notes.*' => ['nullable', 'string', 'max:5000']])->validate();
            foreach ($questions as $questionId => $question) {
                if (! array_key_exists($questionId, $answers)) {
                    continue;
                }
                if ($question->usesStructuredEvaluation()) {
                    Validator::make(['evaluation' => $answers[$questionId]], [
                        'evaluation' => ['present', 'array:positive,improvement,suggestion'],
                        'evaluation.positive' => ['nullable', 'string', 'max:5000'],
                        'evaluation.improvement' => ['nullable', 'string', 'max:5000'],
                        'evaluation.suggestion' => ['nullable', 'string', 'max:5000'],
                    ])->validate();

                    continue;
                }
                $rules = match ($question->question_type) {
                    'LINK' => ['nullable', 'string', 'max:2048', 'url:http,https'],
                    'TEXTAREA', 'FILE' => ['nullable', 'string', 'max:5000'],
                    default => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! is_scalar($value) || (is_string($value) && mb_strlen($value) > 5000)) {
                            $fail('Jawaban harus berupa nilai tunggal dengan panjang maksimal 5000 karakter.');
                        }
                    }],
                };
                if ($question->question_type === 'SCORE') {
                    $rules = ['nullable', 'integer', Rule::in($question->options->modelKeys())];
                }
                Validator::make(['answers' => [$questionId => $answers[$questionId]]], ['answers.'.$questionId => $rules])->validate();
            }
            $now = now();
            $answerRows = [];
            $existingAnswers = $currentSupervision->answers()->get()->keyBy('question_id');

            foreach ($questions as $questionId => $question) {
                $existingAnswer = $existingAnswers->get($questionId);
                $hasAnswerValue = $question->question_type !== 'FILE' && array_key_exists($questionId, $answers);
                $value = $answers[$questionId] ?? null;
                $note = array_key_exists($questionId, $notes)
                    ? trim((string) $notes[$questionId])
                    : $existingAnswer?->notes;

                $answerRow = [
                    'supervision_id' => $currentSupervision->id,
                    'question_id' => $question->id,
                    'question_option_id' => $hasAnswerValue ? null : $existingAnswer?->question_option_id,
                    'boolean_value' => $hasAnswerValue ? null : $existingAnswer?->boolean_value,
                    'text_value' => $hasAnswerValue ? null : $existingAnswer?->text_value,
                    'numeric_score' => $hasAnswerValue ? null : $existingAnswer?->numeric_score,
                    'evaluation_notes' => $hasAnswerValue ? null : ($existingAnswer?->evaluation_notes === null ? null : json_encode($existingAnswer->evaluation_notes, JSON_THROW_ON_ERROR)),
                    'notes' => $note !== '' ? $note : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($hasAnswerValue && $question->usesStructuredEvaluation()) {
                    $evaluationNotes = EvaluationNotes::normalize($value);
                    $answerRow['evaluation_notes'] = json_encode($evaluationNotes, JSON_THROW_ON_ERROR);
                    $answerRow['text_value'] = EvaluationNotes::toText($evaluationNotes);
                } elseif ($hasAnswerValue && $question->question_type === 'SCORE') {
                    $optionId = (int) $value;
                    $option = $question->options->firstWhere('id', $optionId);
                    $answerRow['question_option_id'] = $option?->id;
                    $answerRow['numeric_score'] = $option?->numeric_score;
                    $answerRow['text_value'] = $option?->option_text;
                } elseif ($hasAnswerValue && $question->question_type === 'YES_NO') {
                    $optionId = is_numeric($value) ? (int) $value : null;
                    $option = $optionId ? $question->options->firstWhere('id', $optionId) : null;
                    if ($option) {
                        $answerRow['question_option_id'] = $option->id;
                        $answerRow['numeric_score'] = $option->numeric_score;
                        $answerRow['boolean_value'] = (bool) $option->numeric_score;
                        $answerRow['text_value'] = $option->option_text;
                    } else {
                        $booleanValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        $answerRow['boolean_value'] = $booleanValue;
                        $answerRow['numeric_score'] = $booleanValue ? 4.0 : 0.0;
                        $answerRow['text_value'] = $booleanValue ? 'Ya' : 'Tidak';
                    }
                } elseif ($hasAnswerValue && in_array($question->question_type, ['LINK', 'TEXTAREA'], true)) {
                    $answerRow['text_value'] = is_string($value) ? trim($value) : null;
                }

                $answerRows[] = $answerRow;
            }

            if ($answerRows !== []) {
                SupervisionAnswer::upsert(
                    $answerRows,
                    ['supervision_id', 'question_id'],
                    ['question_option_id', 'boolean_value', 'text_value', 'numeric_score', 'notes', 'evaluation_notes', 'updated_at']
                );
                $currentSupervision->draft_started_at ??= $now;
            }

            $currentSupervision->row_version += 1;
            $currentSupervision->touch();
            $currentSupervision->save();

            return [
                'row_version' => $currentSupervision->row_version,
                'updated_at' => $currentSupervision->updated_at->toISOString(),
            ];
        });
    }
}
