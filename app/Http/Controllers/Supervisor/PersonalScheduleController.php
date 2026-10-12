<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Models\TeachingOccurrence;
use App\Services\Reporting\SchedulePdfService;
use App\Services\Scheduling\WeeklySupervisionScheduleGrouper;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PersonalScheduleController extends Controller
{
    public function __construct(
        protected SchedulePdfService $pdfService,
        protected WeeklySupervisionScheduleGrouper $scheduleGrouper
    ) {}

    public function index(): View
    {
        $lecturer = auth()->user()->lecturer;
        $period = SupervisionPeriod::active()->first()
            ?? SupervisionPeriod::latest('starts_on')->first();
        $inputDate = now('Asia/Jakarta');

        $teachingOccurrences = collect();
        $supervisionAssignments = collect();

        if ($lecturer && $period) {
            $teachingOccurrences = TeachingOccurrence::whereHas('teachingSchedule', function ($q) use ($lecturer) {
                $q->where('lecturer_id', $lecturer->id);
            })
                ->whereBetween('occurs_on', [$period->start_date, $period->end_date])
                ->with(['teachingSchedule.course', 'teachingSchedule.studyProgram'])
                ->orderBy('occurs_on')
                ->orderBy('starts_at')
                ->get();

            $supervisionAssignments = SupervisionAssignment::where('supervisor_lecturer_id', $lecturer->id)
                ->whereHas('supervisionStage', fn ($q) => $q
                    ->where('supervision_period_id', $period->id)
                    ->inputWindowOpen($inputDate))
                ->where('status', '<>', 'CANCELLED')
                ->with(['teachingOccurrence.teachingSchedule.course', 'targetLecturer', 'supervisionStage'])
                ->get();
        }

        // ─── Weekly Matrix Grid Data ─────────────────────────────────────
        $days = [
            1 => 'SENIN',
            2 => 'SELASA',
            3 => 'RABU',
            4 => 'KAMIS',
            5 => 'JUMAT',
            6 => 'SABTU',
        ];

        $teachingSchedules = $teachingOccurrences->map(fn ($occ) => $occ->teachingSchedule)->filter()->unique('id')->values();
        if ($teachingSchedules->isEmpty() && $lecturer) {
            $teachingSchedules = $lecturer->teachingSchedules()->where('is_active', true)->with('course')->get();
        }

        $supervisionSchedules = collect();
        foreach ($supervisionAssignments as $assign) {
            $occ = $assign->teachingOccurrence;
            $sched = $occ?->teachingSchedule;

            $dayOfWeek = (int) ($occ?->day_of_week
                ?? $sched?->day_of_week
                ?? ($occ?->occurs_on ? $occ->occurs_on->format('N') : null)
                ?? ($assign->starts_at ? $assign->starts_at->timezone('Asia/Jakarta')->format('N') : 1));

            $startTime = substr((string) ($occ?->start_time_local
                ?? $sched?->starts_local
                ?? ($assign->starts_at ? $assign->starts_at->timezone('Asia/Jakarta')->format('H:i') : '08:00')), 0, 5);

            $endTime = substr((string) ($occ?->end_time_local
                ?? $sched?->ends_local
                ?? ($assign->ends_at ? $assign->ends_at->timezone('Asia/Jakarta')->format('H:i') : '09:40')), 0, 5);

            $supervisionSchedules->push((object) [
                'id' => 'sup_'.$assign->id,
                'assignment_id' => $assign->id,
                'period_id' => $assign->supervisionStage?->supervision_period_id,
                'target_lecturer_id' => $assign->target_lecturer_id,
                'course_id' => $sched?->course_id,
                'stage_no' => $assign->supervisionStage?->stage_no,
                'is_supervision' => true,
                'day_of_week' => $dayOfWeek,
                'starts_local' => $startTime,
                'ends_local' => $endTime,
                'class_name' => $sched?->class_name ?? '-',
                'course_name' => $sched?->course?->name ?? $sched?->course_name ?? '-',
                'room_name' => $sched?->room_display ?? '-',
                'delivery_mode' => strtoupper($sched?->delivery_mode ?? 'OFFLINE'),
                'target_lecturer_name' => $assign->targetLecturer?->name ?? 'Dosen Tidak Diketahui',
                'stage_name' => $assign->supervisionStage?->name ?? 'Supervisi',
                'occurrence_date' => $occ?->occurrence_date ?? ($assign->starts_at ? $assign->starts_at->format('Y-m-d') : null),
            ]);
        }

        $supervisionSchedules = $this->scheduleGrouper->group($supervisionSchedules);

        $undergraduateSlots = collect(['07:00', '09:00', '11:00', '13:00', '15:00', '17:00', '19:00']);
        $scheduleStartTimes = $teachingSchedules->pluck('starts_local')
            ->concat($supervisionSchedules->pluck('starts_local'))
            ->map(fn ($t) => substr((string) $t, 0, 5))
            ->unique()
            ->filter()
            ->values();

        $graduateSlots = $scheduleStartTimes->reject(
            fn (string $time) => $undergraduateSlots->contains($time)
        );
        $timeSlots = $undergraduateSlots
            ->concat($graduateSlots)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $scheduleGrid = [];
        foreach ($timeSlots as $timeSlot) {
            $scheduleGrid[$timeSlot] = [];
            foreach ($days as $dNum => $dName) {
                $matchingTeachings = $teachingSchedules->filter(function ($s) use ($dNum, $timeSlot) {
                    $sStart = substr((string) $s->starts_local, 0, 5);

                    return (int) $s->day_of_week === $dNum && $sStart === $timeSlot;
                })->values();

                $matchingSupervisions = $supervisionSchedules->filter(function ($sup) use ($dNum, $timeSlot) {
                    $supStart = substr((string) $sup->starts_local, 0, 5);

                    return (int) $sup->day_of_week === $dNum && $supStart === $timeSlot;
                })->values();

                $scheduleGrid[$timeSlot][$dNum] = [
                    'teachings' => $matchingTeachings,
                    'supervisions' => $matchingSupervisions,
                    'teaching' => $matchingTeachings->first(),
                ];
            }
        }

        $teachingEvents = $teachingOccurrences->map(fn ($occurrence) => [
            'date' => $occurrence->occurrence_date,
            'start_time' => $occurrence->start_time_local,
            'end_time' => $occurrence->end_time_local,
            'course' => $occurrence->teachingSchedule?->course?->name ?? '-',
            'class' => $occurrence->teachingSchedule?->class_name ?? '-',
            'room' => $occurrence->teachingSchedule?->room_display ?? '-',
            'mode' => $occurrence->teachingSchedule?->delivery_mode ?? 'OFFLINE',
            'target' => null,
            'stage' => null,
            'is_supervision' => false,
        ]);

        $supervisionEvents = $supervisionAssignments->map(function ($assignment) {
            $occurrence = $assignment->teachingOccurrence;
            $schedule = $occurrence?->teachingSchedule;

            return [
                'date' => $occurrence?->occurrence_date ?? $assignment->starts_at,
                'start_time' => $occurrence?->start_time_local ?? $assignment->starts_at?->format('H:i'),
                'end_time' => $occurrence?->end_time_local ?? $assignment->ends_at?->format('H:i'),
                'course' => $schedule?->course?->name ?? '-',
                'class' => $schedule?->class_name ?? '-',
                'room' => $schedule?->room_display ?? '-',
                'mode' => $schedule?->delivery_mode ?? 'OFFLINE',
                'target' => $assignment->targetLecturer?->name ?? '-',
                'stage' => $assignment->supervisionStage?->name ?? '-',
                'is_supervision' => true,
            ];
        })->filter(fn (array $event) => $event['date'] !== null);

        $sortedEvents = $teachingEvents
            ->concat($supervisionEvents)
            ->sortBy([
                ['date', 'asc'],
                ['start_time', 'asc'],
            ])
            ->values();

        return view('supervisor.schedule', compact(
            'lecturer',
            'period',
            'teachingOccurrences',
            'supervisionAssignments',
            'supervisionSchedules',
            'scheduleGrid',
            'sortedEvents',
            'timeSlots',
            'days'
        ));
    }

    public function downloadPdf(): Response
    {
        abort_unless(auth()->user()->isSuperadmin(), 403, 'Cetak PDF hanya tersedia untuk LPM.');

        $lecturer = auth()->user()->lecturer;
        if (! $lecturer) {
            abort(403, 'Akun Anda tidak terhubung dengan data Dosen aktif.');
        }

        $period = SupervisionPeriod::active()->first()
            ?? SupervisionPeriod::latest('starts_on')->first();

        if (! $period) {
            abort(404, 'Tidak ditemukan periode supervisi aktif.');
        }

        $pdf = $this->pdfService->generate($lecturer, $period);
        $filename = 'Jadwal_Supervisi_'.str_replace(' ', '_', $lecturer->name).'_'.date('Ymd').'.pdf';

        return $pdf->stream($filename);
    }
}
