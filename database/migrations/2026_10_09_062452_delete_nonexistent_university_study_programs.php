<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $facultyId = DB::table('faculties')->where('code', 'FTIK')->value('id');
        if (! $facultyId) {
            return;
        }

        $programs = [
            'IK' => 'Ilmu Komputer',
            'SIA' => 'Sistem Informasi Akuntansi',
            'PAI' => 'Pendidikan Agama Islam',
        ];
        $programIds = DB::table('study_programs')
            ->where('faculty_id', $facultyId)
            ->where(function ($query) use ($programs): void {
                foreach ($programs as $code => $name) {
                    $query->orWhere(function ($programQuery) use ($code, $name): void {
                        $programQuery->where('code', $code)->where('name', $name);
                    });
                }
            })
            ->pluck('id');

        if ($programIds->isEmpty()) {
            return;
        }

        $lecturerReferences = DB::table('lecturers')->whereIn('study_program_id', $programIds)->count();
        $scheduleReferences = DB::table('teaching_schedules')->whereIn('study_program_id', $programIds)->count();
        if ($lecturerReferences > 0 || $scheduleReferences > 0) {
            throw new RuntimeException('Prodi yang akan dihapus masih dirujuk dosen atau jadwal kuliah. Hapus migrasi dibatalkan agar data terkait tidak kehilangan relasi.');
        }

        DB::table('study_programs')->whereIn('id', $programIds)->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $facultyId = DB::table('faculties')->where('code', 'FTIK')->value('id');
        if (! $facultyId) {
            return;
        }

        $now = now();
        foreach ([
            'IK' => 'Ilmu Komputer',
            'SIA' => 'Sistem Informasi Akuntansi',
            'PAI' => 'Pendidikan Agama Islam',
        ] as $code => $name) {
            DB::table('study_programs')->updateOrInsert(
                ['faculty_id' => $facultyId, 'code' => $code],
                [
                    'name' => $name,
                    'is_active' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
};
