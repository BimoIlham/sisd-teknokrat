<?php

namespace App\Services\Academic;

use App\Models\AcademicTerm;
use App\Models\TeachingOccurrence;
use App\Models\TeachingSchedule;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class OccurrenceGeneratorService
{
    /**
     * Generate teaching occurrences for a schedule within an optional date range.
     * If date range not provided, uses schedule effective range clipped by term dates.
     */
    public function generateForSchedule(TeachingSchedule $schedule, ?string $rangeStart = null, ?string $rangeEnd = null): int
    {
        $term = $schedule->academicTerm;
        $tz = $term->timezone ?: 'Asia/Jakarta';

        $startDate = Carbon::parse($rangeStart ?: max($schedule->effective_from->toDateString(), $term->starts_on->toDateString()));
        $endDate = Carbon::parse($rangeEnd ?: min($schedule->effective_to->toDateString(), $term->ends_on->toDateString()));

        if ($startDate->gt($endDate)) {
            return 0;
        }

        // Fetch non-teaching dates for this term
        $nonTeachingDates = $term->nonTeachingDates()->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->flip();

        $period = CarbonPeriod::create($startDate, $endDate);
        $count = 0;

        $identitySnapshot = [
            'lecturer_id' => $schedule->lecturer_id,
            'lecturer_name' => $schedule->lecturer?->name,
            'course_id' => $schedule->course_id,
            'course_name' => $schedule->course?->name,
            'class_name' => $schedule->class_name,
            'faculty_id' => $schedule->faculty_id,
            'faculty_name' => $schedule->faculty?->name,
            'study_program_id' => $schedule->study_program_id,
            'study_program_name' => $schedule->studyProgram?->name,
            'delivery_mode' => $schedule->delivery_mode,
            'room_name' => $schedule->room_name,
            'timezone' => $tz,
        ];

        foreach ($period as $date) {
            // ISO-8601 day of week: 1 (Monday) to 7 (Sunday)
            $dayOfWeek = (int) $date->format('N');
            if ($dayOfWeek !== $schedule->day_of_week) {
                continue;
            }

            $dateStr = $date->toDateString();

            // Check if holiday/non-teaching date
            if (isset($nonTeachingDates[$dateStr])) {
                continue;
            }

            // Combine date with starts_local and ends_local in term timezone
            $startsAtLocal = Carbon::parse("{$dateStr} {$schedule->starts_local}", $tz);
            $endsAtLocal = Carbon::parse("{$dateStr} {$schedule->ends_local}", $tz);

            // Convert to UTC for database storage
            $startsAtUtc = $startsAtLocal->copy()->setTimezone('UTC');
            $endsAtUtc = $endsAtLocal->copy()->setTimezone('UTC');

            TeachingOccurrence::updateOrCreate(
                [
                    'teaching_schedule_id' => $schedule->id,
                    'original_date' => $dateStr,
                ],
                [
                    'occurs_on' => $dateStr,
                    'starts_at' => $startsAtUtc,
                    'ends_at' => $endsAtUtc,
                    'status' => 'SCHEDULED',
                    'identity_snapshot' => $identitySnapshot,
                ]
            );

            $count++;
        }

        return $count;
    }

    /**
     * Generate occurrences for all active schedules in a term
     */
    public function generateForTerm(AcademicTerm $term): int
    {
        $schedules = $term->teachingSchedules()->where('is_active', true)->get();
        $total = 0;
        foreach ($schedules as $schedule) {
            $total += $this->generateForSchedule($schedule);
        }

        return $total;
    }
}
