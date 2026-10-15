<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\FormSection;
use App\Models\FormTemplate;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\ScoringPolicy;
use App\Services\InstrumentEditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InstrumentController extends Controller
{
    /**
     * Display bank pertanyaan overview or redirect to offline mode.
     */
    public function index(Request $request): RedirectResponse|View
    {
        $mode = strtolower($request->query('mode', 'offline'));
        if (in_array($mode, ['offline', 'online'], true)) {
            return redirect()->route('lpm.instruments.mode', $mode);
        }

        $templates = FormTemplate::where('status', 'PUBLISHED')->withCount(['sections', 'questions'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get()->unique('delivery_mode');

        $policies = ScoringPolicy::all();

        return view('lpm.instruments.index', compact('templates', 'policies'));
    }

    /**
     * Display separated question bank for Offline or Online mode.
     */
    public function byMode(string $mode): View
    {
        $mode = strtolower($mode);
        abort_if(! in_array($mode, ['offline', 'online'], true), 404);

        $upperMode = strtoupper($mode);

        $template = FormTemplate::with([
            'sections' => fn ($q) => $q->orderBy('sort_order'),
            'sections.questions' => fn ($q) => $q->orderBy('sort_order'),
            'sections.questions.options' => fn ($q) => $q->orderBy('sort_order'),
        ])
            ->where('delivery_mode', $upperMode)
            ->where('status', 'PUBLISHED')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->firstOrFail();

        $offlineCount = Question::whereHas('template', fn ($q) => $q->where('delivery_mode', 'OFFLINE'))->count();
        $onlineCount = Question::whereHas('template', fn ($q) => $q->where('delivery_mode', 'ONLINE'))->count();
        $policies = ScoringPolicy::all();

        $isUsed = app(InstrumentEditService::class)->hasStartedSupervision($template->id);

        return view('lpm.instruments.mode', compact('template', 'mode', 'offlineCount', 'onlineCount', 'policies', 'isUsed'));
    }

    /**
     * Show detail of a specific template.
     */
    public function show(FormTemplate $template): RedirectResponse
    {
        return redirect()->route('lpm.instruments.mode', strtolower($template->delivery_mode));
    }

    /**
     * Store a newly created question in the question bank.
     */
    public function storeQuestion(Request $request, FormTemplate $template): RedirectResponse
    {
        $validated = $request->validate([
            'form_section_id' => [
                'required',
                Rule::exists('form_sections', 'id')->where('form_template_id', $template->id),
            ],
            'question_text' => 'required|string',
            'help_text' => 'nullable|string',
            'question_type' => 'required|in:SCORE,YES_NO,LINK,FILE,TEXTAREA',
            'is_scored' => 'nullable|boolean',
            'is_required' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:1',
            'options' => 'nullable|array',
            'options.*.value' => 'nullable|string|max:60',
            'options.*.label' => 'nullable|string',
            'options.*.numeric_score' => 'nullable|numeric|min:0|max:10',
        ]);

        DB::transaction(function () use ($request, $template, $validated) {
            $template = app(InstrumentEditService::class)->lockEditable($template->id);
            $prefix = $template->delivery_mode === 'OFFLINE' ? 'OFF_Q' : 'ON_Q';
            $count = $template->questions()->count() + 1;
            $code = sprintf('%s%02d', $prefix, $count);
            while ($template->questions()->where('code', $code)->exists()) {
                $count++;
                $code = sprintf('%s%02d', $prefix, $count);
            }

            // Determine sort order
            $sectionId = (int) $validated['form_section_id'];
            if (! empty($validated['sort_order'])) {
                $sortOrder = (int) $validated['sort_order'];
                $conflict = Question::where('form_section_id', $sectionId)
                    ->where('sort_order', $sortOrder)
                    ->exists();

                if ($conflict) {
                    Question::where('form_section_id', $sectionId)
                        ->where('sort_order', '>=', $sortOrder)
                        ->orderBy('sort_order', 'desc')
                        ->get()
                        ->each(fn ($q) => $q->increment('sort_order'));
                }
            } else {
                $sortOrder = ((int) Question::where('form_section_id', $sectionId)->max('sort_order')) + 1;
            }

            $isScored = $request->has('is_scored')
                ? (bool) $request->is_scored
                : ($validated['question_type'] === 'SCORE');

            $isRequired = $request->has('is_required')
                ? (bool) $request->is_required
                : true;

            $question = Question::create([
                'form_template_id' => $template->id,
                'form_section_id' => $sectionId,
                'code' => $code,
                'question_text' => $validated['question_text'],
                'help_text' => $validated['help_text'] ?? null,
                'question_type' => $validated['question_type'],
                'is_scored' => $isScored,
                'is_required' => $isRequired,
                'sort_order' => $sortOrder,
            ]);

            // Save options based on question type
            if ($validated['question_type'] === 'SCORE') {
                if (! empty($validated['options']) && is_array($validated['options'])) {
                    $optOrder = 1;
                    foreach ($validated['options'] as $opt) {
                        if (! empty($opt['label'])) {
                            QuestionOption::create([
                                'question_id' => $question->id,
                                'value' => (string) ($opt['value'] ?? ($optOrder - 1)),
                                'numeric_score' => isset($opt['numeric_score']) ? (float) $opt['numeric_score'] : ($optOrder - 1),
                                'label' => $opt['label'],
                                'sort_order' => $optOrder++,
                            ]);
                        }
                    }
                } else {
                    // Default standard 5-point Likert scale (0 - 4)
                    $defaultOptions = [
                        ['value' => '0', 'score' => 0.0, 'label' => '0 - Sangat Kurang / Tidak Dilakukan'],
                        ['value' => '1', 'score' => 1.0, 'label' => '1 - Kurang Memenuhi'],
                        ['value' => '2', 'score' => 2.0, 'label' => '2 - Cukup Memenuhi'],
                        ['value' => '3', 'score' => 3.0, 'label' => '3 - Baik / Sesuai Standar'],
                        ['value' => '4', 'score' => 4.0, 'label' => '4 - Sangat Baik / Melampaui Standar'],
                    ];
                    foreach ($defaultOptions as $idx => $opt) {
                        QuestionOption::create([
                            'question_id' => $question->id,
                            'value' => $opt['value'],
                            'numeric_score' => $opt['score'],
                            'label' => $opt['label'],
                            'sort_order' => $idx + 1,
                        ]);
                    }
                }
            } elseif ($validated['question_type'] === 'YES_NO') {
                if (! empty($validated['options']) && is_array($validated['options'])) {
                    $optOrder = 1;
                    foreach ($validated['options'] as $opt) {
                        if (! empty($opt['label'])) {
                            QuestionOption::create([
                                'question_id' => $question->id,
                                'value' => (string) ($opt['value'] ?? ($optOrder === 1 ? 'YES' : 'NO')),
                                'numeric_score' => isset($opt['numeric_score']) ? (float) $opt['numeric_score'] : null,
                                'label' => $opt['label'],
                                'sort_order' => $optOrder++,
                            ]);
                        }
                    }
                } else {
                    QuestionOption::create([
                        'question_id' => $question->id,
                        'value' => 'YES',
                        'numeric_score' => 4.0,
                        'label' => 'Ya, tersedia',
                        'sort_order' => 1,
                    ]);
                    QuestionOption::create([
                        'question_id' => $question->id,
                        'value' => 'NO',
                        'numeric_score' => 0.0,
                        'label' => 'Tidak tersedia',
                        'sort_order' => 2,
                    ]);
                }
            }

            // Sync expected scored count
            $template->update([
                'expected_scored_count' => $template->questions()->where('is_scored', true)->count(),
            ]);
        });

        return redirect()->back()->with('success', 'Butir pertanyaan berhasil ditambahkan ke bank soal.');
    }

    /**
     * Update an existing question and its options.
     */
    public function updateQuestion(Request $request, Question $question): RedirectResponse
    {
        $template = $question->template;

        $validated = $request->validate([
            'form_section_id' => [
                'required',
                Rule::exists('form_sections', 'id')->where('form_template_id', $template->id),
            ],
            'question_text' => 'required|string',
            'help_text' => 'nullable|string',
            'question_type' => 'required|in:SCORE,YES_NO,LINK,FILE,TEXTAREA',
            'is_scored' => 'nullable|boolean',
            'is_required' => 'nullable|boolean',
            'sort_order' => 'required|integer|min:1',
            'options' => 'nullable|array',
            'options.*.id' => 'nullable|integer',
            'options.*.value' => 'nullable|string|max:60',
            'options.*.label' => 'nullable|string',
            'options.*.numeric_score' => 'nullable|numeric|min:0|max:10',
        ]);

        DB::transaction(function () use ($request, $question, $template, $validated) {
            $template = app(InstrumentEditService::class)->lockEditable($template->id);
            $question->refresh();
            $newSectionId = (int) $validated['form_section_id'];
            $newSortOrder = (int) $validated['sort_order'];
            $oldSectionId = (int) $question->form_section_id;
            $oldSortOrder = (int) $question->sort_order;

            // Handle sort order conflict if section or sort order changed
            if ($newSectionId !== $oldSectionId || $newSortOrder !== $oldSortOrder) {
                $conflict = Question::where('form_section_id', $newSectionId)
                    ->where('id', '!=', $question->id)
                    ->where('sort_order', $newSortOrder)
                    ->exists();

                if ($conflict) {
                    $question->update(['sort_order' => 999999]);
                    if ($newSectionId === $oldSectionId && $newSortOrder < $oldSortOrder) {
                        Question::where('form_section_id', $newSectionId)
                            ->where('id', '!=', $question->id)
                            ->whereBetween('sort_order', [$newSortOrder, $oldSortOrder])
                            ->orderBy('sort_order', 'desc')
                            ->get()
                            ->each(fn ($q) => $q->increment('sort_order'));
                    } else {
                        Question::where('form_section_id', $newSectionId)
                            ->where('id', '!=', $question->id)
                            ->where('sort_order', '>=', $newSortOrder)
                            ->orderBy('sort_order', 'desc')
                            ->get()
                            ->each(fn ($q) => $q->increment('sort_order'));
                    }
                }
            }

            $isScored = $request->has('is_scored')
                ? (bool) $request->is_scored
                : ($validated['question_type'] === 'SCORE');

            $isRequired = $request->has('is_required')
                ? (bool) $request->is_required
                : false;

            $question->update([
                'form_section_id' => $newSectionId,
                'question_text' => $validated['question_text'],
                'help_text' => $validated['help_text'] ?? null,
                'question_type' => $validated['question_type'],
                'is_scored' => $isScored,
                'is_required' => $isRequired,
                'sort_order' => $newSortOrder,
            ]);

            // Sync options
            if (in_array($validated['question_type'], ['SCORE', 'YES_NO'], true)) {
                if (! empty($validated['options']) && is_array($validated['options'])) {
                    $keepIds = [];
                    $order = 1;
                    foreach ($validated['options'] as $optData) {
                        if (empty($optData['label'])) {
                            continue;
                        }

                        $optId = $optData['id'] ?? null;
                        $existingOption = $optId ? QuestionOption::where('question_id', $question->id)->find($optId) : null;

                        if ($existingOption) {
                            $existingOption->update([
                                'value' => (string) ($optData['value'] ?? ($order - 1)),
                                'numeric_score' => isset($optData['numeric_score']) && $optData['numeric_score'] !== '' ? (float) $optData['numeric_score'] : null,
                                'label' => $optData['label'],
                                'sort_order' => $order++,
                            ]);
                            $keepIds[] = $existingOption->id;
                        } else {
                            $created = QuestionOption::create([
                                'question_id' => $question->id,
                                'value' => (string) ($optData['value'] ?? ($order - 1)),
                                'numeric_score' => isset($optData['numeric_score']) && $optData['numeric_score'] !== '' ? (float) $optData['numeric_score'] : null,
                                'label' => $optData['label'],
                                'sort_order' => $order++,
                            ]);
                            $keepIds[] = $created->id;
                        }
                    }

                    // Delete options removed by user if they don't have answers
                    $removedOptions = $question->options()->whereNotIn('id', $keepIds)->get();
                    foreach ($removedOptions as $rem) {
                        if (! $rem->answers()->exists()) {
                            $rem->delete();
                        }
                    }
                }
            } else {
                // If question type changed to non-option type, delete options without answers
                foreach ($question->options as $opt) {
                    if (! $opt->answers()->exists()) {
                        $opt->delete();
                    }
                }
            }

            // Sync expected scored count
            $template->update([
                'expected_scored_count' => $template->questions()->where('is_scored', true)->count(),
            ]);
        });

        return redirect()->back()->with('success', 'Butir pertanyaan berhasil diperbarui.');
    }

    /**
     * Delete a question from the question bank.
     */
    public function destroyQuestion(Question $question): RedirectResponse
    {
        $template = $question->template;
        DB::transaction(function () use ($question, $template) {
            $template = app(InstrumentEditService::class)->lockEditable($template->id);
            $question->options()->delete();
            $question->delete();

            $template->update([
                'expected_scored_count' => $template->questions()->where('is_scored', true)->count(),
            ]);
        });

        return redirect()->back()->with('success', 'Butir pertanyaan berhasil dihapus dari bank soal.');
    }

    /**
     * Store a new section for the template.
     */
    public function storeSection(Request $request, FormTemplate $template): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:1',
        ]);

        DB::transaction(function () use ($template, $validated): void {
            $template = app(InstrumentEditService::class)->lockEditable($template->id);
            $sortOrder = $validated['sort_order'] ?? (((int) $template->sections()->max('sort_order')) + 1);

            $prefix = $template->delivery_mode === 'OFFLINE' ? 'SEC_OFF_' : 'SEC_ON_';
            $sectionNumber = $template->sections()->count() + 1;
            $code = $prefix.$sectionNumber;
            while ($template->sections()->where('code', $code)->exists()) {
                $sectionNumber++;
                $code = $prefix.$sectionNumber;
            }

            $conflict = FormSection::where('form_template_id', $template->id)
                ->where('sort_order', $sortOrder)
                ->exists();

            if ($conflict) {
                FormSection::where('form_template_id', $template->id)
                    ->where('sort_order', '>=', $sortOrder)
                    ->orderBy('sort_order', 'desc')
                    ->get()
                    ->each(fn ($s) => $s->increment('sort_order'));
            }

            FormSection::create([
                'form_template_id' => $template->id,
                'code' => $code,
                'title' => $validated['title'],
                'subtitle' => $validated['subtitle'] ?? null,
                'sort_order' => $sortOrder,
            ]);

        });

        return redirect()->back()->with('success', "Bagian [{$validated['title']}] berhasil ditambahkan.");
    }

    public function updateSection(Request $request, FormSection $section): RedirectResponse
    {
        $validated = $request->validate(['title' => 'required|string|max:255', 'subtitle' => 'nullable|string', 'sort_order' => 'required|integer|min:1']);
        DB::transaction(function () use ($section, $validated): void {
            app(InstrumentEditService::class)->lockEditable($section->form_template_id);
            $section->refresh();
            if ($section->template->sections()->whereKeyNot($section->id)->where('sort_order', $validated['sort_order'])->exists()) {
                throw ValidationException::withMessages(['sort_order' => 'Urutan bagian sudah digunakan.']);
            }
            $section->update($validated);
        });

        return redirect()->back()->with('success', 'Bagian berhasil diperbarui.');
    }

    public function destroySection(FormSection $section): RedirectResponse
    {
        DB::transaction(function () use ($section): void {
            $template = app(InstrumentEditService::class)->lockEditable($section->form_template_id);
            $section->delete();
            $template->update(['expected_scored_count' => $template->questions()->where('is_scored', true)->count()]);
        });

        return redirect()->back()->with('success', 'Bagian berhasil dihapus.');
    }
}
