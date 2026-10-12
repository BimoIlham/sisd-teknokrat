<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicTerm extends Model
{
    protected $fillable = [
        'semester',
        'academic_year',
        'starts_on',
        'ends_on',
        'timezone',
        'row_version',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'row_version' => 'integer',
    ];

    public function getNameAttribute(): string
    {
        return $this->full_semester_label;
    }

    public function getFormattedNameAttribute(): string
    {
        return $this->full_semester_label;
    }

    public function getTermCodeAttribute(): string
    {
        $yearClean = str_replace('/', '', $this->academic_year);
        $semCode = $this->semester === 'GANJIL' ? '1' : '2';

        return "{$yearClean}-{$semCode}";
    }

    public function getIsCurrentAttribute(): bool
    {
        $activePeriod = SupervisionPeriod::where('status', 'ACTIVE')->first();
        if ($activePeriod && $activePeriod->academic_term_id === $this->id) {
            return true;
        }

        return false;
    }

    public function getSemesterLabelAttribute(): string
    {
        return $this->semester === 'GANJIL'
            ? 'Semester Ganjil'
            : 'Semester Genap';
    }

    public function getSemesterShortLabelAttribute(): string
    {
        return $this->semester === 'GANJIL'
            ? 'Ganjil'
            : 'Genap';
    }

    public function getFullSemesterLabelAttribute(): string
    {
        return $this->semester_label.' · Tahun Ajaran '.$this->academic_year;
    }

    public function getSelectDisplayAttribute(): string
    {
        return $this->full_semester_label;
    }

    public function teachingSchedules(): HasMany
    {
        return $this->hasMany(TeachingSchedule::class);
    }

    public function nonTeachingDates(): HasMany
    {
        return $this->hasMany(AcademicNonTeachingDate::class);
    }

    public function supervisionPeriods(): HasMany
    {
        return $this->hasMany(SupervisionPeriod::class);
    }
}
