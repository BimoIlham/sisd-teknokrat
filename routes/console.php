<?php

use App\Models\Lecturer;
use App\Models\ScheduleImport;
use App\Models\User;
use App\Services\ScheduleImport\ScheduleImportReferences;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:reset-schedule-data {--keep-academic-master : Pertahankan mata kuliah, tahun akademik, dan kalender akademik}', function (): int {
    $tables = [
        'supervision_evidence',
        'supervision_answers',
        'supervisions',
        'assignment_audits',
        'supervision_assignments',
        'generated_documents',
        'system_audits',
        'operation_requests',
        'schedule_runs',
        'stage_instruments',
        'supervision_stages',
        'period_supervisors',
        'period_targets',
        'scheduling_rules',
        'supervision_periods',
        'teaching_occurrences',
        'schedule_import_rows',
        'teaching_schedules',
        'schedule_imports',
        'academic_non_teaching_dates',
        'courses',
        'lecturers',
        'academic_terms',
    ];
    if ($this->option('keep-academic-master')) {
        $tables = array_values(array_diff($tables, ['courses', 'academic_terms', 'academic_non_teaching_dates']));
    }

    [$deletedCounts, $files] = DB::transaction(function () use ($tables): array {
        $references = app(ScheduleImportReferences::class);
        Lecturer::query()->each(fn ($lecturer) => $references->rememberLecturer($lecturer));
        ScheduleImport::where('status', 'COMMITTED')->each(fn ($import) => $references->rememberImport($import));
        $lecturerUsers = User::query()
            ->whereNotNull('lecturer_id')
            ->whereDoesntHave('roles', fn (Builder $roles): Builder => $roles->where('name', 'superadmin'))
            ->get();
        $files = DB::table('schedule_imports')->select('storage_disk', 'stored_path as file_path')->get()
            ->concat(DB::table('supervision_evidence')->select('storage_disk', 'file_path')->whereNotNull('file_path')->get())
            ->concat(DB::table('generated_documents')->select('storage_disk', 'file_path')->whereNotNull('file_path')->get());
        $deletedCounts = [];

        foreach ($tables as $table) {
            $deletedCounts[$table] = DB::table($table)->delete();
        }

        DB::table('sessions')->whereIn('user_id', $lecturerUsers->modelKeys())->delete();
        DB::table('password_reset_tokens')->whereIn('email', $lecturerUsers->pluck('email'))->delete();
        foreach ($lecturerUsers as $user) {
            $user->delete();
        }
        $deletedCounts['lecturer_accounts'] = $lecturerUsers->count();

        DB::table('scheduling_state')->updateOrInsert(['id' => 1], [
            'revision' => (int) DB::table('scheduling_state')->where('id', 1)->value('revision') + 1,
            'updated_at' => now(),
        ]);

        return [$deletedCounts, $files];
    });

    foreach ($deletedCounts as $table => $count) {
        $this->info("Deleted {$count} records: {$table}");
    }

    $hasFileErrors = false;
    foreach ($files as $file) {
        if ($file->file_path && ! Storage::disk($file->storage_disk)->delete($file->file_path)) {
            $hasFileErrors = true;
            $this->warn("Could not delete stored file: {$file->file_path}");
        }
    }

    $this->info('Lecturer and supervision period data reset. LPM accounts and assessment instruments retained.');

    return $hasFileErrors ? 1 : 0;
})->purpose('Reset lecturer, schedule, and supervision period data while retaining LPM accounts and instruments');
