<?php

namespace App\Services;

use App\Models\FormTemplate;
use App\Models\Supervision;
use App\Services\Scheduling\SchedulingLockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class InstrumentEditService
{
    public function lockEditable(int $templateId): FormTemplate
    {
        app(SchedulingLockService::class)->state();
        $template = FormTemplate::query()->lockForUpdate()->findOrFail($templateId);
        $currentTemplateId = FormTemplate::where('delivery_mode', $template->delivery_mode)
            ->where('status', 'PUBLISHED')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->value('id');
        if ($template->status !== 'PUBLISHED' || $template->id !== $currentTemplateId) {
            throw ValidationException::withMessages([
                'instrument' => 'Instrumen lama tidak dapat diubah.',
            ]);
        }
        if ($this->hasStartedSupervision($templateId)) {
            throw ValidationException::withMessages([
                'instrument' => 'Instrumen sudah digunakan dalam penilaian dan tidak dapat diubah.',
            ]);
        }

        return $template;
    }

    public function hasStartedSupervision(int $templateId): bool
    {
        return Supervision::where('form_template_id', $templateId)
            ->where(fn (Builder $query): Builder => $query->whereNotNull('draft_started_at')->orWhere('status', 'SUBMITTED')->orWhereHas('answers'))
            ->exists();
    }
}
