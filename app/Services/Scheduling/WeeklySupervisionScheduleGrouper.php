<?php

namespace App\Services\Scheduling;

use Illuminate\Support\Collection;

class WeeklySupervisionScheduleGrouper
{
    /**
     * @param  Collection<int, \stdClass>  $schedules
     * @return Collection<int, \stdClass>
     */
    public function group(Collection $schedules): Collection
    {
        return $schedules->groupBy(fn (\stdClass $schedule): string => json_encode([
            $schedule->period_id,
            $schedule->target_lecturer_id,
            $schedule->course_id,
            $schedule->course_name,
            $schedule->class_name,
            $schedule->day_of_week,
            substr((string) $schedule->starts_local, 0, 5),
            substr((string) $schedule->ends_local, 0, 5),
            $schedule->room_name,
            $schedule->delivery_mode,
        ], JSON_THROW_ON_ERROR))
            ->map(function (Collection $group): \stdClass {
                $group = $group->sortBy('stage_no');
                $schedule = clone $group->first();
                $schedule->assignment_ids = $group->pluck('assignment_id')->unique()->values()->all();
                $schedule->stage_name = $group->pluck('stage_name')->unique()->implode(' & ');
                $schedule->stage_dates = $group->map(fn (\stdClass $item): array => [
                    'stage_no' => $item->stage_no,
                    'name' => $item->stage_name,
                    'date' => $item->occurrence_date,
                ])->unique('stage_no')->values()->all();

                return $schedule;
            })->values();
    }
}
