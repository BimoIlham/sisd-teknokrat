<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class SupervisionPeriod extends Model
{
    protected $fillable = [
        'academic_term_id',
        'name',
        'starts_on',
        'ends_on',
        'status',
        'is_demo',
        'active_scheduling_rule_id',
        'created_by',
        'row_version',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_demo' => 'boolean',
        'row_version' => 'integer',
    ];

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activeRule(): BelongsTo
    {
        return $this->belongsTo(SchedulingRule::class, 'active_scheduling_rule_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(SupervisionStage::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PeriodTarget::class);
    }

    public function supervisors(): HasMany
    {
        return $this->hasMany(PeriodSupervisor::class);
    }

    public function assignments(): HasManyThrough
    {
        return $this->hasManyThrough(SupervisionAssignment::class, SupervisionStage::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(SchedulingRule::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    public function getStartDateAttribute()
    {
        return $this->starts_on;
    }

    public function getEndDateAttribute()
    {
        return $this->ends_on;
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'ACTIVE';
    }

    public function getSemesterDisplayAttribute(): string
    {
        $semester = $this->academicTerm?->semester_label ?? 'Semester Supervisi';

        return $semester.' · Tahun Ajaran '.($this->academicTerm?->academic_year ?? '-');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'ACTIVE');
    }

    public function getNameAttribute(?string $value): string
    {
        return trim(str_replace(['- DEMO', '(DEMO)', 'DEMO'], '', $value ?? ''));
    }
}
