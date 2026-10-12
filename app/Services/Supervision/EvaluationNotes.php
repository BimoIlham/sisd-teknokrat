<?php

namespace App\Services\Supervision;

class EvaluationNotes
{
    public const array LABELS = [
        'positive' => 'Hal Positif',
        'improvement' => 'Hal yang Perlu Diperbaiki',
        'suggestion' => 'Saran Tindak Lanjut',
    ];

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
