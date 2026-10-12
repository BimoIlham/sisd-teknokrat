<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'supervision_assignment_id',
        'actor_user_id',
        'action',
        'reason',
        'request_id',
        'old_payload',
        'new_payload',
        'created_at',
    ];

    protected $casts = [
        'old_payload' => 'array',
        'new_payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SupervisionAssignment::class, 'supervision_assignment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
