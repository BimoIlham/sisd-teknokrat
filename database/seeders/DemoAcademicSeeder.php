<?php

namespace Database\Seeders;

use App\Models\AcademicNonTeachingDate;
use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\FormTemplate;
use App\Models\Lecturer;
use App\Models\PeriodSupervisor;
use App\Models\PeriodTarget;
use App\Models\SchedulingRule;
use App\Models\ScoringPolicy;
use App\Models\StageInstrument;
use App\Models\StudyProgram;
use App\Models\SupervisionPeriod;
use App\Models\SupervisionStage;
use App\Models\TeachingSchedule;
use App\Models\User;
use App\Services\Academic\OccurrenceGeneratorService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoAcademicSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::first();

        // 1. Faculties & Study Programs (Universitas Teknokrat Indonesia)
        $ftik = Faculty::updateOrCreate(['code' => 'FTIK'], ['name' => 'Fakultas Teknik dan Ilmu Komputer', 'is_active' => true]);
        $feb = Faculty::updateOrCreate(['code' => 'FEB'], ['name' => 'Fakultas Ekonomi dan Bisnis', 'is_active' => true]);
        $fsip = Faculty::updateOrCreate(['code' => 'FSIP'], ['name' => 'Fakultas Sastra dan Ilmu Pendidikan', 'is_active' => true]);

        // FTIK Study Programs
        StudyProgram::updateOrCreate(['faculty_id' => $ftik->id, 'code' => 'MIK'], ['name' => 'Magister Ilmu Komputer', 'is_active' => true]);
        $prodiIf = StudyProgram::updateOrCreate(['faculty_id' => $ftik->id, 'code' => 'IF'], ['name' => 'Informatika', 'is_active' => true]);
        $prodiSi = StudyProgram::updateOrCreate(['faculty_id' => $ftik->id, 'code' => 'SI'], ['name' => 'Sistem Informasi', 'is_active' => true]);
        $prodiTi = StudyProgram::updateOrCreate(['faculty_id' => $ftik->id, 'code' => 'TI'], ['name' => 'Teknologi Informasi', 'is_active' => true]);
        $prodiTe = StudyProgram::updateOrCreate(['faculty_id' => $ftik->id, 'code' => 'TE'], ['name' => 'Teknik Elektro', 'is_active' => true]);
        $prodiTk = StudyProgram::updateOrCreate(['faculty_id' => $ftik->id, 'code' => 'TK'], ['name' => 'Teknik Komputer', 'is_active' => true]);
        $prodiTs = StudyProgram::updateOrCreate(['faculty_id' => $ftik->id, 'code' => 'TS'], ['name' => 'Teknik Sipil', 'is_active' => true]);

        // FEB Study Programs
        $prodiMn = StudyProgram::updateOrCreate(['faculty_id' => $feb->id, 'code' => 'MN'], ['name' => 'Manajemen', 'is_active' => true]);
        $prodiAk = StudyProgram::updateOrCreate(['faculty_id' => $feb->id, 'code' => 'AK'], ['name' => 'Akuntansi', 'is_active' => true]);

        // FSIP Study Programs
        $prodiPo = StudyProgram::updateOrCreate(['faculty_id' => $fsip->id, 'code' => 'PO'], ['name' => 'Pendidikan Olahraga', 'is_active' => true]);
        $prodiSs = StudyProgram::updateOrCreate(['faculty_id' => $fsip->id, 'code' => 'SS'], ['name' => 'Sastra Inggris', 'is_active' => true]);
        $prodiPi = StudyProgram::updateOrCreate(['faculty_id' => $fsip->id, 'code' => 'PI'], ['name' => 'Pendidikan Bahasa Inggris', 'is_active' => true]);
        $prodiPm = StudyProgram::updateOrCreate(['faculty_id' => $fsip->id, 'code' => 'PM'], ['name' => 'Pendidikan Matematika', 'is_active' => true]);
        $prodiMbi = StudyProgram::updateOrCreate(['faculty_id' => $fsip->id, 'code' => 'MBI'], ['name' => 'Magister Bahasa Inggris', 'is_active' => true]);

        // 2. Academic Terms (Semester Ganjil 2026/2027 and subsequent terms)
        $termsData = [
            ['semester' => 'GANJIL', 'academic_year' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31'],
            ['semester' => 'GENAP', 'academic_year' => '2026/2027', 'starts_on' => '2027-02-01', 'ends_on' => '2027-07-31'],
        ];

        $term = null;
        foreach ($termsData as $tData) {
            $createdTerm = AcademicTerm::firstOrCreate(
                ['semester' => $tData['semester'], 'academic_year' => $tData['academic_year']],
                [
                    'starts_on' => $tData['starts_on'],
                    'ends_on' => $tData['ends_on'],
                    'timezone' => 'Asia/Jakarta',
                ]
            );

            if ($tData['semester'] === 'GANJIL' && $tData['academic_year'] === '2026/2027') {
                $term = $createdTerm;
            }
        }

        // Academic holiday / non-teaching date
        AcademicNonTeachingDate::firstOrCreate(
            ['academic_term_id' => $term->id, 'date' => '2026-10-12'],
            ['reason' => 'Libur Dies Natalis Universitas Teknokrat Indonesia', 'created_by' => $admin->id]
        );

        // 3. Courses
        $courses = [
            Course::firstOrCreate(['code' => 'IF101'], ['name' => 'Algoritma & Pemrograman', 'normalized_name' => 'algoritma pemrograman']),
            Course::firstOrCreate(['code' => 'IF202'], ['name' => 'Pemrograman Web Lanjut', 'normalized_name' => 'pemrograman web lanjut']),
            Course::firstOrCreate(['code' => 'IF303'], ['name' => 'Kecerdasan Buatan', 'normalized_name' => 'kecerdasan buatan']),
            Course::firstOrCreate(['code' => 'SI105'], ['name' => 'Basis Data Enterprise', 'normalized_name' => 'basis data enterprise']),
            Course::firstOrCreate(['code' => 'MN201'], ['name' => 'Manajemen Strategis', 'normalized_name' => 'manajemen strategis']),
        ];

        // 4. Lecturers & User Accounts
        $lecturersData = [
            ['code' => 'DSN001', 'name' => 'Dr. Budi Santoso, M.Kom.', 'prodi' => $prodiIf, 'is_sup' => true, 'email' => 'supervisor1@teknokrat.ac.id'],
            ['code' => 'DSN002', 'name' => 'Fitri Yulianti, S.T., M.Cs.', 'prodi' => $prodiIf, 'is_sup' => true, 'email' => 'supervisor2@teknokrat.ac.id'],
            ['code' => 'DSN003', 'name' => 'Andi Pratama, M.Kom.', 'prodi' => $prodiSi, 'is_sup' => true, 'email' => 'supervisor3@teknokrat.ac.id'],
            ['code' => 'DSN004', 'name' => 'Rina Wulandari, M.T.I.', 'prodi' => $prodiIf, 'is_sup' => false, 'email' => 'rina@teknokrat.ac.id'],
            ['code' => 'DSN005', 'name' => 'Hendra Gunawan, M.Cs.', 'prodi' => $prodiSi, 'is_sup' => false, 'email' => 'hendra@teknokrat.ac.id'],
            ['code' => 'DSN006', 'name' => 'Dewi Lestari, S.E., M.M.', 'prodi' => $prodiMn, 'is_sup' => false, 'email' => 'dewi@teknokrat.ac.id'],
            ['code' => 'DSN007', 'name' => 'Ahmad Fauzi, M.Kom.', 'prodi' => $prodiIf, 'is_sup' => false, 'email' => 'ahmad@teknokrat.ac.id'],
            ['code' => 'DSN008', 'name' => 'Siti Nurhaliza, M.Cs.', 'prodi' => $prodiSi, 'is_sup' => false, 'email' => 'siti@teknokrat.ac.id'],
        ];

        $createdLecturers = [];
        foreach ($lecturersData as $ld) {
            $lec = Lecturer::firstOrCreate(
                ['external_code' => $ld['code']],
                [
                    'faculty_id' => $ld['prodi']->faculty_id,
                    'study_program_id' => $ld['prodi']->id,
                    'name' => $ld['name'],
                    'normalized_name' => strtolower($ld['name']),
                ]
            );
            $createdLecturers[] = $lec;

            if ($ld['is_sup']) {
                $user = User::firstOrCreate(
                    ['email' => $ld['email']],
                    [
                        'lecturer_id' => $lec->id,
                        'name' => $ld['name'],
                        'password' => Hash::make('Password123!'),
                        'is_active' => true,
                        'must_change_password' => false,
                    ]
                );
                $user->assignRole('supervisor');
            }
        }

        // 5. Weekly Teaching Schedules
        $schedulesSpec = [
            // Dosen 1 (Supervisor 1): Monday 08:00-09:40
            ['lec' => $createdLecturers[0], 'course' => $courses[0], 'day' => 1, 'start' => '08:00:00', 'end' => '09:40:00', 'mode' => 'OFFLINE', 'room' => 'R. 301', 'class' => 'IF-23-A'],
            // Dosen 2 (Supervisor 2): Tuesday 10:00-11:40
            ['lec' => $createdLecturers[1], 'course' => $courses[1], 'day' => 2, 'start' => '10:00:00', 'end' => '11:40:00', 'mode' => 'OFFLINE', 'room' => 'R. 302', 'class' => 'IF-23-B'],
            // Dosen 3 (Supervisor 3): Wednesday 13:00-14:40
            ['lec' => $createdLecturers[2], 'course' => $courses[3], 'day' => 3, 'start' => '13:00:00', 'end' => '14:40:00', 'mode' => 'ONLINE', 'room' => 'SPADA', 'class' => 'SI-23-A'],
            // Dosen 4 (Target): Monday 10:00-11:40
            ['lec' => $createdLecturers[3], 'course' => $courses[0], 'day' => 1, 'start' => '10:00:00', 'end' => '11:40:00', 'mode' => 'OFFLINE', 'room' => 'R. 303', 'class' => 'IF-23-C'],
            // Dosen 5 (Target): Tuesday 13:00-14:40
            ['lec' => $createdLecturers[4], 'course' => $courses[1], 'day' => 2, 'start' => '13:00:00', 'end' => '14:40:00', 'mode' => 'OFFLINE', 'room' => 'R. 304', 'class' => 'SI-23-B'],
            // Dosen 6 (Target): Wednesday 08:00-09:40
            ['lec' => $createdLecturers[5], 'course' => $courses[4], 'day' => 3, 'start' => '08:00:00', 'end' => '09:40:00', 'mode' => 'OFFLINE', 'room' => 'R. 401', 'class' => 'MN-23-A'],
            // Dosen 7 (Target): Thursday 09:00-10:40
            ['lec' => $createdLecturers[6], 'course' => $courses[2], 'day' => 4, 'start' => '09:00:00', 'end' => '10:40:00', 'mode' => 'ONLINE', 'room' => 'SPADA', 'class' => 'IF-22-A'],
            // Dosen 8 (Target): Friday 08:00-09:40
            ['lec' => $createdLecturers[7], 'course' => $courses[3], 'day' => 5, 'start' => '08:00:00', 'end' => '09:40:00', 'mode' => 'ONLINE', 'room' => 'SPADA', 'class' => 'SI-22-A'],
        ];

        foreach ($schedulesSpec as $spec) {
            $fingerprint = hash('sha256', "{$term->id}_{$spec['lec']->id}_{$spec['course']->id}_{$spec['day']}_{$spec['start']}_{$spec['class']}");
            TeachingSchedule::firstOrCreate(
                ['academic_term_id' => $term->id, 'fingerprint' => $fingerprint],
                [
                    'lecturer_id' => $spec['lec']->id,
                    'course_id' => $spec['course']->id,
                    'faculty_id' => $spec['lec']->faculty_id,
                    'study_program_id' => $spec['lec']->study_program_id,
                    'class_name' => $spec['class'],
                    'room_name' => $spec['room'],
                    'delivery_mode' => $spec['mode'],
                    'day_of_week' => $spec['day'],
                    'starts_local' => $spec['start'],
                    'ends_local' => $spec['end'],
                    'effective_from' => '2026-09-01',
                    'effective_to' => '2027-01-31',
                    'is_active' => true,
                ]
            );
        }

        // Generate Occurrences
        $generator = new OccurrenceGeneratorService;
        $generator->generateForTerm($term);

        // 6. Supervision Period
        $period = SupervisionPeriod::firstOrCreate(
            ['academic_term_id' => $term->id, 'name' => 'Supervisi Semester Ganjil 2026/2027'],
            [
                'starts_on' => '2026-10-01',
                'ends_on' => '2026-11-30',
                'status' => 'ACTIVE',
                'is_demo' => true,
                'created_by' => $admin->id,
            ]
        );

        // 7. Scheduling Rules
        $rule = SchedulingRule::firstOrCreate(
            ['supervision_period_id' => $period->id, 'version' => 1],
            [
                'schema_version' => '1.0',
                'config' => [
                    'schema_version' => '1.0',
                    'block_supervision_when_teaching_count_gte' => 3,
                    'buffer_minutes' => 0,
                    'max_supervisions_per_day' => null,
                    'max_supervisions_per_week' => null,
                    'max_daily_span_minutes' => null,
                    'cross_unit_policy' => 'ANY',
                    'stage_supervisor_policy' => 'ANY',
                    'min_stage_gap_days' => 0,
                    'one_observation_per_target_per_stage' => true,
                    'require_t2_after_t1' => true,
                ],
                'status' => 'APPROVED',
                'is_demo' => true,
                'created_by' => $admin->id,
                'approved_by' => $admin->id,
                'approved_at' => now(),
                'approval_note' => 'Konfigurasi aturan penjadwalan demo awal',
            ]
        );
        $period->update(['active_scheduling_rule_id' => $rule->id]);

        // 8. Supervision Stages (two observations in the same semester)
        $stage1 = SupervisionStage::firstOrCreate(
            ['supervision_period_id' => $period->id, 'stage_no' => 1],
            [
                'name' => 'Tahap 1',
                'starts_on' => '2026-10-01',
                'ends_on' => '2026-10-21',
                'status' => 'DRAFT',
            ]
        );

        $stage2 = SupervisionStage::firstOrCreate(
            ['supervision_period_id' => $period->id, 'stage_no' => 2],
            [
                'name' => 'Tahap 2',
                'starts_on' => '2026-11-01',
                'ends_on' => '2026-11-21',
                'status' => 'DRAFT',
            ]
        );

        // 9. Period Targets (All lecturers included)
        foreach ($createdLecturers as $lec) {
            PeriodTarget::firstOrCreate(
                ['supervision_period_id' => $period->id, 'lecturer_id' => $lec->id],
                ['scope_status' => 'INCLUDED', 'selected_by' => $admin->id]
            );
        }

        // 10. Period Supervisors (3 supervisors selected)
        for ($i = 0; $i < 3; $i++) {
            PeriodSupervisor::firstOrCreate(
                ['supervision_period_id' => $period->id, 'lecturer_id' => $createdLecturers[$i]->id],
                ['selected_by' => $admin->id, 'is_active' => true]
            );
        }

        // 11. Bind Stage Instruments
        $offTemplate = FormTemplate::where('code', 'DEMO_OFFLINE')->first();
        $onTemplate = FormTemplate::where('code', 'DEMO_ONLINE')->first();
        $policyMean = ScoringPolicy::where('code', 'DEMO_POLICY_MEAN')->first();

        if ($offTemplate && $policyMean) {
            StageInstrument::firstOrCreate(
                ['supervision_stage_id' => $stage1->id, 'delivery_mode' => 'OFFLINE'],
                ['form_template_id' => $offTemplate->id, 'scoring_policy_id' => $policyMean->id, 'bound_by' => $admin->id]
            );
            StageInstrument::firstOrCreate(
                ['supervision_stage_id' => $stage2->id, 'delivery_mode' => 'OFFLINE'],
                ['form_template_id' => $offTemplate->id, 'scoring_policy_id' => $policyMean->id, 'bound_by' => $admin->id]
            );
        }

        if ($onTemplate && $policyMean) {
            StageInstrument::firstOrCreate(
                ['supervision_stage_id' => $stage1->id, 'delivery_mode' => 'ONLINE'],
                ['form_template_id' => $onTemplate->id, 'scoring_policy_id' => $policyMean->id, 'bound_by' => $admin->id]
            );
            StageInstrument::firstOrCreate(
                ['supervision_stage_id' => $stage2->id, 'delivery_mode' => 'ONLINE'],
                ['form_template_id' => $onTemplate->id, 'scoring_policy_id' => $policyMean->id, 'bound_by' => $admin->id]
            );
        }
    }
}
