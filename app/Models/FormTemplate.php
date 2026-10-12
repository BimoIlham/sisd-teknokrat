<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormTemplate extends Model
{
    protected $fillable = [
        'code',
        'version',
        'name',
        'delivery_mode',
        'status',
        'is_demo',
        'expected_scored_count',
        'source_path',
        'source_sha256',
        'source_metadata',
        'published_by',
        'published_at',
        'row_version',
    ];

    protected $casts = [
        'version' => 'integer',
        'is_demo' => 'boolean',
        'expected_scored_count' => 'integer',
        'source_metadata' => 'array',
        'published_at' => 'datetime',
        'row_version' => 'integer',
    ];

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(FormSection::class)->orderBy('sort_order');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('sort_order');
    }

    public function stageInstruments(): HasMany
    {
        return $this->hasMany(StageInstrument::class);
    }

    public function isPublished(): bool
    {
        return $this->status === 'PUBLISHED';
    }

    public function getNameAttribute(?string $value): string
    {
        return trim(str_replace(['- DEMO', '(DEMO)', 'DEMO'], '', $value ?? ''));
    }
}
