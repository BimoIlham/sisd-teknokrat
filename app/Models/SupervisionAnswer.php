<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupervisionAnswer extends Model
{
    protected $fillable = [
        'supervision_id',
        'question_id',
        'question_option_id',
        'boolean_value',
        'text_value',
        'notes',
        'evaluation_notes',
        'numeric_score',
        'is_applicable',
        'answer_snapshot',
    ];

    protected $casts = [
        'boolean_value' => 'boolean',
        'numeric_score' => 'decimal:2',
        'is_applicable' => 'boolean',
        'answer_snapshot' => 'array',
        'evaluation_notes' => 'array',
    ];

    public function supervision(): BelongsTo
    {
        return $this->belongsTo(Supervision::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(QuestionOption::class, 'question_option_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(SupervisionEvidence::class);
    }

    public function getSelectedOptionIdAttribute(): ?int
    {
        return $this->attributes['question_option_id'] ?? null;
    }

    public function setSelectedOptionIdAttribute($value): void
    {
        $this->attributes['question_option_id'] = $value;
    }
}
