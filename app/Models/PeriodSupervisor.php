<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodSupervisor extends Model
{
    protected $fillable = [
        'supervision_period_id',
        'lecturer_id',
        'selected_by',
        'is_active',
        'row_version',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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
}
