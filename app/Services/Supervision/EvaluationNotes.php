<?php

namespace App\Services\Supervision;

use App\Models\FormTemplate;
use App\Models\Question;

class EvaluationNotes
{
    public const int MIN_SUGGESTION_WORDS = 25;

    public const string WORD_PATTERN = '\p{L}[\p{L}\p{M}\p{N}]*(?:[-’\'][\p{L}\p{N}][\p{L}\p{M}\p{N}]*)*';

    public const array LABELS = [
        'positive' => 'Hal Positif',
        'improvement' => 'Hal yang Perlu Diperbaiki',
        'suggestion' => 'Saran Tindak Lanjut',
    ];

    public static function finalQuestionId(FormTemplate $template): ?int
    {
        return $template->sections->flatMap->questions
            ->last(fn (Question $question): bool => $question->usesStructuredEvaluation())?->id;
    }

    public static function suggestionWordCount(string $suggestion): int
    {
        return preg_match_all('/'.self::WORD_PATTERN.'/u', $suggestion) ?: 0;
    }

    /**
     * @param  array<string, string|null>  $notes
     * @return array{positive: string, improvement: string, suggestion: string}
     */
    public static function normalize(array $notes): array
    {
        $normalized = [];
        foreach (self::LABELS as $key => $label) {
            $normalized[$key] = trim($notes[$key] ?? '');
        }

        return $normalized;
    }

    /** @param array<string, string|null> $notes */
    public static function toText(array $notes): string
    {
        $parts = [];
        foreach (self::normalize($notes) as $key => $text) {
            if ($text !== '') {
                $parts[] = self::LABELS[$key].":\n".$text;
            }
        }

        return implode("\n\n", $parts);
    }
}
