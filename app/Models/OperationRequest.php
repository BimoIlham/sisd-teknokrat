<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationRequest extends Model
{
    protected $fillable = [
        'actor_user_id',
        'operation',
        'idempotency_key',
        'request_hash',
        'status',
        'resource_type',
        'resource_id',
        'response_payload',
        'error_code',
        'expires_at',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'expires_at' => 'datetime',
        'resource_id' => 'integer',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
