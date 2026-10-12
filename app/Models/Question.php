<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
