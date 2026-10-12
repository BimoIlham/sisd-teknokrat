<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchedulingRule extends Model
{
    protected $fillable = [
        'supervision_period_id',
        'version',
        'schema_version',
        'config',
        'status',
        'is_demo',
        'created_by',
        'approved_by',
        'approved_at',
        'approval_note',
    ];

    protected $casts = [
        'version' => 'integer',
        'config' => 'array',
        'is_demo' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(SupervisionPeriod::class, 'supervision_period_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }
}
