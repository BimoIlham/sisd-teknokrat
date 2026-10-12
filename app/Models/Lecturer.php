<?php

namespace App\Models;

use App\Services\ScheduleImport\ScheduleImportReferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lecturer extends Model
{
    protected $fillable = [
        'faculty_id',
        'study_program_id',
        'external_code',
        'name',
        'normalized_name',
        'identity_sources',
        'is_active',
        'row_version',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'row_version' => 'integer',
        'identity_sources' => 'array',
    ];

    protected static function booted(): void
    {
        static::saved(fn (Lecturer $lecturer) => app(ScheduleImportReferences::class)->rememberLecturer($lecturer));
        static::deleting(fn (Lecturer $lecturer) => app(ScheduleImportReferences::class)->rememberLecturer($lecturer));
        static::saving(function ($lecturer) {
            if (! $lecturer->normalized_name && $lecturer->name) {
                $lecturer->normalized_name = strtolower(trim($lecturer->name));
            }

            if ($lecturer->study_program_id && (! $lecturer->faculty_id || $lecturer->isDirty('study_program_id'))) {
                $prodi = StudyProgram::find($lecturer->study_program_id);
                if ($prodi) {
                    $lecturer->faculty_id = $prodi->faculty_id;
                }
            }
        });
    }

    public function getNidnAttribute(): ?string
    {
        return preg_match('/^\d{10}$/', (string) $this->external_code) ? $this->external_code : null;
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function studyProgram(): BelongsTo
    {
        return $this->belongsTo(StudyProgram::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function teachingSchedules(): HasMany
    {
        return $this->hasMany(TeachingSchedule::class);
    }

    public function periodTargets(): HasMany
    {
        return $this->hasMany(PeriodTarget::class);
    }

    public function periodSupervisors(): HasMany
    {
        return $this->hasMany(PeriodSupervisor::class);
    }

    public function assignmentsAsTarget(): HasMany
    {
        return $this->hasMany(SupervisionAssignment::class, 'target_lecturer_id');
    }

    public function assignmentsAsSupervisor(): HasMany
    {
        return $this->hasMany(SupervisionAssignment::class, 'supervisor_lecturer_id');
    }
}
