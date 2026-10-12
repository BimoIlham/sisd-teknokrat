<?php

use App\Models\Faculty;
use App\Models\Lecturer;
use App\Models\StudyProgram;
use App\Models\TeachingSchedule;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Faculties
        $ftik = Faculty::firstOrCreate(['code' => 'FTIK'], ['name' => 'Fakultas Teknik dan Ilmu Komputer']);
        $feb = Faculty::firstOrCreate(['code' => 'FEB'], ['name' => 'Fakultas Ekonomi dan Bisnis']);
        $fsip = Faculty::firstOrCreate(['code' => 'FSIP'], ['name' => 'Fakultas Sastra dan Ilmu Pendidikan']);

        // 2. FTIK Study Programs
        $prodiIf = StudyProgram::firstOrCreate(['faculty_id' => $ftik->id, 'code' => 'IF'], ['name' => 'Informatika']);
        $prodiSi = StudyProgram::firstOrCreate(['faculty_id' => $ftik->id, 'code' => 'SI'], ['name' => 'Sistem Informasi']);
        $prodiTi = StudyProgram::firstOrCreate(['faculty_id' => $ftik->id, 'code' => 'TI'], ['name' => 'Teknologi Informasi']);
        $prodiTe = StudyProgram::firstOrCreate(['faculty_id' => $ftik->id, 'code' => 'TE'], ['name' => 'Teknik Elektro']);
        $prodiTk = StudyProgram::firstOrCreate(['faculty_id' => $ftik->id, 'code' => 'TK'], ['name' => 'Teknik Komputer']);
        $prodiTs = StudyProgram::firstOrCreate(['faculty_id' => $ftik->id, 'code' => 'TS'], ['name' => 'Teknik Sipil']);

        // 3. FEB Study Programs
        $prodiMn = StudyProgram::firstOrCreate(['faculty_id' => $feb->id, 'code' => 'MN'], ['name' => 'Manajemen']);
        $prodiAk = StudyProgram::firstOrCreate(['faculty_id' => $feb->id, 'code' => 'AK'], ['name' => 'Akuntansi']);

        // 4. FSIP Study Programs
        $prodiPo = StudyProgram::firstOrCreate(['faculty_id' => $fsip->id, 'code' => 'PO'], ['name' => 'Pendidikan Olahraga']);
        $prodiSs = StudyProgram::firstOrCreate(['faculty_id' => $fsip->id, 'code' => 'SS'], ['name' => 'Sastra Inggris']);
        $prodiPi = StudyProgram::firstOrCreate(['faculty_id' => $fsip->id, 'code' => 'PI'], ['name' => 'Pendidikan Bahasa Inggris']);
        $prodiPm = StudyProgram::firstOrCreate(['faculty_id' => $fsip->id, 'code' => 'PM'], ['name' => 'Pendidikan Matematika']);
        $prodiMbi = StudyProgram::firstOrCreate(['faculty_id' => $fsip->id, 'code' => 'MBI'], ['name' => 'Magister Bahasa Inggris']);

        // 5. Update existing FSIP lecturers (Dr. Eko, Prof. Akhyar, etc.)
        $fsipLecturers = Lecturer::where('id', '>=', 9)->get();
        foreach ($fsipLecturers as $lec) {
            $classes = TeachingSchedule::where('lecturer_id', $lec->id)->pluck('class_name')->all();
            $classStr = implode(' ', $classes);

            // Determine predominant study program
            $targetProdi = $prodiSs;
            if (preg_match_all('/\bPO\b/i', $classStr, $m) && count($m[0]) >= 2) {
                $targetProdi = $prodiPo;
            } elseif (preg_match_all('/\bPM\b/i', $classStr, $m) && count($m[0]) >= 2) {
                $targetProdi = $prodiPm;
            } elseif (preg_match_all('/\bPI\b|\bPBI\b/i', $classStr, $m) && count($m[0]) >= 2) {
                $targetProdi = $prodiPi;
            } elseif (preg_match_all('/\bMBI\b/i', $classStr, $m) && count($m[0]) >= 2) {
                $targetProdi = $prodiMbi;
            } elseif (str_contains($lec->name, 'Eko Bagus') || str_contains($lec->name, 'Gumantan') || str_contains($lec->name, 'Arifai') || str_contains($lec->name, 'Yuliandra') || str_contains($lec->name, 'Marsheilla') || str_contains($lec->name, 'Reza Adhi') || str_contains($lec->name, 'Triyanto')) {
                $targetProdi = $prodiPo;
            } elseif (str_contains($lec->name, 'Puspaningtyas') || str_contains($lec->name, 'Sukma Dewi') || str_contains($lec->name, 'Kartika') || str_contains($lec->name, 'Anderha') || str_contains($lec->name, 'Hendra Saputra') || str_contains($lec->name, 'Sugama')) {
                $targetProdi = $prodiPm;
            } elseif (str_contains($lec->name, 'Mutiara') || str_contains($lec->name, 'Dyah Aminatun') || str_contains($lec->name, 'Intan Hamzah') || str_contains($lec->name, 'Alvi Raihan') || str_contains($lec->name, 'Afrianto')) {
                $targetProdi = $prodiPi;
            }

            $lec->update([
                'faculty_id' => $fsip->id,
                'study_program_id' => $targetProdi->id,
            ]);
        }

        // 6. Update Teaching Schedules according to class prefixes
        $schedules = TeachingSchedule::all();
        foreach ($schedules as $sch) {
            $cls = strtoupper($sch->class_name);
            if (str_starts_with($cls, 'PO')) {
                $sch->update(['faculty_id' => $fsip->id, 'study_program_id' => $prodiPo->id]);
            } elseif (str_starts_with($cls, 'SS')) {
                $sch->update(['faculty_id' => $fsip->id, 'study_program_id' => $prodiSs->id]);
            } elseif (str_starts_with($cls, 'PI') || str_starts_with($cls, 'PBI')) {
                $sch->update(['faculty_id' => $fsip->id, 'study_program_id' => $prodiPi->id]);
            } elseif (str_starts_with($cls, 'PM')) {
                $sch->update(['faculty_id' => $fsip->id, 'study_program_id' => $prodiPm->id]);
            } elseif (str_starts_with($cls, 'MBI')) {
                $sch->update(['faculty_id' => $fsip->id, 'study_program_id' => $prodiMbi->id]);
            } elseif (str_starts_with($cls, 'MN') || str_starts_with($cls, 'MNJ')) {
                $sch->update(['faculty_id' => $feb->id, 'study_program_id' => $prodiMn->id]);
            } elseif (str_starts_with($cls, 'AK') || str_starts_with($cls, 'AKT')) {
                $sch->update(['faculty_id' => $feb->id, 'study_program_id' => $prodiAk->id]);
            } elseif (str_starts_with($cls, 'IF')) {
                $sch->update(['faculty_id' => $ftik->id, 'study_program_id' => $prodiIf->id]);
            } elseif (str_starts_with($cls, 'SI')) {
                $sch->update(['faculty_id' => $ftik->id, 'study_program_id' => $prodiSi->id]);
            } elseif (str_starts_with($cls, 'TI')) {
                $sch->update(['faculty_id' => $ftik->id, 'study_program_id' => $prodiTi->id]);
            } elseif (str_starts_with($cls, 'TK')) {
                $sch->update(['faculty_id' => $ftik->id, 'study_program_id' => $prodiTk->id]);
            } elseif (str_starts_with($cls, 'TS')) {
                $sch->update(['faculty_id' => $ftik->id, 'study_program_id' => $prodiTs->id]);
            } elseif ($sch->lecturer && $sch->lecturer->faculty_id == $fsip->id) {
                $sch->update(['faculty_id' => $fsip->id, 'study_program_id' => $sch->lecturer->study_program_id]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep data intact
    }
};
