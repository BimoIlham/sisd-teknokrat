<?php

namespace App\Services\Reporting;

use App\Models\Supervision;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class SupervisionResultPdfService
{
    public function generate(Supervision $supervision): \Barryvdh\DomPDF\PDF
    {
        $data = $this->reportData($supervision);
        $isStageTwo = $data['stage']?->stage_no === 2;
        $stageOneData = null;

        if ($isStageTwo && $data['assignment'] && $data['period']) {
            $stageOne = Supervision::query()
                ->where('status', 'SUBMITTED')
                ->whereHas('assignment', function (Builder $query) use ($data): void {
                    $query->where('target_lecturer_id', $data['assignment']->target_lecturer_id)
                        ->where('status', '<>', 'CANCELLED')
                        ->whereHas('supervisionStage', function (Builder $stageQuery) use ($data): void {
                            $stageQuery->where('supervision_period_id', $data['period']->id)
                                ->where('stage_no', 1);
                        });
                })
                ->first();

            if ($stageOne) {
                $stageOneData = $this->reportData($stageOne);
            }
        }

        $data['isStageTwo'] = $isStageTwo;
        $data['stageScores'] = $isStageTwo ? [1 => $stageOneData, 2 => $data] : [1 => $data];
        $data['comparisonSections'] = $isStageTwo
            ? $this->comparisonSections($stageOneData['structuredSections'] ?? [], $data['structuredSections'])
            : [];

        return Pdf::loadView('pdf.supervision-result', $data)
            ->setPaper('a4', 'portrait');
    }

    /**
     * Display data is frozen for schema v2 submissions; partial legacy snapshots use current master data.
     *
     * @return array<string, mixed>
     */
    public function reportData(Supervision $supervision, bool $useSnapshot = true): array
    {
        $snapshot = $supervision->submission_snapshot;
        if ($useSnapshot && $supervision->isSubmitted() && ($snapshot['schema_version'] ?? null) === 2
            && isset($snapshot['report'], $snapshot['instrument'])) {
            $data = $snapshot['report'];
            foreach (['assignment', 'lecturer', 'supervisor', 'stage', 'period', 'term', 'schedule', 'course'] as $key) {
                $data[$key] = $data[$key] === null ? null : json_decode(json_encode($data[$key], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            }
            $data['performedAt'] = isset($data['performedAt']) ? Carbon::parse($data['performedAt'])->timezone('Asia/Jakarta')->locale('id') : null;

            return $data + [
                'supervision' => $supervision,
                'logoDataUri' => $this->logoDataUri(),
                'generatedAt' => now('Asia/Jakarta')->locale('id')->translatedFormat('d F Y H:i:s').' WIB',
                'legacySnapshot' => false,
            ];
        }

        $supervision->loadMissing([
            'assignment.targetLecturer.studyProgram',
            'assignment.targetLecturer.faculty',
            'assignment.supervisorLecturer',
            'assignment.teachingOccurrence.teachingSchedule.course',
            'assignment.supervisionStage.period.academicTerm',
            'formTemplate.sections.questions.options',
            'answers.question.section',
            'answers.option',
            'answers.evidence',
            'scoringPolicy',
        ]);

        $assignment = $supervision->assignment;
        $targetLecturer = $assignment?->targetLecturer;
        $supervisor = $assignment?->supervisorLecturer;
        $stage = $assignment?->supervisionStage;
        $period = $stage?->period;
        $term = $period?->academicTerm;
        $schedule = $assignment?->teachingOccurrence?->teachingSchedule;
        $course = $schedule?->course;

        $answersByQuestionId = $supervision->answers->keyBy('question_id');
        $sections = $supervision->formTemplate?->sections?->sortBy('sort_order') ?? collect();

        $structuredSections = [];
        $answeredCount = 0;
        $totalQuestionCount = 0;
        $totalScorePoints = 0.0;
        $scoredAnswerCount = 0;
        $scoreDistribution = [
            4 => 0,
            3 => 0,
            2 => 0,
            1 => 0,
            0 => 0,
        ];

        foreach ($sections as $section) {
            $questionsData = [];
            $sectionScoreSum = 0.0;
            $sectionScoredCount = 0;

            foreach ($section->questions->sortBy('sort_order') as $question) {
                $totalQuestionCount++;

                $answer = $answersByQuestionId->get($question->id);
                $selectedOption = $answer?->option;
                $score = $answer?->numeric_score ?? $selectedOption?->numeric_score;
                $questionText = preg_replace('/^Butir\s*\d+\s*:\s*/i', '', $question->question_text)
                    ?? $question->question_text;
                $answerDisplay = 'Belum diisi';
                $isAnswered = false;

                if ($question->question_type === 'SCORE') {
                    $answerDisplay = $selectedOption?->label
                        ?? ($score !== null ? number_format((float) $score, 1) : 'Belum diisi');
                    $isAnswered = $selectedOption !== null || $score !== null;
                } elseif ($question->question_type === 'YES_NO') {
                    if ($selectedOption !== null) {
                        $answerDisplay = $selectedOption->label;
                        $isAnswered = true;
                    } elseif ($answer?->boolean_value !== null) {
                        $answerDisplay = $answer->boolean_value ? 'Ya' : 'Tidak';
                        $isAnswered = true;
                    }
                } elseif ($question->question_type === 'TEXTAREA') {
                    $textValue = trim((string) $answer?->text_value);
                    $answerDisplay = $textValue !== '' ? $textValue : 'Belum diisi';
                    $isAnswered = $textValue !== '';

                } elseif ($question->question_type === 'LINK') {
                    $textValue = trim((string) $answer?->text_value);
                    $answerDisplay = $textValue !== '' ? $textValue : 'Belum diisi';
                    $isAnswered = $textValue !== '';
                } elseif ($question->question_type === 'FILE') {
                    $fileNames = $answer?->evidence
                        ?->pluck('original_filename')
                        ->filter()
                        ->values()
                        ->all() ?? [];
                    $answerDisplay = $fileNames !== [] ? implode(', ', $fileNames) : 'Belum ada berkas';
                    $isAnswered = $fileNames !== [];
                }

                if ($isAnswered) {
                    $answeredCount++;
                }

                if ($question->is_scored && $score !== null) {
                    $sectionScoreSum += (float) $score;
                    $sectionScoredCount++;
                    $totalScorePoints += (float) $score;
                    $scoredAnswerCount++;

                    $scoreBucket = max(0, min(4, (int) round((float) $score)));
                    $scoreDistribution[$scoreBucket]++;
                }

                $questionsData[] = [
                    'code' => $question->code,
                    'text' => $questionText,
                    'type' => $question->question_type,
                    'is_scored' => $question->is_scored,
                    'score' => $score,
                    'answer_display' => $answerDisplay,
                    'notes' => $answer?->notes,
                    'evaluation_notes' => $answer?->evaluation_notes,
                    'is_answered' => $isAnswered,
                ];
            }

            $sectionAverage = $sectionScoredCount > 0
                ? round($sectionScoreSum / $sectionScoredCount, 2)
                : null;

            $structuredSections[] = [
                'code' => $section->code,
                'title' => $section->title,
                'avg_score' => $sectionAverage,
                'score_percentage' => $sectionAverage !== null ? round(($sectionAverage / 4) * 100, 1) : null,
                'questions' => $questionsData,
            ];
        }

        $finalScore = $supervision->final_score !== null ? (float) $supervision->final_score : null;
        $predicate = 'Belum Dinilai';
        $predicateGrade = '-';

        if ($finalScore !== null) {
            if ($finalScore >= 3.51) {
                $predicate = 'Sangat Baik';
                $predicateGrade = 'A';
            } elseif ($finalScore >= 3.00) {
                $predicate = 'Baik';
                $predicateGrade = 'B';
            } elseif ($finalScore >= 2.50) {
                $predicate = 'Cukup';
                $predicateGrade = 'C';
            } else {
                $predicate = 'Perlu Pembinaan';
                $predicateGrade = 'D';
            }
        }

        $maximumScore = (float) ($supervision->scoringPolicy?->max_score ?? 4.0);
        $finalScorePercentage = $finalScore !== null && $maximumScore > 0
            ? round(($finalScore / $maximumScore) * 100, 1)
            : null;
        $maximumRawScore = $scoredAnswerCount * 4;

        $deliveryModeLabel = match (strtoupper((string) $supervision->delivery_mode)) {
            'ONLINE', 'DARING' => 'Daring / Online',
            'HYBRID' => 'Hibrida',
            default => 'Tatap Muka / Offline',
        };

        $statusLabel = match ($supervision->status) {
            'SUBMITTED' => 'Final - Telah Disahkan',
            'DRAFT' => 'Draf',
            default => ucfirst(strtolower((string) $supervision->status)),
        };

        return [
            'supervision' => $supervision,
            'assignment' => $assignment,
            'lecturer' => $targetLecturer,
            'supervisor' => $supervisor,
            'stage' => $stage,
            'period' => $period,
            'term' => $term,
            'schedule' => $schedule,
            'course' => $course,
            'finalScore' => $finalScore !== null ? number_format($finalScore, 2) : '-',
            'finalScorePercentage' => $finalScorePercentage,
            'maximumScore' => number_format($maximumScore, 2),
            'rawScore' => number_format($totalScorePoints, 2),
            'maximumRawScore' => $maximumRawScore,
            'scoredCount' => $scoredAnswerCount,
            'answeredCount' => $answeredCount,
            'totalQuestionCount' => $totalQuestionCount,
            'scoreDistribution' => $scoreDistribution,
            'predicate' => $predicate,
            'predicateGrade' => $predicateGrade,
            'policyName' => $supervision->scoringPolicy?->name ?? 'Standar LPM',
            'policyFormula' => $supervision->scoringPolicy?->human_formula ?? 'Total skor dibagi jumlah butir',
            'deliveryModeLabel' => $deliveryModeLabel,
            'statusLabel' => $statusLabel,
            'performedAt' => $supervision->submitted_at?->copy()->timezone('Asia/Jakarta')->locale('id'),
            'structuredSections' => $structuredSections,
            'evidence' => $supervision->answers->flatMap->evidence->sortBy('id')->map(fn ($evidence) => [
                'id' => $evidence->id, 'file_name' => $evidence->original_filename,
            ])->values()->all(),
            'logoDataUri' => $this->logoDataUri(),
            'legacySnapshot' => $useSnapshot && $supervision->isSubmitted(),
            'generatedAt' => now('Asia/Jakarta')->locale('id')->translatedFormat('d F Y H:i:s').' WIB',
        ];

    }

    /**
     * Freeze only the display data; never serialize model attributes or server file paths.
     *
     * @return array<string, mixed>
     */
    public function snapshotData(Supervision $supervision): array
    {
        $data = $this->reportData($supervision, false);
        $data['assignment'] = $data['assignment']?->only(['id', 'target_lecturer_id']);
        $lecturer = $data['lecturer'];
        $data['lecturer'] = $lecturer ? [
            'name' => $lecturer->name, 'nidn' => $lecturer->nidn,
            'studyProgram' => ['name' => $lecturer->studyProgram?->name],
            'faculty' => ['name' => $lecturer->faculty?->name ?? $lecturer->studyProgram?->faculty?->name],
        ] : null;
        $data['supervisor'] = $data['supervisor']?->only(['name', 'nidn']);
        $data['stage'] = $data['stage']?->only(['id', 'stage_no', 'name']);
        $data['period'] = $data['period']?->only(['id', 'semester_display']);
        $data['term'] = null;
        $data['schedule'] = $data['schedule']?->only(['class_name', 'room']);
        $data['course'] = $data['course']?->only(['code', 'name']);
        $data['performedAt'] = $data['performedAt']?->toIso8601String();
        unset($data['supervision'], $data['logoDataUri'], $data['generatedAt'], $data['legacySnapshot']);

        return $data;
    }

    private function logoDataUri(): ?string
    {
        $path = public_path('images/logo-teknokrat-sm.png');

        return is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : null;
    }

    /**
     * Match identical indicators across form versions; keep changed or mode-specific indicators separate.
     *
     * @param  array<int, array{code: ?string, title: string, avg_score: ?float, score_percentage: ?float, questions: array<int, array<string, mixed>>}>  $stageOneSections
     * @param  array<int, array{code: ?string, title: string, avg_score: ?float, score_percentage: ?float, questions: array<int, array<string, mixed>>}>  $stageTwoSections
     * @return array<int, array{title: string, averages: array<int, ?float>, questions: array<int, array{text: string, observations: array<int, array<string, mixed>>}>}>
     */
    private function comparisonSections(array $stageOneSections, array $stageTwoSections): array
    {
        $sections = [];

        foreach ([1 => $stageOneSections, 2 => $stageTwoSections] as $stageNumber => $stageSections) {
            foreach ($stageSections as $section) {
                $sectionKey = json_encode([$section['code'], $section['title']], JSON_THROW_ON_ERROR);
                if (! isset($sections[$sectionKey])) {
                    $sections[$sectionKey] = [
                        'title' => $section['title'],
                        'averages' => [1 => null, 2 => null],
                        'questions' => [],
                    ];
                }

                $sections[$sectionKey]['averages'][$stageNumber] = $section['avg_score'];
                foreach ($section['questions'] as $question) {
                    $questionKey = json_encode([$question['code'], $question['type'], $question['text']], JSON_THROW_ON_ERROR);
                    if (! isset($sections[$sectionKey]['questions'][$questionKey])) {
                        $sections[$sectionKey]['questions'][$questionKey] = [
                            'text' => $question['text'],
                            'observations' => [],
                        ];
                    }
                    $sections[$sectionKey]['questions'][$questionKey]['observations'][$stageNumber] = $question;
                }
            }
        }

        return array_values(array_map(function (array $section): array {
            $section['questions'] = array_values($section['questions']);

            return $section;
        }, $sections));
    }
}
