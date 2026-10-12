<?php

namespace App\Services\Reporting;

use App\Models\Lecturer;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Models\TeachingOccurrence;
use Barryvdh\DomPDF\Facade\Pdf;

class SchedulePdfService
{
    /**
     * Generate personal combined teaching and supervision schedule PDF for a lecturer.
     */
    public function generate(Lecturer $lecturer, SupervisionPeriod $period): \Barryvdh\DomPDF\PDF
    {
        // 1. Fetch teaching occurrences for this lecturer during the period
        $periodStart = $period->start_date;
        $periodEnd = $period->end_date;

        $teachingOccurrences = TeachingOccurrence::whereHas('teachingSchedule', function ($q) use ($lecturer) {
            $q->where('lecturer_id', $lecturer->id);
        })
            ->whereBetween('occurs_on', [$periodStart, $periodEnd])
            ->with(['teachingSchedule.course', 'teachingSchedule.studyProgram'])
            ->get();

        // 2. Fetch supervision duties assigned to this lecturer
        $supervisionAssignments = SupervisionAssignment::where('supervisor_lecturer_id', $lecturer->id)
            ->whereHas('supervisionStage', function ($q) use ($period) {
                $q->where('supervision_period_id', $period->id);
            })
            ->where('status', '<>', 'CANCELLED')
            ->with(['teachingOccurrence.teachingSchedule.course', 'teachingOccurrence.teachingSchedule.studyProgram', 'targetLecturer', 'supervisionStage'])
            ->get();

        // 3. Merge events and group by Week / Date
        $allEvents = collect();

        foreach ($teachingOccurrences as $occ) {
            $allEvents->push([
                'type' => 'TEACHING',
                'date' => $occ->occurrence_date,
                'day_name' => $this->getDayName($occ->day_of_week),
                'start_time' => $occ->start_time_local,
                'end_time' => $occ->end_time_local,
                'course_code' => $occ->teachingSchedule->course->code,
                'course_name' => $occ->teachingSchedule->course->name,
                'class_name' => $occ->teachingSchedule->class_name,
                'room' => $occ->teachingSchedule->room_display,
                'delivery_mode' => $occ->teachingSchedule->delivery_mode,
                'prodi' => $occ->teachingSchedule->studyProgram->name,
                'is_supervision' => false,
            ]);
        }

        foreach ($supervisionAssignments as $assign) {
            $occ = $assign->teachingOccurrence;
            if (! $occ) {
                continue;
            }

            $allEvents->push([
                'type' => 'SUPERVISION',
                'date' => $occ->occurrence_date,
                'day_name' => $this->getDayName($occ->day_of_week),
                'start_time' => $occ->start_time_local,
                'end_time' => $occ->end_time_local,
                'course_code' => $occ->teachingSchedule->course->code,
                'course_name' => $occ->teachingSchedule->course->name,
                'class_name' => $occ->teachingSchedule->class_name,
                'room' => $occ->teachingSchedule->room_display,
                'delivery_mode' => $occ->teachingSchedule->delivery_mode,
                'prodi' => $occ->teachingSchedule->studyProgram->name,
                'target_lecturer' => $assign->targetLecturer->name,
                'stage_name' => $assign->supervisionStage->name,
                'is_supervision' => true,
            ]);
        }

        // Sort chronologically
        $sortedEvents = $allEvents->sortBy([
            ['date', 'asc'],
            ['start_time', 'asc'],
        ]);

        $groupedByWeek = $sortedEvents->groupBy(function ($item) {
            return $item['date']->startOfWeek()->format('d M Y').' s/d '.$item['date']->endOfWeek()->format('d M Y');
        });

        $data = [
            'lecturer' => $lecturer,
            'period' => $period,
            'groupedEvents' => $groupedByWeek,
            'generatedAt' => now()->format('d F Y H:i:s'),
            'totalSupervisions' => $supervisionAssignments->count(),
            'totalTeaching' => $teachingOccurrences->count(),
        ];

        return Pdf::loadView('pdf.supervisor-schedule', $data)
            ->setPaper('a4', 'portrait');
    }

    protected function getDayName(int $isoDay): string
    {
        return match ($isoDay) {
            1 => 'Senin',
            2 => 'Selasa',
            3 => 'Rabu',
            4 => 'Kamis',
            5 => 'Jumat',
            6 => 'Sabtu',
            7 => 'Minggu',
            default => '-',
        };
    }
}
