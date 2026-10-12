<?php

namespace Tests\Unit;

use App\Services\Scheduling\WeeklySupervisionScheduleGrouper;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class WeeklySupervisionScheduleGrouperTest extends TestCase
{
    public function test_identical_weekly_assignments_are_one_card_with_both_stages_and_dates(): void
    {
        $stageOne = $this->schedule();
        $stageTwo = $this->schedule([
            'assignment_id' => 2,
            'stage_no' => 2,
            'stage_name' => 'Tahap 2',
            'occurrence_date' => '2027-01-20',
        ]);

        $grouped = (new WeeklySupervisionScheduleGrouper)->group(collect([$stageTwo, $stageOne]));

        $this->assertCount(1, $grouped);
        $this->assertSame([1, 2], $grouped->first()->assignment_ids);
        $this->assertSame('Tahap 1 & Tahap 2', $grouped->first()->stage_name);
        $this->assertSame([
            ['stage_no' => 1, 'name' => 'Tahap 1', 'date' => '2026-10-28'],
            ['stage_no' => 2, 'name' => 'Tahap 2', 'date' => '2027-01-20'],
        ], $grouped->first()->stage_dates);
        $this->assertSame('Tahap 1', $stageOne->stage_name);
    }

    #[TestWith(['period_id', 2])]
    #[TestWith(['target_lecturer_id', 2])]
    #[TestWith(['course_id', 2])]
    #[TestWith(['course_name', 'Mata Kuliah Berbeda'])]
    #[TestWith(['class_name', 'MNJ 25 B'])]
    #[TestWith(['day_of_week', 4])]
    #[TestWith(['starts_local', '11:00'])]
    #[TestWith(['ends_local', '10:00'])]
    #[TestWith(['room_name', 'Lab 2'])]
    #[TestWith(['delivery_mode', 'ONLINE'])]
    public function test_different_weekly_assignments_remain_separate(string $field, int|string $value): void
    {
        $grouped = (new WeeklySupervisionScheduleGrouper)->group(collect([
            $this->schedule(),
            $this->schedule([
                'assignment_id' => 2,
                'stage_no' => 2,
                'stage_name' => 'Tahap 2',
                $field => $value,
            ]),
        ]));

        $this->assertCount(2, $grouped);
    }

    public function test_empty_schedule_has_no_cards(): void
    {
        $this->assertCount(0, (new WeeklySupervisionScheduleGrouper)->group(collect()));
    }

    /**
     * @param  array{assignment_id?: int, stage_no?: int, stage_name?: string, occurrence_date?: string, period_id?: int, target_lecturer_id?: int, course_id?: int, course_name?: string, class_name?: string, day_of_week?: int, starts_local?: string, ends_local?: string, room_name?: string, delivery_mode?: string}  $overrides
     */
    private function schedule(array $overrides = []): \stdClass
    {
        return (object) array_replace([
            'assignment_id' => 1,
            'period_id' => 1,
            'target_lecturer_id' => 1,
            'target_lecturer_name' => 'Dosen yang Sama',
            'course_id' => 1,
            'course_name' => 'Desain Grafis Bisnis II',
            'class_name' => 'MNJ 25 A',
            'day_of_week' => 3,
            'starts_local' => '09:00',
            'ends_local' => '10:40',
            'room_name' => 'Lab 1 GSG',
            'delivery_mode' => 'OFFLINE',
            'stage_no' => 1,
            'stage_name' => 'Tahap 1',
            'occurrence_date' => '2026-10-28',
        ], $overrides);
    }
}
