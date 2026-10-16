<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeachingSchedule extends Model
{
    protected $fillable = [
        'academic_term_id',
        'lecturer_id',
        'course_id',
        'faculty_id',
        'study_program_id',
        'class_name',
        'room_name',
        'room',
        'delivery_mode',
        'day_of_week',
        'starts_local',
        'ends_local',
        'start_time',
        'end_time',
        'effective_from',
        'effective_to',
        'source_import_id',
        'source_row_id',
        'source_payload',
        'fingerprint',
        'is_active',
        'row_version',
    ];

    protected $casts = [
        'source_payload' => 'array',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'day_of_week' => 'integer',
        'is_active' => 'boolean',
        'row_version' => 'integer',
    ];

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    protected static function booted(): void
    {
        static::creating(function ($schedule) {
            if (! $schedule->effective_from) {
                $term = AcademicTerm::find($schedule->academic_term_id);
                $schedule->effective_from = $term?->starts_on ?? '2026-09-01';
                $schedule->effective_to = $term?->ends_on ?? '2027-01-31';
            }
            if (! $schedule->fingerprint) {
                $schedule->fingerprint = hash('sha256', implode('|', [
                    $schedule->academic_term_id,
                    $schedule->lecturer_id,
                    $schedule->course_id,
                    $schedule->class_name,
                    $schedule->day_of_week,
                    $schedule->starts_local,
                    $schedule->ends_local,
                    $schedule->room_name ?? '',
                    uniqid('', true),
                ]));
            }
        });
    }

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(Lecturer::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function studyProgram(): BelongsTo
    {
        return $this->belongsTo(StudyProgram::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(TeachingOccurrence::class);
    }

    public function hasSameSupervisionScheduleAs(self $other): bool
    {
        return (int) $this->lecturer_id === (int) $other->lecturer_id
            && (int) $this->course_id === (int) $other->course_id
            && (int) $this->day_of_week === (int) $other->day_of_week
            && $this->normalizeTime($this->starts_local) === $this->normalizeTime($other->starts_local)
            && $this->normalizeTime($this->ends_local) === $this->normalizeTime($other->ends_local)
            && $this->normalizeText($this->class_name) === $this->normalizeText($other->class_name)
            && $this->normalizeText($this->room_name) === $this->normalizeText($other->room_name)
            && strtoupper((string) $this->delivery_mode) === strtoupper((string) $other->delivery_mode);
    }

    /** @return array{lecturer_id: int, course_id: int, day_of_week: int, starts_local: string, ends_local: string, class_name: string, room_name: string, delivery_mode: string} */
    public function supervisionIdentity(): array
    {
        return [
            'lecturer_id' => (int) $this->lecturer_id,
            'course_id' => (int) $this->course_id,
            'day_of_week' => (int) $this->day_of_week,
            'starts_local' => $this->normalizeTime($this->starts_local),
            'ends_local' => $this->normalizeTime($this->ends_local),
            'class_name' => $this->normalizeText($this->class_name),
            'room_name' => $this->normalizeText($this->room_name),
            'delivery_mode' => strtoupper((string) $this->delivery_mode),
        ];
    }

    private function normalizeTime(?string $time): string
    {
        return substr((string) $time, 0, 5);
    }

    private function normalizeText(?string $value): string
    {
        return trim((string) $value);
    }

    public function getStartTimeAttribute(): ?string
    {
        return $this->attributes['starts_local'] ?? null;
    }

    public function setStartTimeAttribute($value): void
    {
        $this->attributes['starts_local'] = $value;
    }

    public function getEndTimeAttribute(): ?string
    {
        return $this->attributes['ends_local'] ?? null;
    }

    public function setEndTimeAttribute($value): void
    {
        $this->attributes['ends_local'] = $value;
    }

    public function getRoomAttribute(): ?string
    {
        return $this->attributes['room_name'] ?? null;
    }

    public function setRoomAttribute($value): void
    {
        $this->attributes['room_name'] = $value;
    }

    public function getRoomDisplayAttribute(): string
    {
        $room = trim((string) $this->room_name);
        if ($room === '') {
            return '-';
        }

        return $room;
    }
}
