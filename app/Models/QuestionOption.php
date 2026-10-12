<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionOption extends Model
{
    protected $fillable = [
        'question_id',
        'value',
        'numeric_score',
        'label',
        'sort_order',
    ];

    protected $casts = [
        'numeric_score' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function getOptionTextAttribute(): ?string
    {
        return $this->label;
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SupervisionAnswer::class);
    }
}
