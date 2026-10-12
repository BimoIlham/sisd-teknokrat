<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduleImport extends Model
{
    protected $fillable = [
        'academic_term_id',
        'uploaded_by',
        'uploaded_by_user_id',
        'original_filename',
        'file_name',
        'storage_disk',
        'stored_path',
        'file_path',
        'sha256',
        'checksum_sha256',
        'size_bytes',
        'file_size',
        'mime_type',
        'parser_name',
        'parser_version',
        'contract_version',
        'status',
        'row_version',
        'page_count',
        'total_rows',
        'error_code',
        'error_message',
        'parsed_at',
        'committed_at',
        'committed_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'row_version' => 'integer',
        'page_count' => 'integer',
        'parsed_at' => 'datetime',
        'committed_at' => 'datetime',
    ];

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function committer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ScheduleImportRow::class)->orderBy('row_no');
    }

    public function isCommitted(): bool
    {
        return $this->status === 'COMMITTED';
    }

    public function getFileNameAttribute(): string
    {
        return $this->original_filename ?? 'Jadwal.pdf';
    }

    public function setFileNameAttribute($val): void
    {
        $this->attributes['original_filename'] = $val;
    }

    public function getFilePathAttribute(): ?string
    {
        return $this->stored_path;
    }

    public function setFilePathAttribute($val): void
    {
        $this->attributes['stored_path'] = $val;
    }

    public function getFileSizeAttribute(): ?int
    {
        return $this->size_bytes;
    }

    public function setFileSizeAttribute($val): void
    {
        $this->attributes['size_bytes'] = (int) $val;
    }

    public function setUploadedByUserIdAttribute($val): void
    {
        $this->attributes['uploaded_by'] = $val;
    }

    public function setChecksumSha256Attribute($val): void
    {
        $this->attributes['sha256'] = $val;
    }

    public function getTotalRowsAttribute(): int
    {
        return $this->rows()->count();
    }

    public function setTotalRowsAttribute($val): void
    {
        $this->attributes['page_count'] = (int) $val;
    }
}
