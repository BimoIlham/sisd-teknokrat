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
        $now = now();
        $faculties = [
            'FTIK' => 'Fakultas Teknik dan Ilmu Komputer',
            'FEB' => 'Fakultas Ekonomi dan Bisnis',
            'FSIP' => 'Fakultas Sastra dan Ilmu Pendidikan',
        ];
        $programs = [
            'FTIK' => [
                'MIK' => 'Magister Ilmu Komputer',
                'IF' => 'Informatika',
                'SI' => 'Sistem Informasi',
                'TI' => 'Teknologi Informasi',
                'TE' => 'Teknik Elektro',
                'TK' => 'Teknik Komputer',
                'TS' => 'Teknik Sipil',
            ],
            'FEB' => [
                'MN' => 'Manajemen',
                'AK' => 'Akuntansi',
            ],
            'FSIP' => [
                'PO' => 'Pendidikan Olahraga',
                'PM' => 'Pendidikan Matematika',
                'PI' => 'Pendidikan Bahasa Inggris',
                'SS' => 'Sastra Inggris',
                'MBI' => 'Magister Bahasa Inggris',
            ],
        ];

        $facultyIds = [];
        foreach ($faculties as $code => $name) {
            $faculty = DB::table('faculties')->where('code', $code)->first(['id']);
            if ($faculty) {
                DB::table('faculties')->where('id', $faculty->id)->update([
                    'name' => $name,
                    'is_active' => true,
                    'updated_at' => $now,
                ]);
                $facultyIds[$code] = (int) $faculty->id;
            } else {
                $facultyIds[$code] = (int) DB::table('faculties')->insertGetId([
                    'code' => $code,
                    'name' => $name,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $canonicalProgramIds = [];
        foreach ($programs as $facultyCode => $facultyPrograms) {
            foreach ($facultyPrograms as $code => $name) {
                $program = DB::table('study_programs')
                    ->where('faculty_id', $facultyIds[$facultyCode])
                    ->where('code', $code)
                    ->first(['id']);

                if ($program) {
                    DB::table('study_programs')->where('id', $program->id)->update([
                        'name' => $name,
                        'is_active' => true,
                        'updated_at' => $now,
                    ]);
                    $canonicalProgramIds[] = (int) $program->id;
                } else {
                    $canonicalProgramIds[] = (int) DB::table('study_programs')->insertGetId([
                        'faculty_id' => $facultyIds[$facultyCode],
                        'code' => $code,
                        'name' => $name,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        DB::table('study_programs')->whereNotIn('id', $canonicalProgramIds)->update([
            'is_active' => false,
            'updated_at' => $now,
        ]);
        DB::table('faculties')->whereNotIn('code', array_keys($faculties))->update([
            'is_active' => false,
            'updated_at' => $now,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Archived academic units remain inactive to avoid reintroducing them into selectors.
    }
};
