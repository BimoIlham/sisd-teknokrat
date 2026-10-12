<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodTarget extends Model
{
    protected $fillable = [
        'supervision_period_id',
        'lecturer_id',
        'scope_status',
        'reason',
        'selected_by',
        'row_version',
    ];

    protected $casts = [
        'row_version' => 'integer',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(SupervisionPeriod::class, 'supervision_period_id');
    }

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(Lecturer::class);
    }

    public function selector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by');
    }

    public function getIsIncludedAttribute(): bool
    {
        return $this->scope_status === 'INCLUDED';
    }

    public function setIsIncludedAttribute($val): void
    {
        $this->attributes['scope_status'] = $val ? 'INCLUDED' : 'EXCLUDED';
    }

    public function scopeIncluded($query)
    {
        return $query->where('scope_status', 'INCLUDED');
    }
}
