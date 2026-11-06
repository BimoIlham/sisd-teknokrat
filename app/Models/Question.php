<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Question extends Model
{
    protected $fillable = [
        'form_template_id',
        'form_section_id',
        'code',
        'question_text',
        'help_text',
        'question_type',
        'is_required',
        'is_scored',
        'sort_order',
        'conditional_rule',
        'validation_rules',
        'source_reference',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_scored' => 'boolean',
        'sort_order' => 'integer',
        'conditional_rule' => 'array',
        'validation_rules' => 'array',
        'source_reference' => 'array',
    ];

    public function getQuestionCodeAttribute(): string
    {
        return $this->code ?? '';
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_template_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(FormSection::class, 'form_section_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('sort_order');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SupervisionAnswer::class);
    }

    public function usesStructuredEvaluation(): bool
    {
        return $this->question_type === 'TEXTAREA'
            && ($this->validation_rules['evaluation_notes'] ?? false) === true;
    }

    /**
     * @return list<array{value: string, label: string, numeric_score: float, sort_order: int}>
     */
    public static function defaultYesNoOptions(): array
    {
        return [
            ['value' => 'YES', 'label' => 'Ya, tersedia', 'numeric_score' => 4.0, 'sort_order' => 1],
            ['value' => 'NO', 'label' => 'Tidak tersedia', 'numeric_score' => 0.0, 'sort_order' => 2],
        ];
    }

    public function hasValidYesNoOptions(): bool
    {
        return $this->question_type === 'YES_NO'
            && $this->options->count() === 2
            && $this->options->pluck('value')->sort()->values()->all() === ['NO', 'YES'];
    }

    public function usesDefaultYesNoOptions(): bool
    {
        return $this->question_type === 'YES_NO' && ! $this->hasValidYesNoOptions();
    }

    /**
     * @return Collection<int, array{answer_value: int|string, value: string, label: string, numeric_score: float|null}>
     */
    public function answerChoices(): Collection
    {
        if ($this->usesDefaultYesNoOptions()) {
            return collect(self::defaultYesNoOptions())->map(fn (array $option): array => [
                'answer_value' => $option['value'], 'value' => $option['value'],
                'label' => $option['label'], 'numeric_score' => $option['numeric_score'],
            ]);
        }

        return $this->options->map(fn (QuestionOption $option): array => [
            'answer_value' => $option->id, 'value' => $option->value, 'label' => $option->label,
            'numeric_score' => $option->numeric_score !== null ? (float) $option->numeric_score : null,
        ]);
    }
}
