<?php

namespace App\Console\Commands;

use App\Models\Lecturer;
use App\Models\StudyProgram;
use App\Models\TeachingSchedule;
use Illuminate\Console\Command;

class BackfillLecturerFaculty extends Command
{
    protected $signature = 'lecturers:backfill-faculty';

    protected $description = 'Backfill faculty_id and study_program_id on lecturers from their teaching schedule class_name codes';

    public function handle(): int
    {
        // Build a lookup: study program code => StudyProgram model
        $prodiLookup = StudyProgram::query()
            ->where('is_active', true)
            ->whereHas('faculty', fn ($query) => $query->where('is_active', true))
            ->with('faculty')
            ->get()
            ->keyBy(fn ($p) => strtoupper($p->code));

        $lecturers = Lecturer::whereNull('faculty_id')->get();
        $updated = 0;

        foreach ($lecturers as $lec) {
            // Try from teaching_schedules faculty_id first
            $schedule = TeachingSchedule::where('lecturer_id', $lec->id)
                ->whereNotNull('faculty_id')
                ->first();

            if ($schedule) {
                $data = ['faculty_id' => $schedule->faculty_id];
                if (! $lec->study_program_id && $schedule->study_program_id) {
                    $data['study_program_id'] = $schedule->study_program_id;
                }
                $lec->update($data);
                $updated++;

                continue;
            }

            // Fallback: parse the class_name prefix (e.g. "SS 25 A" => SS => Sastra Inggris)
            $classNames = TeachingSchedule::where('lecturer_id', $lec->id)
                ->pluck('class_name')
                ->filter();

            foreach ($classNames as $className) {
                // Extract the prefix code before the first space or digit
                $prefix = strtoupper(trim(preg_replace('/[\d\s].*/', '', $className)));
                if ($prefix && $prodiLookup->has($prefix)) {
                    $prodi = $prodiLookup[$prefix];
                    $lec->update([
                        'faculty_id' => $prodi->faculty_id,
                        'study_program_id' => $prodi->id,
                    ]);
                    $updated++;
                    break;
                }
            }
        }

        $remaining = Lecturer::whereNull('faculty_id')->count();
        $this->info("{$updated} dosen berhasil diupdate faculty_id.");
        if ($remaining > 0) {
            $this->warn("{$remaining} dosen masih belum memiliki faculty_id (perlu diset manual).");
        }

        // Also backfill teaching_schedules
        $scheduleUpdated = 0;
        $schedules = TeachingSchedule::with('lecturer')->whereNull('faculty_id')->get();
        foreach ($schedules as $sched) {
            $facId = $sched->lecturer?->faculty_id;
            $prodiId = $sched->lecturer?->study_program_id;

            if (! $facId && $sched->class_name) {
                $prefix = strtoupper(trim(preg_replace('/[\d\s].*/', '', $sched->class_name)));
                if ($prefix && $prodiLookup->has($prefix)) {
                    $prodi = $prodiLookup[$prefix];
                    $facId = $prodi->faculty_id;
                    $prodiId = $prodi->id;
                }
            }

            if ($facId) {
                $sched->update([
                    'faculty_id' => $facId,
                    'study_program_id' => $prodiId,
                ]);
                $scheduleUpdated++;
            }
        }
        $this->info("{$scheduleUpdated} jadwal kuliah berhasil disinkronkan fakultas & prodinya.");

        return self::SUCCESS;
    }
}
