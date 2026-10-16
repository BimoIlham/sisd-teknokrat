<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeachingOccurrence extends Model
{
    protected $fillable = [
        'teaching_schedule_id',
        'original_date',
        'occurs_on',
        'starts_at',
        'ends_at',
        'status',
        'is_exception',
        'exception_reason',
        'identity_snapshot',
        'row_version',
    ];

    protected $casts = [
        'original_date' => 'date',
        'occurs_on' => 'date',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_exception' => 'boolean',
        'identity_snapshot' => 'array',
        'row_version' => 'integer',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TeachingSchedule::class, 'teaching_schedule_id');
    }

    public function teachingSchedule(): BelongsTo
    {
        return $this->schedule();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SupervisionAssignment::class);
    }

    public function getOccurrenceDateAttribute()
    {
        return $this->occurs_on;
    }

    public function getDayOfWeekAttribute(): int
    {
        return $this->schedule?->day_of_week ?? (int) $this->occurs_on->format('N');
    }

    public function getStartTimeLocalAttribute(): string
    {
        return $this->starts_at ? $this->starts_at->timezone('Asia/Jakarta')->format('H:i') : '08:00';
    }

    public function getEndTimeLocalAttribute(): string
    {
        return $this->ends_at ? $this->ends_at->timezone('Asia/Jakarta')->format('H:i') : '09:40';
    }

    public function hasSameSupervisionSlotAs(self $other): bool
    {
        $this->loadMissing('teachingSchedule');
        $other->loadMissing('teachingSchedule');

        return $this->teachingSchedule
            && $other->teachingSchedule
            && $this->teachingSchedule->hasSameSupervisionScheduleAs($other->teachingSchedule)
            && $this->start_time_local === $other->start_time_local
            && $this->end_time_local === $other->end_time_local;
    }
}
