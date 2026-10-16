<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CompleteDemoDataSeeder extends Seeder
{
    private const SUPERVISOR_PASSWORD = 'Password123!';

    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            UserSeeder::class,
            DemoInstrumentSeeder::class,
        ]);

        $admin = DB::table('users')->where('email', 'lpm@teknokrat.ac.id')->first();

        if (! $admin) {
            throw new \RuntimeException('Akun Administrator LPM tidak ditemukan.');
        }

        $this->resetOperationalData();

        $academicTermId = $this->createAcademicTerm();
        $studyPrograms = $this->createStudyPrograms();
        $courses = $this->createCourses($studyPrograms);
        [$supervisors, $supervisorUsers, $targets] = $this->createLecturers($studyPrograms);
        [$periodId, $stages, $ruleId] = $this->createPeriod($academicTermId, $admin->id);

        $this->createPeriodParticipants($periodId, $admin->id, $supervisors, $targets);
        $instruments = $this->bindInstruments($stages, $admin->id);
        $this->createCompleteReports(
            $academicTermId,
            $periodId,
            $stages,
            $ruleId,
            $courses,
            $supervisors,
            $supervisorUsers,
            $targets,
            $instruments,
            $admin->id,
        );

        DB::table('scheduling_state')->updateOrInsert(
            ['id' => 1],
            ['revision' => 2, 'updated_at' => now()],
        );
    }

    private function resetOperationalData(): void
    {
        $lecturerUserIds = DB::table('users')->whereNotNull('lecturer_id')->pluck('id');

        DB::transaction(function () use ($lecturerUserIds): void {
            foreach ([
                'supervision_evidence',
                'supervision_answers',
                'supervisions',
                'assignment_audits',
                'supervision_assignments',
                'generated_documents',
                'schedule_runs',
                'stage_instruments',
                'period_supervisors',
                'period_targets',
                'operation_requests',
                'system_audits',
            ] as $table) {
                DB::table($table)->delete();
            }

            DB::table('supervision_periods')->update(['active_scheduling_rule_id' => null]);
            DB::table('scheduling_rules')->delete();
            DB::table('supervision_stages')->delete();
            DB::table('supervision_periods')->delete();
            DB::table('teaching_occurrences')->delete();
            DB::table('teaching_schedules')->delete();
            DB::table('schedule_import_rows')->delete();
            DB::table('schedule_imports')->delete();
            DB::table('courses')->delete();

            if ($lecturerUserIds->isNotEmpty()) {
                DB::table('sessions')->whereIn('user_id', $lecturerUserIds)->delete();
                DB::table('model_has_permissions')
                    ->where('model_type', 'App\\Models\\User')
                    ->whereIn('model_id', $lecturerUserIds)
                    ->delete();
                DB::table('model_has_roles')
                    ->where('model_type', 'App\\Models\\User')
                    ->whereIn('model_id', $lecturerUserIds)
                    ->delete();
                DB::table('users')->whereIn('id', $lecturerUserIds)->delete();
            }

            DB::table('lecturers')->delete();
        });
    }

    private function createAcademicTerm(): int
    {
        DB::table('academic_terms')->updateOrInsert(
            ['semester' => 'GANJIL', 'academic_year' => '2026/2027'],
            [
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-01-31',
                'timezone' => 'Asia/Jakarta',
                'row_version' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return (int) DB::table('academic_terms')
            ->where('semester', 'GANJIL')
            ->where('academic_year', '2026/2027')
            ->value('id');
    }

    /**
     * @return array<string, object>
     */
    private function createStudyPrograms(): array
    {
        $faculties = [
            'FTIK' => 'Fakultas Teknik dan Ilmu Komputer',
            'FEB' => 'Fakultas Ekonomi dan Bisnis',
            'FSIP' => 'Fakultas Sastra dan Ilmu Pendidikan',
        ];
        $studyPrograms = [
            ['faculty' => 'FTIK', 'code' => 'MIK', 'name' => 'Magister Ilmu Komputer'],
            ['faculty' => 'FTIK', 'code' => 'IF', 'name' => 'Informatika'],
            ['faculty' => 'FTIK', 'code' => 'SI', 'name' => 'Sistem Informasi'],
            ['faculty' => 'FTIK', 'code' => 'TI', 'name' => 'Teknologi Informasi'],
            ['faculty' => 'FTIK', 'code' => 'TE', 'name' => 'Teknik Elektro'],
            ['faculty' => 'FTIK', 'code' => 'TK', 'name' => 'Teknik Komputer'],
            ['faculty' => 'FTIK', 'code' => 'TS', 'name' => 'Teknik Sipil'],
            ['faculty' => 'FEB', 'code' => 'MN', 'name' => 'Manajemen'],
            ['faculty' => 'FEB', 'code' => 'AK', 'name' => 'Akuntansi'],
            ['faculty' => 'FSIP', 'code' => 'PO', 'name' => 'Pendidikan Olahraga'],
            ['faculty' => 'FSIP', 'code' => 'SS', 'name' => 'Sastra Inggris'],
            ['faculty' => 'FSIP', 'code' => 'PI', 'name' => 'Pendidikan Bahasa Inggris'],
            ['faculty' => 'FSIP', 'code' => 'PM', 'name' => 'Pendidikan Matematika'],
            ['faculty' => 'FSIP', 'code' => 'MBI', 'name' => 'Magister Bahasa Inggris'],
        ];
        $facultyIds = [];

        foreach ($faculties as $code => $name) {
            DB::table('faculties')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()],
            );
            $facultyIds[$code] = (int) DB::table('faculties')->where('code', $code)->value('id');
        }

        $result = [];
        foreach ($studyPrograms as $studyProgram) {
            $facultyId = $facultyIds[$studyProgram['faculty']];
            DB::table('study_programs')->updateOrInsert(
                ['faculty_id' => $facultyId, 'code' => $studyProgram['code']],
                ['name' => $studyProgram['name'], 'is_active' => true, 'updated_at' => now(), 'created_at' => now()],
            );
            $result[$studyProgram['code']] = DB::table('study_programs')
                ->where('faculty_id', $facultyId)
                ->where('code', $studyProgram['code'])
                ->first();
        }

        return $result;
    }

    /**
     * @param  array<string, object>  $studyPrograms
     * @return array<string, int>
     */
    private function createCourses(array $studyPrograms): array
    {
        $courseNames = [
            'IF' => 'Rekayasa Perangkat Lunak',
            'SI' => 'Analisis Proses Bisnis',
            'TI' => 'Arsitektur Cloud',
            'TE' => 'Sistem Kendali Digital',
            'TK' => 'Jaringan Komputer Lanjut',
            'TS' => 'Manajemen Konstruksi',
            'MN' => 'Manajemen Strategis',
            'AK' => 'Audit Sistem Informasi',
            'PO' => 'Evaluasi Pembelajaran Olahraga',
            'SS' => 'English for Academic Purposes',
            'PI' => 'Technology Enhanced Language Learning',
            'PM' => 'Media Pembelajaran Matematika',
            'MBI' => 'Advanced Applied Linguistics',
            'MIK' => 'Sains Data Lanjut',
        ];
        $courses = [];

        foreach ($studyPrograms as $code => $studyProgram) {
            $courseCode = $code.'-DM01';
            $courses[$code] = (int) DB::table('courses')->insertGetId([
                'code' => $courseCode,
                'name' => $courseNames[$code],
                'normalized_name' => mb_strtolower($courseNames[$code]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $courses;
    }

    /**
     * @param  array<string, object>  $studyPrograms
     * @return array{0: array<int, object>, 1: array<int, int>, 2: array<int, object>}
     */
    private function createLecturers(array $studyPrograms): array
    {
        $supervisorData = [
            ['name' => 'Dr. Arya Pratama, M.Kom.', 'program' => 'IF'],
            ['name' => 'Dr. Nadia Permata, M.T.I.', 'program' => 'SI'],
            ['name' => 'Ir. Fajar Ramadhan, M.T.', 'program' => 'TE'],
            ['name' => 'Dr. Maya Kartika, S.E., M.M.', 'program' => 'MN'],
            ['name' => 'Dr. Rizka Amelia, M.Pd.', 'program' => 'PI'],
            ['name' => 'Dr. Kevin Wijaya, M.Hum.', 'program' => 'SS'],
        ];
        $targetNames = [
            'Aditya Saputra, M.Kom.', 'Aulia Rahmawati, M.Cs.', 'Bagus Firmansyah, M.T.I.',
            'Bella Oktaviani, M.Kom.', 'Cahyo Nugroho, M.T.', 'Citra Lestari, M.Eng.',
            'Damar Wicaksono, M.T.', 'Dian Puspitasari, M.Kom.', 'Eka Kurniawan, M.T.',
            'Elisa Maharani, M.M.', 'Farhan Akbar, M.Ak.', 'Febrianti Kusuma, M.Ak.',
            'Galih Setiawan, M.Pd.', 'Gita Safitri, M.Pd.', 'Hadi Prakoso, M.Hum.',
            'Hana Aprilia, M.Hum.', 'Indra Gunawan, M.Pd.', 'Intan Larasati, M.Pd.',
            'Joko Susanto, M.Si.', 'Juliana Putri, M.Si.', 'Kurnia Adinata, M.Pd.',
            'Laras Wulandari, M.Pd.', 'Mochamad Iqbal, M.Li.', 'Nabila Anjani, M.Li.',
            'Oscar Mahendra, M.Kom.', 'Putri Ayuningtyas, M.M.',
        ];
        $programCodes = array_keys($studyPrograms);
        $supervisors = [];
        $supervisorUsers = [];

        foreach ($supervisorData as $index => $data) {
            $studyProgram = $studyPrograms[$data['program']];
            $lecturerId = $this->insertLecturer(
                'SPV'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                $data['name'],
                $studyProgram,
            );
            $email = 'supervisor'.($index + 1).'@teknokrat.ac.id';
            $userId = (int) DB::table('users')->insertGetId([
                'lecturer_id' => $lecturerId,
                'name' => $data['name'],
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make(self::SUPERVISOR_PASSWORD),
                'is_active' => true,
                'must_change_password' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $roleId = DB::table('roles')->where('name', 'supervisor')->where('guard_name', 'web')->value('id');
            DB::table('model_has_roles')->insert([
                'role_id' => $roleId,
                'model_type' => 'App\\Models\\User',
                'model_id' => $userId,
            ]);
            $supervisors[] = DB::table('lecturers')->where('id', $lecturerId)->first();
            $supervisors[array_key_last($supervisors)]->program_code = $data['program'];
            $supervisorUsers[$lecturerId] = $userId;
        }

        $targets = [];
        foreach ($targetNames as $index => $name) {
            $programCode = $programCodes[$index % count($programCodes)];
            $lecturerId = $this->insertLecturer(
                'DSN'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                $name,
                $studyPrograms[$programCode],
            );
            $target = DB::table('lecturers')->where('id', $lecturerId)->first();
            $target->program_code = $programCode;
            $targets[] = $target;
        }

        return [$supervisors, $supervisorUsers, $targets];
    }

    private function insertLecturer(string $externalCode, string $name, object $studyProgram): int
    {
        return (int) DB::table('lecturers')->insertGetId([
            'faculty_id' => $studyProgram->faculty_id,
            'study_program_id' => $studyProgram->id,
            'external_code' => $externalCode,
            'name' => $name,
            'normalized_name' => mb_strtolower($name),
            'is_active' => true,
            'row_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{0: int, 1: array<int, int>, 2: int}
     */
    private function createPeriod(int $academicTermId, int $adminId): array
    {
        $periodId = (int) DB::table('supervision_periods')->insertGetId([
            'academic_term_id' => $academicTermId,
            'name' => 'Supervisi Lengkap Semester Ganjil 2026/2027',
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-11-30',
            'status' => 'ACTIVE',
            'is_demo' => true,
            'created_by' => $adminId,
            'row_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ruleId = (int) DB::table('scheduling_rules')->insertGetId([
            'supervision_period_id' => $periodId,
            'version' => 1,
            'schema_version' => '1.0',
            'config' => json_encode([
                'schema_version' => '1.0',
                'block_supervision_when_teaching_count_gte' => 3,
                'buffer_minutes' => 10,
                'max_supervisions_per_day' => 6,
                'max_supervisions_per_week' => null,
                'max_daily_span_minutes' => null,
                'cross_unit_policy' => 'ANY',
                'stage_supervisor_policy' => 'ANY',
                'min_stage_gap_days' => 7,
                'one_observation_per_target_per_stage' => true,
                'require_t2_after_t1' => true,
            ], JSON_THROW_ON_ERROR),
            'status' => 'APPROVED',
            'is_demo' => true,
            'created_by' => $adminId,
            'approved_by' => $adminId,
            'approved_at' => now(),
            'approval_note' => 'Aturan penjadwalan untuk dataset dummy lengkap.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('supervision_periods')->where('id', $periodId)->update(['active_scheduling_rule_id' => $ruleId]);

        $stages = [];
        foreach ([
            1 => ['name' => 'Tahap 1', 'start' => '2026-10-05', 'end' => '2026-10-23'],
            2 => ['name' => 'Tahap 2', 'start' => '2026-11-02', 'end' => '2026-11-20'],
        ] as $stageNumber => $stageData) {
            $stages[$stageNumber] = (int) DB::table('supervision_stages')->insertGetId([
                'supervision_period_id' => $periodId,
                'stage_no' => $stageNumber,
                'name' => $stageData['name'],
                'starts_on' => $stageData['start'],
                'ends_on' => $stageData['end'],
                'status' => $stageNumber === 1 ? 'FINALIZED' : 'DRAFT',
                'schedule_revision' => $stageNumber === 1 ? 1 : 0,
                'finalized_by' => $stageNumber === 1 ? $adminId : null,
                'finalized_at' => $stageNumber === 1 ? now() : null,
                'row_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$periodId, $stages, $ruleId];
    }

    /**
     * @param  array<int, object>  $supervisors
     * @param  array<int, object>  $targets
     */
    private function createPeriodParticipants(int $periodId, int $adminId, array $supervisors, array $targets): void
    {
        foreach ($targets as $target) {
            DB::table('period_targets')->insert([
                'supervision_period_id' => $periodId,
                'lecturer_id' => $target->id,
                'scope_status' => 'INCLUDED',
                'selected_by' => $adminId,
                'row_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($supervisors as $supervisor) {
            DB::table('period_supervisors')->insert([
                'supervision_period_id' => $periodId,
                'lecturer_id' => $supervisor->id,
                'selected_by' => $adminId,
                'is_active' => true,
                'row_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<int, int>  $stages
     * @return array<int, array<string, object>>
     */
    private function bindInstruments(array $stages, int $adminId): array
    {
        $templates = DB::table('form_templates')
            ->whereIn('code', ['DEMO_OFFLINE', 'DEMO_ONLINE'])
            ->get()
            ->keyBy('delivery_mode');
        $policy = DB::table('scoring_policies')->where('code', 'DEMO_POLICY_MEAN')->first();

        if ($templates->count() !== 2 || ! $policy) {
            throw new \RuntimeException('Instrumen demo OFFLINE/ONLINE atau kebijakan penilaian tidak lengkap.');
        }

        $result = [];
        foreach ($stages as $stageNumber => $stageId) {
            foreach (['OFFLINE', 'ONLINE'] as $mode) {
                $template = $templates[$mode];
                $stageInstrumentId = (int) DB::table('stage_instruments')->insertGetId([
                    'supervision_stage_id' => $stageId,
                    'delivery_mode' => $mode,
                    'form_template_id' => $template->id,
                    'scoring_policy_id' => $policy->id,
                    'bound_by' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $result[$stageNumber][$mode] = (object) [
                    'id' => $stageInstrumentId,
                    'template_id' => $template->id,
                    'policy_id' => $policy->id,
                ];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $stages
     * @param  array<string, int>  $courses
     * @param  array<int, object>  $supervisors
     * @param  array<int, int>  $supervisorUsers
     * @param  array<int, object>  $targets
     * @param  array<int, array<string, object>>  $instruments
     */
    private function createCompleteReports(
        int $academicTermId,
        int $periodId,
        array $stages,
        int $ruleId,
        array $courses,
        array $supervisors,
        array $supervisorUsers,
        array $targets,
        array $instruments,
        int $adminId,
    ): void {
        $stageStartDates = [
            1 => CarbonImmutable::parse('2026-10-05', 'Asia/Jakarta'),
            2 => CarbonImmutable::parse('2026-11-02', 'Asia/Jakarta'),
        ];
        $undergraduateStartTimes = ['07:00', '09:00', '11:00', '13:00', '15:00', '17:00'];
        $graduateStartTimes = ['08:00', '10:00'];
        $t1DesiredScores = [1.65, 1.90, 2.10, 2.25, 2.40, 2.55, 2.65, 2.75, 2.85, 2.95, 3.05, 3.15, 3.25, 3.35, 3.45, 3.55, 3.60, 3.65, 3.70, 3.75, 3.80, 3.85, 3.90, 3.95, 3.30, 2.70];
        $improvements = [0.55, 0.45, 0.60, 0.35, 0.50, 0.30, 0.40, 0.25, 0.35, 0.20, 0.45, 0.30, 0.25, 0.35, 0.15, 0.25, 0.20, 0.15, 0.20, 0.10, 0.15, 0.10, 0.05, 0.05, -0.10, 0.30];

        $this->createSupervisorTeachingSchedules(
            $academicTermId,
            $courses,
            $supervisors,
            $stageStartDates,
        );

        foreach ($targets as $index => $target) {
            $mode = $index % 3 === 0 ? 'ONLINE' : 'OFFLINE';
            $dayOffset = $index % 5;
            $slotIndex = intdiv($index, 5);
            $courseId = $courses[$target->program_code];
            $isGraduateSchedule = in_array($target->program_code, ['MBI', 'MIK'], true);
            $startTime = $isGraduateSchedule
                ? $graduateStartTimes[intdiv($index, (int) (count($targets) / 2)) % count($graduateStartTimes)]
                : $undergraduateStartTimes[$slotIndex];
            $scheduleId = (int) DB::table('teaching_schedules')->insertGetId([
                'academic_term_id' => $academicTermId,
                'lecturer_id' => $target->id,
                'course_id' => $courseId,
                'faculty_id' => $target->faculty_id,
                'study_program_id' => $target->study_program_id,
                'class_name' => $target->program_code.' '.(24 + ($index % 3)).' '.chr(65 + intdiv($index, 13)),
                'room_name' => $mode === 'ONLINE' ? 'SPADA / Zoom' : 'Gedung A '.(301 + $index),
                'delivery_mode' => $mode,
                'day_of_week' => $dayOffset + 1,
                'starts_local' => $startTime.':00',
                'ends_local' => CarbonImmutable::parse($startTime)->addMinutes(100)->format('H:i:s'),
                'effective_from' => '2026-09-01',
                'effective_to' => '2027-01-31',
                'source_payload' => json_encode([
                    'degree' => $isGraduateSchedule ? 'S2' : 'S1',
                    'classification_basis' => $isGraduateSchedule
                        ? 'Di luar slot baku S1'
                        : 'Slot baku S1',
                ], JSON_THROW_ON_ERROR),
                'fingerprint' => hash('sha256', 'complete-demo-'.$target->id),
                'is_active' => true,
                'row_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ([1 => $stages[1]] as $stageNumber => $stageId) {
                $date = $stageStartDates[$stageNumber]->addDays($dayOffset);
                $startsAt = CarbonImmutable::parse($date->format('Y-m-d').' '.$startTime, 'Asia/Jakarta');
                $endsAt = $startsAt->addMinutes(100);
                $occurrenceId = (int) DB::table('teaching_occurrences')->insertGetId([
                    'teaching_schedule_id' => $scheduleId,
                    'original_date' => $date->toDateString(),
                    'occurs_on' => $date->toDateString(),
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'status' => 'SCHEDULED',
                    'is_exception' => false,
                    'identity_snapshot' => json_encode([
                        'lecturer_name' => $target->name,
                        'course_id' => $courseId,
                        'delivery_mode' => $mode,
                        'degree' => $isGraduateSchedule ? 'S2' : 'S1',
                    ], JSON_THROW_ON_ERROR),
                    'row_version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $supervisor = $supervisors[$index % count($supervisors)];
                $assignmentId = (int) DB::table('supervision_assignments')->insertGetId([
                    'supervision_stage_id' => $stageId,
                    'target_lecturer_id' => $target->id,
                    'supervisor_lecturer_id' => $supervisor->id,
                    'teaching_occurrence_id' => $occurrenceId,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'assignment_type' => 'AUTO',
                    'is_locked' => true,
                    'status' => 'FINAL',
                    'conflict_status' => 'VALID',
                    'conflict_codes' => json_encode([], JSON_THROW_ON_ERROR),
                    'conflict_details' => json_encode([], JSON_THROW_ON_ERROR),
                    'scheduling_rule_id' => $ruleId,
                    'input_revision' => 1,
                    'identity_snapshot' => json_encode([
                        'period_id' => $periodId,
                        'stage_no' => $stageNumber,
                        'target_name' => $target->name,
                        'supervisor_name' => $supervisor->name,
                    ], JSON_THROW_ON_ERROR),
                    'assigned_by' => $adminId,
                    'assigned_at' => now(),
                    'change_reason' => 'Dataset dummy lengkap untuk pemeriksaan seluruh tampilan.',
                    'row_version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $desiredScore = $stageNumber === 1
                    ? $t1DesiredScores[$index]
                    : min(4.0, $t1DesiredScores[$index] + $improvements[$index]);
                $this->createSubmittedSupervision(
                    $assignmentId,
                    $instruments[$stageNumber][$mode],
                    $courseId,
                    $mode,
                    $startsAt,
                    $endsAt,
                    $supervisorUsers[$supervisor->id],
                    $target,
                    $supervisor,
                    $stageNumber,
                    $desiredScore,
                );
            }
        }
    }

    private function createSubmittedSupervision(
        int $assignmentId,
        object $instrument,
        int $courseId,
        string $mode,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        int $supervisorUserId,
        object $target,
        object $supervisor,
        int $stageNumber,
        float $desiredScore,
    ): void {
        $questions = DB::table('questions')
            ->where('form_template_id', $instrument->template_id)
            ->orderBy('sort_order')
            ->get();
        $scoredQuestions = $questions->where('is_scored', true)->values();
        $higherScoreAnswers = (int) round(($desiredScore - floor($desiredScore)) * $scoredQuestions->count());
        $numericScores = [];
        $answerSnapshots = [];
        $submittedAt = $endsAt->addMinutes(15);

        $supervisionId = (int) DB::table('supervisions')->insertGetId([
            'supervision_assignment_id' => $assignmentId,
            'stage_instrument_id' => $instrument->id,
            'form_template_id' => $instrument->template_id,
            'scoring_policy_id' => $instrument->policy_id,
            'status' => 'SUBMITTED',
            'supervision_date' => $startsAt->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'delivery_mode' => $mode,
            'course_id' => $courseId,
            'material_topic' => 'Materi pertemuan supervisi tahap '.$stageNumber.' telah disiapkan lengkap.',
            'identity_snapshot' => json_encode([
                'target_code' => $target->external_code,
                'target_name' => $target->name,
                'supervisor_code' => $supervisor->external_code,
                'supervisor_name' => $supervisor->name,
                'delivery_mode' => $mode,
            ], JSON_THROW_ON_ERROR),
            'raw_score' => 0,
            'scored_question_count' => $scoredQuestions->count(),
            'final_score' => 0,
            'submitted_by' => $supervisorUserId,
            'submitted_at' => $submittedAt,
            'row_version' => 2,
            'created_at' => $startsAt,
            'updated_at' => $submittedAt,
        ]);

        $scoreIndex = 0;
        foreach ($questions as $question) {
            $answer = [
                'supervision_id' => $supervisionId,
                'question_id' => $question->id,
                'question_option_id' => null,
                'boolean_value' => null,
                'text_value' => null,
                'numeric_score' => null,
                'is_applicable' => true,
                'created_at' => $startsAt,
                'updated_at' => $submittedAt,
            ];

            if ($question->question_type === 'SCORE') {
                $score = (int) floor($desiredScore) + ($scoreIndex < $higherScoreAnswers ? 1 : 0);
                $score = max(0, min(4, $score));
                $option = DB::table('question_options')
                    ->where('question_id', $question->id)
                    ->where('numeric_score', $score)
                    ->first();
                $answer['question_option_id'] = $option?->id;
                $answer['numeric_score'] = $score;
                $numericScores[] = $score;
                $scoreIndex++;
            } elseif ($question->question_type === 'YES_NO') {
                $option = DB::table('question_options')
                    ->where('question_id', $question->id)
                    ->orderByDesc('numeric_score')
                    ->orderBy('sort_order')
                    ->first();
                $answer['question_option_id'] = $option?->id;
                $answer['boolean_value'] = true;
            } elseif ($question->question_type === 'LINK') {
                $answer['text_value'] = 'https://spada.teknokrat.ac.id/course/demo-'.$target->external_code;
            } elseif ($question->question_type === 'FILE') {
                $answer['text_value'] = 'Bukti pembelajaran '.$target->external_code.' tahap '.$stageNumber.'.pdf';
            } elseif ($question->question_type === 'TEXTAREA') {
                $answer['text_value'] = $this->evaluationText($desiredScore, $stageNumber, (int) $target->id);
            }

            $answer['answer_snapshot'] = json_encode([
                'question_code' => $question->code,
                'question_type' => $question->question_type,
                'value' => $answer['numeric_score'] ?? $answer['boolean_value'] ?? $answer['text_value'],
            ], JSON_THROW_ON_ERROR);
            $answerId = (int) DB::table('supervision_answers')->insertGetId($answer);
            $answerSnapshots[] = json_decode($answer['answer_snapshot'], true, 512, JSON_THROW_ON_ERROR);

            if ($question->question_type === 'FILE') {
                DB::table('supervision_evidence')->insert([
                    'supervision_answer_id' => $answerId,
                    'uploaded_by' => $supervisorUserId,
                    'evidence_type' => 'URL',
                    'storage_disk' => 'local',
                    'external_url' => 'https://example.com/bukti/'.$target->external_code.'/tahap-'.$stageNumber,
                    'original_filename' => 'bukti-'.$target->external_code.'-t'.$stageNumber.'.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 524288 + ($target->id * 100),
                    'sha256' => hash('sha256', $supervisionId.'-'.$question->id),
                    'status' => 'READY',
                    'validated_at' => $submittedAt,
                    'created_at' => $startsAt,
                    'updated_at' => $submittedAt,
                ]);
            }
        }

        $finalScore = round(array_sum($numericScores) / count($numericScores), 6);
        DB::table('supervisions')->where('id', $supervisionId)->update([
            'raw_score' => round(array_sum($numericScores) / count($numericScores), 4),
            'final_score' => $finalScore,
            'submission_snapshot' => json_encode([
                'metadata' => [
                    'submitted_at' => $submittedAt->toIso8601String(),
                    'submitted_by_user_id' => $supervisorUserId,
                    'supervisor_name' => $supervisor->name,
                    'target_lecturer_name' => $target->name,
                    'stage_no' => $stageNumber,
                    'delivery_mode' => $mode,
                ],
                'scores' => [
                    'raw_score' => $finalScore,
                    'final_score' => $finalScore,
                    'n_items' => count($numericScores),
                ],
                'answers' => $answerSnapshots,
                'evidence_count' => 1,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param  array<string, int>  $courses
     * @param  array<int, object>  $supervisors
     * @param  array<int, CarbonImmutable>  $stageStartDates
     */
    private function createSupervisorTeachingSchedules(
        int $academicTermId,
        array $courses,
        array $supervisors,
        array $stageStartDates,
    ): void {
        $teachingSlots = [
            [['day' => 1, 'start' => '11:00'], ['day' => 3, 'start' => '15:00']],
            [['day' => 2, 'start' => '13:00'], ['day' => 4, 'start' => '15:00']],
            [['day' => 3, 'start' => '13:00'], ['day' => 5, 'start' => '15:00']],
            [['day' => 4, 'start' => '11:00'], ['day' => 1, 'start' => '09:00']],
            [['day' => 5, 'start' => '13:00'], ['day' => 2, 'start' => '09:00']],
            [['day' => 5, 'start' => '09:00'], ['day' => 4, 'start' => '11:00']],
        ];

        foreach ($supervisors as $supervisorIndex => $supervisor) {
            foreach ($teachingSlots[$supervisorIndex] as $teachingIndex => $slot) {
                $courseId = $courses[$supervisor->program_code];
                $endsAtTime = CarbonImmutable::parse($slot['start'])->addMinutes(100)->format('H:i:s');
                $scheduleId = (int) DB::table('teaching_schedules')->insertGetId([
                    'academic_term_id' => $academicTermId,
                    'lecturer_id' => $supervisor->id,
                    'course_id' => $courseId,
                    'faculty_id' => $supervisor->faculty_id,
                    'study_program_id' => $supervisor->study_program_id,
                    'class_name' => $supervisor->program_code.' '.(24 + $teachingIndex).' S'.($supervisorIndex + 1),
                    'room_name' => 'Gedung B '.(201 + ($supervisorIndex * 2) + $teachingIndex),
                    'delivery_mode' => $teachingIndex % 2 === 0 ? 'OFFLINE' : 'ONLINE',
                    'day_of_week' => $slot['day'],
                    'starts_local' => $slot['start'].':00',
                    'ends_local' => $endsAtTime,
                    'effective_from' => '2026-09-01',
                    'effective_to' => '2027-01-31',
                    'fingerprint' => hash('sha256', 'complete-demo-supervisor-'.$supervisor->id.'-'.$teachingIndex),
                    'is_active' => true,
                    'row_version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($stageStartDates as $stageStartDate) {
                    $date = $stageStartDate->addDays($slot['day'] - 1);
                    $startsAt = CarbonImmutable::parse($date->format('Y-m-d').' '.$slot['start'], 'Asia/Jakarta');
                    DB::table('teaching_occurrences')->insert([
                        'teaching_schedule_id' => $scheduleId,
                        'original_date' => $date->toDateString(),
                        'occurs_on' => $date->toDateString(),
                        'starts_at' => $startsAt,
                        'ends_at' => $startsAt->addMinutes(100),
                        'status' => 'SCHEDULED',
                        'is_exception' => false,
                        'identity_snapshot' => json_encode([
                            'lecturer_name' => $supervisor->name,
                            'course_id' => $courseId,
                            'delivery_mode' => $teachingIndex % 2 === 0 ? 'OFFLINE' : 'ONLINE',
                            'degree' => 'S1',
                        ], JSON_THROW_ON_ERROR),
                        'row_version' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    private function evaluationText(float $desiredScore, int $stageNumber, int $targetId): string
    {
        if ($desiredScore >= 3.5) {
            return "Tahap {$stageNumber}: seluruh indikator pembelajaran terlaksana sangat baik. Pertahankan konsistensi, variasi metode, dan praktik baik yang sudah berjalan.";
        }

        $findingTexts = [
            'kelengkapan SPADA, RPS, materi digital, dan forum diskusi perlu ditingkatkan.',
            'pengelolaan kelas dan keterlibatan mahasiswa perlu ditingkatkan agar interaksi mahasiswa lebih aktif.',
            'disiplin waktu dan kesesuaian durasi perkuliahan perlu diperbaiki.',
            'materi bilingual dan penggunaan Bahasa Inggris pada slide perlu ditingkatkan.',
            'refleksi, rangkuman, evaluasi pembelajaran, dan penguatan materi pada penutupan pembelajaran perlu ditingkatkan.',
        ];
        $primaryFinding = $findingTexts[$targetId % count($findingTexts)];

        if ($desiredScore >= 2.75) {
            return "Tahap {$stageNumber}: pembelajaran berjalan baik, namun {$primaryFinding}";
        }

        $secondaryFinding = $findingTexts[($targetId + 2) % count($findingTexts)];

        return "Tahap {$stageNumber}: diperlukan tindak lanjut karena {$primaryFinding} Selain itu, {$secondaryFinding}";
    }
}
