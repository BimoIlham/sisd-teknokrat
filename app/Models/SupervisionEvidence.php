<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupervisionEvidence extends Model
{
    protected $fillable = [
        'supervision_answer_id',
        'uploaded_by',
        'evidence_type',
        'storage_disk',
        'file_path',
        'external_url',
        'original_filename',
        'mime_type',
        'size_bytes',
        'sha256',
        'status',
        'validated_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'validated_at' => 'datetime',
    ];

    public function answer(): BelongsTo
    {
        return $this->belongsTo(SupervisionAnswer::class, 'supervision_answer_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isReady(): bool
    {
        return $this->status === 'READY';
    }
}
