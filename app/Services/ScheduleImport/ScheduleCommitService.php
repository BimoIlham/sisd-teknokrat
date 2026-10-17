<?php

namespace App\Services\ScheduleImport;

use App\Models\PeriodTarget;
use App\Models\ScheduleImport;
use App\Models\ScheduleImportRow;
use App\Models\StudyProgram;
use App\Models\SupervisionPeriod;
use App\Models\TeachingSchedule;
use App\Services\Academic\OccurrenceGeneratorService;
use App\Services\Scheduling\SchedulingLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleCommitService
{
    public function __construct(
        protected OccurrenceGeneratorService $occurrenceGenerator,
        protected ScheduleRowValidator $rowValidator,
        protected ScheduleCourseResolver $courseResolver
    ) {}

    /**
     * Commit staging rows into active teaching_schedules and generate occurrences.
     * Invariant 11: Commit is atomic and idempotent.
     */
    public function commit(ScheduleImport $import, bool $skipInvalidRows = false): int
    {
        return DB::transaction(function () use ($import, $skipInvalidRows): int {
            $locks = app(SchedulingLockService::class);
            $state = $locks->state();
            $import = ScheduleImport::query()->lockForUpdate()->findOrFail($import->id);
            if ($import->status === 'COMMITTED') {
                return 0; // Idempotent
            }

            if ($import->supervision_period_id && ! SupervisionPeriod::whereKey($import->supervision_period_id)
                ->where('academic_term_id', $import->academic_term_id)->where('status', 'ACTIVE')->exists()) {
                throw ValidationException::withMessages(['import' => 'Periode unggahan ini tidak aktif lagi. Unggah jadwal untuk periode aktif.']);
            }

            $pendingRows = ScheduleImportRow::where('schedule_import_id', $import->id)
                ->where('validation_status', '<>', 'SKIPPED')
                ->with(['matchedLecturer', 'committedSchedule'])
                ->orderBy('id')->lockForUpdate()->get();
            if ($pendingRows->isEmpty()) {
                $intentionalRows = ScheduleImportRow::where('schedule_import_id', $import->id)
                    ->where('validation_status', 'SKIPPED')
                    ->whereIn('skip_reason', PeriodLecturerExclusions::intentionalReasons())
                    ->count();
                if ($intentionalRows > 0 && $intentionalRows === $import->rows()->count()) {
                    $import->update(['status' => 'COMMITTED', 'committed_at' => now()]);
                    app(ScheduleImportReferences::class)->rememberImport($import);
                    $locks->bump($state);

                    return 0;
                }

                throw ValidationException::withMessages(['import' => ['Tidak ada jadwal valid untuk diaktifkan.']]);
            }

            if (! $skipInvalidRows) {
                $unmatched = $pendingRows->whereNull('matched_lecturer_id')->count();
                if ($unmatched > 0) {
                    throw ValidationException::withMessages([
                        'import' => ["Terdapat {$unmatched} baris jadwal yang belum cocok dengan dosen terdaftar; baris tersebut tidak dapat diaktifkan."],
                    ]);
                }
            }

            $rows = collect();
            $skippedRows = collect();
            foreach ($pendingRows as $row) {
                $issues = $this->rowValidator->validate($row->normalized_payload ?? [], $row->validation_issues ?? []);
                $hasErrors = collect($issues)->contains(fn (array $issue): bool => ($issue['severity'] ?? '') === 'ERROR');
                $lecturerIsActive = $row->matchedLecturer?->is_active === true;
                $canCommit = $row->matched_lecturer_id
                    && $lecturerIsActive
                    && $row->is_approved
                    && $row->validation_status === 'VALID'
                    && ! $hasErrors;

                if ($canCommit) {
                    $rows->push($row);

                    continue;
                }

                if (! $skipInvalidRows) {
                    throw ValidationException::withMessages([
                        'import' => ["Baris {$row->row_no} pada halaman {$row->source_page} belum dapat dipastikan. Periksa metode, ruang, dan waktu dari PDF sebelum jadwal diaktifkan."],
                    ]);
                }

                $skippedRows->push([
                    'row' => $row,
                    'reason' => ! $row->matched_lecturer_id
                        ? 'Nama dosen dari PDF tidak terbaca atau tidak cocok dengan direktori dosen.'
                        : collect($issues)->pluck('message')->filter()->unique()->implode(' '),
                ]);
            }

            if ($rows->isEmpty()) {
                $unmatchedCount = $pendingRows->filter(fn (ScheduleImportRow $row): bool => ! $row->matched_lecturer_id
                    || $row->matchedLecturer?->is_active !== true)->count();
                $invalidCount = $pendingRows->where('validation_status', 'INVALID')->count();
                $uncertainCount = $pendingRows->filter(fn (ScheduleImportRow $row): bool => (float) $row->parser_confidence < 0.85)->count();
                $reasons = [];
                if ($unmatchedCount > 0) {
                    $reasons[] = "{$unmatchedCount} baris tidak cocok dengan dosen aktif di Direktori Dosen";
                }
                if ($invalidCount > 0) {
                    $reasons[] = "{$invalidCount} baris memiliki data jadwal yang tidak lengkap atau tidak valid";
                }
                if ($uncertainCount > 0) {
                    $reasons[] = "{$uncertainCount} baris memiliki hasil pembacaan PDF yang kurang yakin";
                }

                throw ValidationException::withMessages([
                    'import' => ['Tidak ada baris jadwal yang dapat diaktifkan. '.($reasons ? implode('; ', $reasons).'.' : 'Periksa PDF dan Direktori Dosen.')],
                ]);
            }

            $term = $import->academicTerm;

            foreach ($skippedRows as $skippedRow) {
                $skippedRow['row']->update([
                    'validation_status' => 'SKIPPED',
                    'is_approved' => false,
                    'skip_reason' => $skippedRow['reason'] ?: 'Baris tidak lolos validasi otomatis.',
                ]);
            }

            $committedCount = 0;

            foreach ($rows as $row) {
                $existingSchedule = $row->committedSchedule;
                if ($existingSchedule?->is_active) {
                    continue;
                }
                $course = $this->courseResolver->resolve($row->parsed_course_name, $row->parsed_course_code);

                $facId = $row->matched_faculty_id ?: $row->matchedLecturer?->faculty_id;
                $prodiId = $row->matched_study_program_id ?: $row->matchedLecturer?->study_program_id;

                if (! $prodiId && $row->parsed_class_name) {
                    $prefix = strtoupper(trim(preg_replace('/[\d\s].*/', '', $row->parsed_class_name)));
                    $matchedProdi = StudyProgram::query()
                        ->where('code', $prefix)
                        ->where('is_active', true)
                        ->whereHas('faculty', fn ($query) => $query->where('is_active', true))
                        ->first();
                    if ($matchedProdi) {
                        $prodiId = $matchedProdi->id;
                        $facId = $matchedProdi->faculty_id;
                    }
                }

                $attributes = [
                    'academic_term_id' => $term->id,
                    'supervision_period_id' => $import->supervision_period_id,
                    'faculty_id' => $facId,
                    'study_program_id' => $prodiId,
                    'lecturer_id' => $row->matched_lecturer_id,
                    'course_id' => $course->id,
                    'class_name' => $row->parsed_class_name ?: 'A',
                    'day_of_week' => $row->parsed_day_of_week,
                    'starts_local' => $row->parsed_start_time,
                    'ends_local' => $row->parsed_end_time,
                    'room_name' => $row->parsed_room,
                    'delivery_mode' => $row->parsed_delivery_mode,
                    'source_import_id' => $import->id,
                    'source_row_id' => $row->id,
                    'source_payload' => $row->normalized_payload,
                    'is_active' => true,
                ];
                if ($existingSchedule) {
                    $existingSchedule->update($attributes);
                    $schedule = $existingSchedule;
                } else {
                    $schedule = TeachingSchedule::create($attributes);
                }
                $row->update(['committed_schedule_id' => $schedule->id]);

                // Generate dated occurrences
                $this->occurrenceGenerator->generateForSchedule($schedule);

                $committedCount++;
            }

            $import->update([
                'status' => 'COMMITTED',
                'committed_at' => now(),
            ]);
            app(ScheduleImportReferences::class)->rememberImport($import);

            // Auto-enroll lecturers into PeriodTarget for any active SupervisionPeriods of this term
            $lecturerIds = $rows->pluck('matched_lecturer_id')->filter()->unique();
            $periods = SupervisionPeriod::whereKey($import->supervision_period_id)->get();
            $adminId = auth()->id() ?? 1;

            foreach ($periods as $period) {
                foreach ($lecturerIds as $lecId) {
                    PeriodTarget::firstOrCreate(
                        [
                            'supervision_period_id' => $period->id,
                            'lecturer_id' => $lecId,
                        ],
                        [
                            'scope_status' => 'INCLUDED',
                            'selected_by' => $adminId,
                        ]
                    );
                }
            }

            $locks->bump($state);

            return $committedCount;
        }, 3);
    }
}
