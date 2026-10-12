<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedDocument extends Model
{
    protected $fillable = [
        'document_type',
        'document_number',
        'supervision_period_id',
        'supervision_stage_id',
        'supervisor_lecturer_id',
        'source_revision',
        'source_hash',
        'filter_snapshot',
        'status',
        'storage_disk',
        'file_path',
        'sha256',
        'generated_by',
        'generated_at',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'source_revision' => 'integer',
        'filter_snapshot' => 'array',
        'generated_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(SupervisionPeriod::class, 'supervision_period_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(SupervisionStage::class, 'supervision_stage_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Lecturer::class, 'supervisor_lecturer_id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
