<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $fillable = [
        'code',
        'name',
        'normalized_name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function ($course) {
            if (! $course->normalized_name && $course->name) {
                $course->normalized_name = strtolower(trim($course->name));
            }
        });

        static::updating(function ($course) {
            if (! $course->normalized_name && $course->name) {
                $course->normalized_name = strtolower(trim($course->name));
            }
        });
    }

    public function teachingSchedules(): HasMany
    {
        return $this->hasMany(TeachingSchedule::class);
    }
}
