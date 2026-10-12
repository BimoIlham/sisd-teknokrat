<?php

namespace App\Services\Supervision;

use App\Models\Question;
use App\Models\ScoringPolicy;

class ScoringService
{
    /**
     * Calculate score from answers according to the given policy.
     * Invariant 33: Always resolve scores from DB options, never trust client input.
     * Invariant 34: raw_score, N, final_score (stored with 6 decimals precision).
     *
     * @param  array<int, mixed>  $answers  Map of [question_id => answer_value]
     * @return array{raw_score: float, n_items: int, final_score: float}
     */
    public function calculate(array $answers, ScoringPolicy $policy): array
    {
        $scoredQuestions = Question::whereIn('id', array_keys($answers))
            ->where('is_scored', true)
            ->with('options')
            ->get();

        $totalPoints = 0.0;
        $scoredCount = 0;

        foreach ($scoredQuestions as $question) {
            $value = $answers[$question->id] ?? null;

            if ($value === null || $value === '') {
                continue; // Missing score
            }

            $numericPoint = null;

            if ($question->question_type === 'SCORE') {
                // Find matching option in DB
                $option = $question->options->firstWhere('id', (int) $value);
                if ($option && $option->numeric_score !== null) {
                    $numericPoint = (float) $option->numeric_score;
                }
            } elseif ($question->question_type === 'YES_NO') {
                $option = $question->options->firstWhere('id', (int) $value);
                if ($option && $option->numeric_score !== null) {
                    $numericPoint = (float) $option->numeric_score;
                } else {
                    // Fallback to direct boolean mapping if option ID wasn't stored
                    $boolVal = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($boolVal !== null) {
                        $numericPoint = $boolVal ? 4.0 : 0.0;
                    }
                }
            }

            if ($numericPoint !== null) {
                $totalPoints += $numericPoint;
                $scoredCount++;
            }
        }

        if ($scoredCount === 0) {
            return [
                'raw_score' => 0.0,
                'n_items' => 0,
                'final_score' => 0.0,
            ];
        }

        $rawAverage = $totalPoints / $scoredCount;
        $finalScore = $this->applyFormula($rawAverage, $policy->strategy, $totalPoints, $scoredCount);

        return [
            'raw_score' => round($totalPoints, 6),
            'n_items' => $scoredCount,
            'final_score' => round($finalScore, 6),
        ];
    }

    /**
     * Apply mathematical formula based on strategy.
     * MEAN_0_4: direct unweighted average in range [0, 4]
     * LINEAR_1_4: 1.0 + (raw / (N * 4)) * 3.0 in range [1, 4]
     * FLOOR_1_4: max(1.0, raw / N)
     */
    public function applyFormula(float $rawAverage, string $formulaCode, float $totalPoints, int $scoredCount): float
    {
        switch ($formulaCode) {
            case 'MEAN_0_4':
                return $rawAverage;

            case 'LINEAR_1_4':
                $maxPossible = $scoredCount * 4.0;
                if ($maxPossible <= 0) {
                    return 1.0;
                }

                return 1.0 + (($totalPoints / $maxPossible) * 3.0);

            case 'FLOOR_1_4':
                return max(1.0, $rawAverage);

            default:
                // Default fallback to mean
                return $rawAverage;
        }
    }
}
