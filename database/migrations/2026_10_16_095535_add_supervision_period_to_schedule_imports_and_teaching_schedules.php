<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_imports', function (Blueprint $table) {
            $table->foreignId('supervision_period_id')->nullable()->after('academic_term_id')->constrained('supervision_periods')->nullOnDelete();
        });
        Schema::table('teaching_schedules', function (Blueprint $table) {
            $table->foreignId('supervision_period_id')->nullable()->after('academic_term_id')->constrained('supervision_periods')->nullOnDelete();
        });

        $uniquePeriods = DB::table('supervision_periods')->select('academic_term_id')
            ->groupBy('academic_term_id')->havingRaw('COUNT(*) = 1')->pluck('academic_term_id');
        foreach ($uniquePeriods as $termId) {
            $periodId = DB::table('supervision_periods')->where('academic_term_id', $termId)->value('id');
            DB::table('schedule_imports')->where('academic_term_id', $termId)->update(['supervision_period_id' => $periodId]);
            DB::table('teaching_schedules')->where('academic_term_id', $termId)->update(['supervision_period_id' => $periodId]);
        }
        $assignedSchedules = DB::table('teaching_schedules as schedules')
            ->join('teaching_occurrences as occurrences', 'occurrences.teaching_schedule_id', '=', 'schedules.id')
            ->join('supervision_assignments as assignments', 'assignments.teaching_occurrence_id', '=', 'occurrences.id')
            ->join('supervision_stages as stages', 'stages.id', '=', 'assignments.supervision_stage_id')
            ->whereNull('schedules.supervision_period_id')
            ->selectRaw('schedules.id, MIN(stages.supervision_period_id) AS first_period_id, MAX(stages.supervision_period_id) AS last_period_id')
            ->groupBy('schedules.id')->get();
        foreach ($assignedSchedules as $schedule) {
            if ($schedule->first_period_id === $schedule->last_period_id) {
                DB::table('teaching_schedules')->where('id', $schedule->id)->update(['supervision_period_id' => $schedule->first_period_id]);
            }
        }
        $assignedImports = DB::table('teaching_schedules')->whereNotNull('supervision_period_id')->whereNotNull('source_import_id')
            ->selectRaw('source_import_id, MIN(supervision_period_id) AS first_period_id, MAX(supervision_period_id) AS last_period_id')
            ->groupBy('source_import_id')->get();
        foreach ($assignedImports as $import) {
            if ($import->first_period_id === $import->last_period_id) {
                DB::table('schedule_imports')->where('id', $import->source_import_id)->whereNull('supervision_period_id')
                    ->update(['supervision_period_id' => $import->first_period_id]);
            }
        }
        DB::table('teaching_schedules')->whereNull('supervision_period_id')->whereNotNull('source_import_id')
            ->get(['id', 'source_import_id'])->each(function (object $schedule): void {
                $periodId = DB::table('schedule_imports')->where('id', $schedule->source_import_id)->value('supervision_period_id');
                if ($periodId) {
                    DB::table('teaching_schedules')->where('id', $schedule->id)->update(['supervision_period_id' => $periodId]);
                }
            });

        Schema::table('schedule_imports', function (Blueprint $table) {
            $table->dropUnique(['academic_term_id', 'sha256']);
            $table->unique(['supervision_period_id', 'sha256']);
        });
        Schema::table('teaching_schedules', function (Blueprint $table) {
            $table->index(['supervision_period_id', 'lecturer_id', 'is_active'], 'ts_period_lecturer_active_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('schedule_imports')->select('academic_term_id', 'sha256')->groupBy('academic_term_id', 'sha256')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Unggahan PDF lintas periode dalam semester yang sama harus ditangani sebelum rollback.');
        }
        Schema::table('teaching_schedules', fn (Blueprint $table) => $table->dropForeign(['supervision_period_id']));
        Schema::table('schedule_imports', fn (Blueprint $table) => $table->dropForeign(['supervision_period_id']));
        Schema::table('teaching_schedules', function (Blueprint $table) {
            $table->dropIndex('ts_period_lecturer_active_idx');
            $table->dropColumn('supervision_period_id');
        });
        Schema::table('schedule_imports', function (Blueprint $table) {
            $table->dropUnique(['supervision_period_id', 'sha256']);
            $table->unique(['academic_term_id', 'sha256']);
            $table->dropColumn('supervision_period_id');
        });
    }
};
