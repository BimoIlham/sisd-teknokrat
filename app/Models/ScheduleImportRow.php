<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleImportRow extends Model
{
    protected $fillable = [
        'schedule_import_id',
        'row_no',
        'source_page',
        'source_locator',
        'raw_payload',
        'normalized_payload',
        'parser_confidence',
        'validation_status',
        'validation_issues',
        'matched_lecturer_id',
        'matched_course_id',
        'row_fingerprint',
        'is_approved',
        'reviewed_by',
        'reviewed_at',
        'skip_reason',
        'row_version',
        'committed_schedule_id',
        // Aliases for seamless service compatibility
        'raw_row_data',
        'parsed_lecturer_name',
        'parsed_course_name',
        'parsed_course_code',
        'parsed_class_name',
        'parsed_day_of_week',
        'parsed_start_time',
        'parsed_end_time',
        'parsed_room',
        'parsed_delivery_mode',
        'confidence_score',
        'is_uncertain',
        'review_status',
    ];

    protected $casts = [
        'row_no' => 'integer',
        'source_page' => 'integer',
        'source_locator' => 'array',
        'raw_payload' => 'array',
        'normalized_payload' => 'array',
        'parser_confidence' => 'decimal:4',
        'validation_issues' => 'array',
        'is_approved' => 'boolean',
        'reviewed_at' => 'datetime',
        'row_version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function ($row) {
            if (! $row->row_no) {
                $max = static::where('schedule_import_id', $row->schedule_import_id)->max('row_no');
                $row->row_no = ($max ?? 0) + 1;
            }
            if (! $row->raw_payload) {
                $row->raw_payload = $row->normalized_payload ?? [];
            }
            if (! $row->normalized_payload) {
                $row->normalized_payload = $row->raw_payload ?? [];
            }
            if (! $row->validation_status) {
                $row->validation_status = $row->matched_lecturer_id ? 'VALID' : 'AMBIGUOUS';
            }
        });
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ScheduleImport::class, 'schedule_import_id');
    }

    public function matchedLecturer(): BelongsTo
    {
        return $this->belongsTo(Lecturer::class, 'matched_lecturer_id');
    }

    public function matchedCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'matched_course_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function committedSchedule(): BelongsTo
    {
        return $this->belongsTo(TeachingSchedule::class, 'committed_schedule_id');
    }

    // ─── Dynamic Accessors & Mutators ─────────────────────────

    public function getParsedLecturerNameAttribute(): ?string
    {
        return $this->normalized_payload['lecturer_name'] ?? $this->raw_payload['lecturer_name'] ?? $this->raw_payload['lecturer'] ?? null;
    }

    public function setParsedLecturerNameAttribute($value): void
    {
        $payload = $this->normalized_payload ?? [];
        $payload['lecturer_name'] = $value;
        $this->normalized_payload = $payload;
    }

    public function getParsedCourseNameAttribute(): ?string
    {
        return $this->normalized_payload['course_name'] ?? $this->raw_payload['course_name'] ?? $this->raw_payload['course'] ?? null;
    }

    public function setParsedCourseNameAttribute($value): void
    {
        $payload = $this->normalized_payload ?? [];
        $payload['course_name'] = $value;
        $this->normalized_payload = $payload;
    }

    public function getParsedCourseCodeAttribute(): ?string
    {
        return $this->normalized_payload['course_code'] ?? $this->raw_payload['course_code'] ?? null;
    }

    public function setParsedCourseCodeAttribute($value): void
    {
        $payload = $this->normalized_payload ?? [];
        $payload['course_code'] = $value;
        $this->normalized_payload = $payload;
    }

    public function getParsedClassNameAttribute(): ?string
    {
        return $this->normalized_payload['class_name'] ?? $this->raw_payload['class_name'] ?? $this->raw_payload['class'] ?? '';
    }

    public function setParsedClassNameAttribute($value): void
    {
        $payload = $this->normalized_payload ?? [];
        $payload['class_name'] = $value;
        $this->normalized_payload = $payload;
    }

    public function getParsedDayOfWeekAttribute(): int
    {
        return (int) ($this->normalized_payload['day_of_week'] ?? $this->raw_payload['day_of_week'] ?? $this->raw_payload['day'] ?? 0);
    }

    public function setParsedDayOfWeekAttribute($value): void
    {
        $payload = $this->normalized_payload ?? [];
        $payload['day_of_week'] = (int) $value;
        $this->normalized_payload = $payload;
    }

    public function getParsedStartTimeAttribute(): string
    {
        return $this->normalized_payload['start_time'] ?? $this->raw_payload['start_time'] ?? '';
    }

    public function setParsedStartTimeAttribute($value): void
    {
        $payload = $this->normalized_payload ?? [];
        $payload['start_time'] = $value;
        $this->normalized_payload = $payload;
    }

    public function getParsedEndTimeAttribute(): string
    {
        return $this->normalized_payload['end_time'] ?? $this->raw_payload['end_time'] ?? '';
    }

    public function setParsedEndTimeAttribute($value): void
    {
        $payload = $this->normalized_payload ?? [];
        $payload['end_time'] = $value;
        $this->normalized_payload = $payload;
    }

    public function getParsedRoomAttribute(): ?string
    {
        return $this->normalized_payload['room_name'] ?? $this->normalized_payload['room'] ?? $this->raw_payload['room'] ?? null;
    }

    public function getParsedRoomDisplayAttribute(): string
    {
        $room = trim((string) $this->parsed_room);
        if ($room === '') {
            return '-';
        }

        return $room;
    }

    public function setParsedRoomAttribute($value): void
    {
        $payload = $this->normalized_payload ?? [];
        $payload['room_name'] = $value;
        $payload['room'] = $value;
        $this->normalized_payload = $payload;
    }

    public function getParsedDeliveryModeAttribute(): string
    {
        return strtoupper($this->normalized_payload['delivery_mode'] ?? $this->raw_payload['delivery_mode'] ?? '');
    }

    public function setParsedDeliveryModeAttribute($value): void
    {
        $payload = $this->normalized_payload ?? [];
        $payload['delivery_mode'] = strtoupper($value);
        $this->normalized_payload = $payload;
    }

    public function setRawRowDataAttribute($value): void
    {
        $this->raw_payload = is_array($value) ? $value : ['data' => $value];
    }

    public function getConfidenceScoreAttribute(): float
    {
        return (float) ($this->parser_confidence ?? 1.0);
    }

    public function setConfidenceScoreAttribute($value): void
    {
        $this->parser_confidence = (float) $value;
    }

    public function getIsUncertainAttribute(): bool
    {
        return ! $this->matched_lecturer_id || $this->confidence_score < 0.85 || in_array($this->validation_status, ['AMBIGUOUS', 'INVALID'], true);
    }

    public function setIsUncertainAttribute($value): void
    {
        $this->validation_status = $value ? 'AMBIGUOUS' : 'VALID';
    }

    public function getReviewStatusAttribute(): string
    {
        if ($this->is_approved) {
            return 'APPROVED';
        }

        return $this->matched_lecturer_id ? 'AUTO_MATCHED' : 'UNMATCHED';
    }

    public function setReviewStatusAttribute($value): void
    {
        if ($value === 'MANUALLY_VERIFIED' || $value === 'APPROVED') {
            $this->is_approved = true;
            $this->validation_status = 'VALID';
        }
    }
}
