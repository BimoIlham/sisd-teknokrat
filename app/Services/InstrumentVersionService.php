<?php

namespace App\Services;

use App\Models\FormTemplate;
use App\Models\Supervision;
use App\Models\SystemAudit;
use App\Services\Scheduling\SchedulingLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InstrumentVersionService
{
    public function lockEditable(int $templateId): FormTemplate
    {
        app(SchedulingLockService::class)->state();
        $template = FormTemplate::query()->lockForUpdate()->findOrFail($templateId);
        if (Supervision::where('form_template_id', $templateId)->exists()) {
            throw ValidationException::withMessages([
                'instrument' => 'Instrumen sudah digunakan dalam penilaian. Buat versi baru untuk melakukan perubahan.',
            ]);
        }

        return $template;
    }

    public function duplicate(FormTemplate $source): FormTemplate
    {
        return DB::transaction(function () use ($source): FormTemplate {
            app(SchedulingLockService::class)->state();
            $family = FormTemplate::where('code', $source->code)->orderBy('id')->lockForUpdate()->get();
            $source = $family->firstWhere('id', $source->id);
            abort_unless($source, 404);
            $source->load('sections.questions.options');
            $copy = $source->replicate(['published_by', 'published_at']);
            $copy->version = $family->max('version') + 1;
            $copy->status = 'DRAFT';
            $copy->row_version = 1;
            $copy->save();

            foreach ($source->sections as $section) {
                $newSection = $section->replicate();
                $newSection->form_template_id = $copy->id;
                $newSection->save();
                foreach ($section->questions as $question) {
                    $newQuestion = $question->replicate();
                    $newQuestion->form_template_id = $copy->id;
                    $newQuestion->form_section_id = $newSection->id;
                    $newQuestion->save();
                    foreach ($question->options as $option) {
                        $newOption = $option->replicate();
                        $newOption->question_id = $newQuestion->id;
                        $newOption->save();
                    }
                }
            }

            SystemAudit::create([
                'user_id' => auth()->id(),
                'event' => 'INSTRUMENT_VERSION_CREATED',
                'entity_type' => FormTemplate::class,
                'entity_id' => $copy->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'new_values' => [
                    'source_template_id' => $source->id,
                    'source_version' => $source->version,
                    'version' => $copy->version,
                    'status' => $copy->status,
                ],
            ]);

            return $copy->unsetRelations();
        }, 3);
    }
}
