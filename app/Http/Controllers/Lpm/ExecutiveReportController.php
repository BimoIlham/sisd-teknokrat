<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\PeriodTarget;
use App\Models\Supervision;
use App\Models\SupervisionPeriod;
use App\Models\SupervisionStage;
use App\Services\Reporting\SupervisionResultPdfService;
use App\Services\Reporting\SupervisorNoteClassifier;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ExecutiveReportController extends Controller
{
    public function index(Request $request): View
    {
        $periods = SupervisionPeriod::query()
            ->with(['academicTerm', 'stages'])
            ->latest('starts_on')
            ->get();
        $selectedPeriod = $request->filled('period_id')
            ? $periods->firstWhere('id', $request->integer('period_id'))
            : ($periods->firstWhere('status', 'ACTIVE') ?? $periods->first());
        abort_if($request->filled('period_id') && ! $selectedPeriod, 404);
        $requestedStage = (string) $request->input('stage', 'all');
        $selectedStage = in_array($requestedStage, ['1', '2'], true) ? $requestedStage : 'all';
        $reportScope = $selectedStage === 'all' ? 'semester' : 'stage';
        $stageIds = $selectedPeriod?->stages
            ->filter(fn (SupervisionStage $stage): bool => in_array($stage->stage_no, [1, 2], true)
                && ($selectedStage === 'all' || $stage->stage_no === (int) $selectedStage))
            ->pluck('id') ?? collect();

        $submissions = Supervision::query()
            ->where('status', 'SUBMITTED')
            ->whereHas('assignment', fn ($query) => $query->where('status', '<>', 'CANCELLED'))
            ->whereHas('assignment.supervisionStage', fn ($query) => $query->whereIn('id', $stageIds))
            ->with([
                'assignment.targetLecturer:id,name,external_code',
                'assignment.supervisionStage:id,supervision_period_id,stage_no',
                'assignment.supervisorLecturer:id,name',
                'answers.question.section',
            ])
            ->get();

        $stageOneSubmissions = $submissions
            ->filter(fn (Supervision $supervision) => $supervision->assignment?->supervisionStage?->stage_no === 1)
            ->values();
        $stageTwoSubmissions = $submissions
            ->filter(fn (Supervision $supervision) => $supervision->assignment?->supervisionStage?->stage_no === 2)
            ->values();

        $targetCount = PeriodTarget::query()
            ->where('supervision_period_id', $selectedPeriod?->id)
            ->where('scope_status', 'INCLUDED')
            ->distinct('lecturer_id')
            ->count('lecturer_id');

        $hasStageOneData = $stageOneSubmissions->isNotEmpty();
        $averageStageOne = $hasStageOneData
            ? round((float) $stageOneSubmissions->avg('final_score'), 2)
            : null;
        $hasStageTwoData = $stageTwoSubmissions->isNotEmpty();
        $averageStageTwo = $hasStageTwoData
            ? round((float) $stageTwoSubmissions->avg('final_score'), 2)
            : null;
        $hasComparisonData = $hasStageOneData && $hasStageTwoData;
        $summary = [
            'total_supervised' => $targetCount,
            'submitted_count' => $submissions->count(),
            'average_score' => $submissions->isNotEmpty() ? round((float) $submissions->avg('final_score'), 2) : null,
            'count_t2' => $stageTwoSubmissions->count(),
            'avg_t1' => $averageStageOne,
            'avg_t2' => $averageStageTwo,
            'delta_avg' => $hasComparisonData ? round($averageStageTwo - $averageStageOne, 2) : null,
        ];

        $top10T1 = $this->rank($stageOneSubmissions, descending: true);
        $bottom10T1 = $this->rank($stageOneSubmissions, descending: false);
        $top10T2 = $this->rank($stageTwoSubmissions, descending: true);
        $bottom10T2 = $this->rank($stageTwoSubmissions, descending: false);
        $topImprovement = $this->improvements($stageOneSubmissions, $stageTwoSubmissions);
        $viewData = [
            'periods' => $periods,
            'selectedPeriod' => $selectedPeriod,
            'selectedStage' => $selectedStage,
            'reportScope' => $reportScope,
            'reportLabel' => $selectedPeriod
                ? $selectedPeriod->semester_display.' · '.($selectedStage === 'all' ? 'Tahap 1 dan Tahap 2' : 'Tahap '.$selectedStage)
                : 'Belum ada semester supervisi',
            'summary' => $summary,
            'top10T1' => $top10T1,
            'bottom10T1' => $bottom10T1,
            'top10T2' => $top10T2,
            'bottom10T2' => $bottom10T2,
            'top10' => $top10T2,
            'bottom10' => $bottom10T2,
            'topImprovement' => $topImprovement,
            'hasStageTwoData' => $hasStageTwoData,
            'hasStageOneData' => $hasStageOneData,
            'hasComparisonData' => $hasComparisonData,
            'hasSubmissions' => $submissions->isNotEmpty(),
        ];
        $notes = $this->findingNotes($submissions);

        $classifiedNotes = $this->classifyNotes($notes);
        $findings = $this->commonFindings($classifiedNotes);

        return view('lpm.reports.executive', $viewData + [
            'notesCount' => $notes->count(),
            'commonFindings' => $findings,
        ]);
    }

    /**
     * @param  Collection<int, Supervision>  $submissions
     * @return array<int, array{rank: int, name: string, score: float}>
     */
    private function rank(Collection $submissions, bool $descending): array
    {
        $sorted = $descending
            ? $submissions->sortByDesc('final_score')
            : $submissions->sortBy('final_score');

        return $sorted
            ->values()
            ->take(10)
            ->map(fn (Supervision $supervision, int $index) => [
                'rank' => $index + 1,
                'name' => $this->displayName($supervision->assignment?->targetLecturer?->name ?? 'Dosen'),
                'score' => round((float) $supervision->final_score, 2),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Supervision>  $stageOneSubmissions
     * @param  Collection<int, Supervision>  $stageTwoSubmissions
     * @return array<int, array{rank: int, name: string, t1: float, t2: float, delta: float, pct: string}>
     */
    private function improvements(Collection $stageOneSubmissions, Collection $stageTwoSubmissions): array
    {
        $stageOneByTarget = $stageOneSubmissions->keyBy(fn (Supervision $supervision) => $supervision->assignment->supervisionStage->supervision_period_id.':'.$supervision->assignment->target_lecturer_id
        );

        return $stageTwoSubmissions
            ->map(function (Supervision $stageTwo) use ($stageOneByTarget): ?array {
                $pairKey = $stageTwo->assignment->supervisionStage->supervision_period_id.':'.$stageTwo->assignment->target_lecturer_id;
                $stageOne = $stageOneByTarget->get($pairKey);
                if (! $stageOne) {
                    return null;
                }

                $scoreOne = round((float) $stageOne->final_score, 2);
                $scoreTwo = round((float) $stageTwo->final_score, 2);
                $delta = round($scoreTwo - $scoreOne, 2);

                if ($delta <= 0) {
                    return null;
                }

                return [
                    'name' => $this->displayName($stageTwo->assignment?->targetLecturer?->name ?? 'Dosen'),
                    't1' => $scoreOne,
                    't2' => $scoreTwo,
                    'delta' => $delta,
                    'pct' => $scoreOne > 0 ? round(($delta / $scoreOne) * 100, 1).'%' : '-',
                ];
            })
            ->filter()
            ->sortByDesc('delta')
            ->values()
            ->take(10)
            ->map(function (array $improvement, int $index): array {
                $improvement['rank'] = $index + 1;

                return $improvement;
            })
            ->all();
    }

    /**
     * @param  Collection<int, Supervision>  $submissions
     * @return Collection<int, array{lecturer_id: int|null, lecturer: string, stage: int, text: string}>
     */
    private function findingNotes(Collection $submissions): Collection
    {
        $notes = collect();
        $reports = app(SupervisionResultPdfService::class);

        foreach ($submissions as $supervision) {
            $report = $reports->reportData($supervision);
            foreach ($report['structuredSections'] ?? [] as $section) {
                foreach ($section['questions'] ?? [] as $question) {
                    $texts = [];
                    if (($question['evaluation_notes'] ?? null) !== null) {
                        foreach ($question['evaluation_notes'] as $intent => $text) {
                            $texts[] = ['text' => (string) $text, 'intent' => $intent];
                        }
                    } elseif (($question['type'] ?? '') === 'TEXTAREA' && ($question['is_answered'] ?? false)) {
                        $texts[] = ['text' => (string) ($question['answer_display'] ?? ''), 'intent' => null];
                    }
                    $texts[] = ['text' => (string) ($question['notes'] ?? ''), 'intent' => null];

                    foreach ($texts as $text) {
                        if (in_array(trim($text['text']), ['', '-', '--'], true)) {
                            continue;
                        }
                        $notes->push([
                            'lecturer_id' => $supervision->assignment?->target_lecturer_id,
                            'lecturer' => $this->displayName((string) ($report['lecturer']?->name ?? 'Dosen tidak tersedia')),
                            'stage' => (int) ($report['stage']?->stage_no ?? $supervision->assignment?->supervisionStage?->stage_no),
                            'text' => $text['text'],
                            'intent' => $text['intent'],
                        ]);
                    }
                }
            }
        }

        return $notes->sortBy([['lecturer', 'asc'], ['stage', 'asc']])->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $notes
     * @return Collection<int, array<string, mixed>>
     */
    private function classifyNotes(Collection $notes): Collection
    {
        $classifier = app(SupervisorNoteClassifier::class);

        return $notes->map(function (array $note) use ($classifier): array {
            $classifications = $classifier->classify($note['text'], $note['intent'] ?? null);
            if ($note['lecturer_id'] === null) {
                $classifications = array_map(fn (array $fragment): array => array_replace($fragment, ['status' => 'review']), $classifications);
            }

            return $note + ['classifications' => $classifications];
        });
    }

    /**
     * Include categories shared by at least two distinct lecturers in the recap.
     *
     * @param  Collection<int, array<string, mixed>>  $notes
     * @return Collection<int, array{key: string, title: string, lecturer_count: int, improvement_count: int, suggestion_count: int, lecturer_names: array<int, string>, points: array<int, string>}>
     */
    private function commonFindings(Collection $notes): Collection
    {
        $categories = app(SupervisorNoteClassifier::class)->categories();
        $findings = [];
        foreach ($notes as $note) {
            foreach ($note['classifications'] as $fragment) {
                if ($fragment['category'] === null || $note['lecturer_id'] === null
                    || ! in_array($fragment['status'], ['improvement', 'suggestion'], true)) {
                    continue;
                }
                $key = $fragment['category'];
                $lecturerId = $note['lecturer_id'];
                $findings[$key][$lecturerId] ??= [
                    'name' => $note['lecturer'],
                    'points' => [],
                    'improvement' => false,
                    'suggestion' => false,
                ];
                $findings[$key][$lecturerId][$fragment['status']] = true;
                foreach ($fragment['standard_texts'] as $statement) {
                    $findings[$key][$lecturerId]['points'][$statement] = $statement;
                }
            }
        }

        return collect($findings)->filter(fn (array $items): bool => count($items) >= 2)
            ->map(function (array $items, string $key) use ($categories): array {
                $lecturers = collect($items)->sortBy('name');

                return [
                    'key' => $key, 'title' => $categories[$key]['title'],
                    'lecturer_count' => $lecturers->count(),
                    'improvement_count' => $lecturers->where('improvement', true)->count(),
                    'suggestion_count' => $lecturers->where('suggestion', true)->count(),
                    'lecturer_names' => $lecturers->pluck('name')->values()->all(),
                    'points' => $lecturers->flatMap(fn (array $lecturer): array => array_values($lecturer['points']))
                        ->unique()->take(3)->values()->all(),
                ];
            })->sortBy([['lecturer_count', 'desc'], ['title', 'asc']])->values();
    }

    private function displayName(string $name): string
    {
        $withoutPrefix = preg_replace('/^(?:(?:Prof|Dr|Drs|Dra|Ir)\.\s*)+/iu', '', trim($name)) ?? $name;
        $withoutSuffix = preg_replace('/(?:,\s*(?:S\.[A-Z]+\.?|M\.[A-Z]+\.?|Ph\.?D\.?|MSc\.?|BSc\.?|MBA\.?|IPM|IPU|ASEAN Eng)\s*)+$/iu', '', $withoutPrefix) ?? $withoutPrefix;

        return trim($withoutSuffix);
    }
}
