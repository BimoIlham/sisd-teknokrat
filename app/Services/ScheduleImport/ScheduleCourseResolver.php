<?php

namespace App\Services\ScheduleImport;

use App\Models\Course;

class ScheduleCourseResolver
{
    public function resolve(string $name, ?string $code = null): Course
    {
        $normalizedName = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        if ($code) {
            return Course::firstOrCreate(['code' => $code], ['name' => $name, 'normalized_name' => $normalizedName, 'is_active' => true]);
        }

        return Course::firstOrCreate(['normalized_name' => $normalizedName], [
            'code' => 'MK-'.strtoupper(substr(hash('sha256', $normalizedName), 0, 12)),
            'name' => $name,
            'is_active' => true,
        ]);
    }
}
