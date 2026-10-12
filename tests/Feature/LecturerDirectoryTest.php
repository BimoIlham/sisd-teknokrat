<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\FormTemplate;
use App\Models\Lecturer;
use App\Models\PeriodTarget;
use App\Models\ScheduleImport;
use App\Models\ScheduleImportRow;
use App\Models\SchedulingRule;
use App\Models\ScoringPolicy;
use App\Models\StudyProgram;
use App\Models\Supervision;
use App\Models\SupervisionAnswer;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\TeachingSchedule;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LecturerDirectoryTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::firstOrCreate([
            'email' => 'lpm@teknokrat.ac.id',
        ], [
            'name' => 'Administrator LPM',
            'password' => bcrypt('Password123!'),
        ]);
        if (! $this->admin->hasRole('superadmin')) {
            $this->seed(RoleAndPermissionSeeder::class);
            $this->admin->assignRole('superadmin');
        }

        if (Lecturer::count() === 0) {
            $this->seed(DatabaseSeeder::class);
            $this->seed(UserSeeder::class);
            $this->seed(DemoAcademicSeeder::class);
        }
    }

    public function test_guest_cannot_access_lecturers_directory(): void
    {
        $response = $this->get(route('lpm.lecturers.index'));
        $response->assertRedirect(route('login'));
    }

    public function test_lpm_admin_can_access_lecturers_directory_without_sql_error(): void
    {
        $response = $this->actingAs($this->admin)->get(route('lpm.lecturers.index'));

        $response->assertStatus(200);
        $response->assertViewIs('lpm.lecturers.index');
        $response->assertSee('Direktori Dosen');
    }

    public function test_directory_only_lists_lecturers_with_active_schedules_in_selected_period(): void
    {
        $period = SupervisionPeriod::with('academicTerm')->where('status', 'ACTIVE')->latest('starts_on')->firstOrFail();
        $faculty = Faculty::where('code', 'FTIK')->firstOrFail();
        $studyProgram = StudyProgram::where('faculty_id', $faculty->id)->firstOrFail();
        $scheduledLecturer = Lecturer::create([
            'name' => 'Dosen Jadwal FTIK Periode Ini',
            'external_code' => 'DSN-FTIK-PERIOD-TEST',
            'faculty_id' => $faculty->id,
            'study_program_id' => $studyProgram->id,
            'is_active' => true,
        ]);
        $unscheduledLecturer = Lecturer::create([
            'name' => 'Dosen FEB Tanpa Jadwal Periode Ini',
            'external_code' => 'DSN-FEB-NO-PERIOD-TEST',
            'faculty_id' => Faculty::where('code', 'FEB')->value('id'),
            'is_active' => true,
        ]);
        $previousPeriod = SupervisionPeriod::create([
            'academic_term_id' => $period->academic_term_id,
            'name' => 'Periode Lama Uji',
            'starts_on' => $period->starts_on->copy()->subYear(),
            'ends_on' => $period->ends_on->copy()->subYear(),
            'status' => 'CLOSED',
            'created_by' => $this->admin->id,
        ]);
        $previousLecturer = Lecturer::create([
            'name' => 'Dosen Hanya Periode Lama',
            'external_code' => 'DSN-ONLY-OLD-PERIOD',
            'faculty_id' => $faculty->id,
            'study_program_id' => $studyProgram->id,
            'is_active' => true,
        ]);

        $this->attachLecturerToPeriod($scheduledLecturer, $period->academic_term_id);
        PeriodTarget::create([
            'supervision_period_id' => $period->id,
            'lecturer_id' => $scheduledLecturer->id,
            'scope_status' => 'INCLUDED',
            'selected_by' => $this->admin->id,
        ]);
        $this->attachLecturerToPeriod($previousLecturer, $previousPeriod->academic_term_id);
        PeriodTarget::create([
            'supervision_period_id' => $previousPeriod->id,
            'lecturer_id' => $previousLecturer->id,
            'scope_status' => 'INCLUDED',
            'selected_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('lpm.lecturers.index', [
            'period_id' => $period->id,
        ]));

        $response->assertOk()
            ->assertSee($scheduledLecturer->name)
            ->assertDontSee($unscheduledLecturer->name)
            ->assertDontSee($previousLecturer->name);

        $this->actingAs($this->admin)->get(route('lpm.lecturers.index', [
            'period_id' => $previousPeriod->id,
        ]))->assertOk()->assertSee($previousLecturer->name)->assertDontSee($scheduledLecturer->name);
    }

    public function test_search_and_faculty_filter_on_lecturers_directory(): void
    {
        $lecturer = Lecturer::first();
        $this->assertNotNull($lecturer);

        $response = $this->actingAs($this->admin)->get(route('lpm.lecturers.index', [
            'search' => substr($lecturer->name, 0, 4),
        ]));

        $response->assertStatus(200);
        $response->assertSee($lecturer->name);

        if ($lecturer->faculty_id) {
            $facultyResponse = $this->actingAs($this->admin)->get(route('lpm.lecturers.index', [
                'faculty_id' => $lecturer->faculty_id,
            ]));
            $facultyResponse->assertStatus(200);
            $facultyResponse->assertSee($lecturer->name);
        }
    }

    public function test_directory_can_filter_lecturers_by_profile_completeness(): void
    {
        $faculty = Faculty::firstOrFail();
        $studyProgram = StudyProgram::where('faculty_id', $faculty->id)->firstOrFail();
        do {
            $nidn = (string) random_int(1000000000, 9999999999);
        } while (Lecturer::where('external_code', $nidn)->exists());

        $completeLecturer = Lecturer::create([
            'name' => 'Filter Kelengkapan Lengkap',
            'external_code' => $nidn,
            'faculty_id' => $faculty->id,
            'study_program_id' => $studyProgram->id,
            'is_active' => true,
        ]);
        $incompleteLecturer = Lecturer::create([
            'name' => 'Filter Kelengkapan Belum Lengkap',
            'external_code' => 'DSN-FILTER-INCOMPLETE',
            'faculty_id' => $faculty->id,
            'study_program_id' => $studyProgram->id,
            'is_active' => true,
        ]);
        $this->attachLecturerToSelectedPeriod($completeLecturer);
        $this->attachLecturerToSelectedPeriod($incompleteLecturer);

        $incompleteResponse = $this->actingAs($this->admin)->get(route('lpm.lecturers.index', [
            'search' => 'Filter Kelengkapan',
            'completion' => 'incomplete',
        ]));

        $incompleteResponse->assertOk()
            ->assertSee($incompleteLecturer->name)
            ->assertSee('completion=all', false)
            ->assertSee('completion=complete', false)
            ->assertDontSee($completeLecturer->name);

        $completeResponse = $this->actingAs($this->admin)->get(route('lpm.lecturers.index', [
            'search' => 'Filter Kelengkapan',
            'completion' => 'complete',
        ]));

        $completeResponse->assertOk()
            ->assertSee($completeLecturer->name)
            ->assertSee('completion=all', false)
            ->assertSee('completion=incomplete', false)
            ->assertDontSee($incompleteLecturer->name);
    }

    public function test_all_three_faculties_including_fsip_appear_in_filter_and_lecturer_profile(): void
    {
        $response = $this->actingAs($this->admin)->get(route('lpm.lecturers.index'));
        $response->assertStatus(200);

        $this->assertDatabaseHas('faculties', ['name' => 'Fakultas Sastra dan Ilmu Pendidikan']);
        $this->assertDatabaseHas('faculties', ['name' => 'Fakultas Ekonomi dan Bisnis']);
        $this->assertDatabaseHas('faculties', ['name' => 'Fakultas Teknik dan Ilmu Komputer']);

        $eko = Lecturer::where('name', 'LIKE', '%Eko Bagus%')->first();
        if ($eko) {
            $detailResponse = $this->actingAs($this->admin)->get(route('lpm.lecturers.show', $eko));
            $detailResponse->assertStatus(200);
            $detailResponse->assertSee('Fakultas Sastra dan Ilmu Pendidikan');
            $detailResponse->assertSee('Pendidikan Olahraga');
        }
    }

    public function test_lpm_admin_can_access_lecturer_detail_page(): void
    {
        $lecturer = Lecturer::first();
        $this->assertNotNull($lecturer);

        $response = $this->actingAs($this->admin)->get(route('lpm.lecturers.show', $lecturer));

        $response->assertStatus(200);
        $response->assertViewIs('lpm.lecturers.show');
        $response->assertSee($lecturer->name);
        $response->assertSee('Jadwal Mengajar');
        $response->assertSee('Penilaian Tahap 1');
        $response->assertSee('Penilaian Tahap 2');
        $response->assertSee('Perbandingan T1 vs T2');
        $response->assertSee('Temuan Umum');
    }

    public function test_lecturer_detail_page_handles_staging_schedule_rows(): void
    {
        $period = SupervisionPeriod::where('status', 'ACTIVE')->latest('starts_on')->firstOrFail();
        $term = $period->academicTerm;
        $faculty = Faculty::first();
        $prodi = StudyProgram::first();

        $lecturer = Lecturer::create([
            'name' => 'Dosen Uji Staging',
            'normalized_name' => 'dosen uji staging',
            'external_code' => 'DSN-STAGING',
            'faculty_id' => $faculty?->id,
            'study_program_id' => $prodi?->id,
            'is_active' => true,
        ]);
        $import = ScheduleImport::create([
            'academic_term_id' => $term->id,
            'uploaded_by' => $this->admin->id,
            'original_filename' => 'test.pdf',
            'storage_disk' => 'local',
            'stored_path' => 'imports/test.pdf',
            'sha256' => 'dummy',
            'size_bytes' => 1024,
            'mime_type' => 'application/pdf',
            'status' => 'PENDING_REVIEW',
        ]);

        ScheduleImportRow::create([
            'schedule_import_id' => $import->id,
            'row_no' => 1,
            'matched_lecturer_id' => $lecturer->id,
            'normalized_payload' => [
                'course_name' => 'Staging Mata Kuliah',
                'class_name' => 'IF 23 A',
                'day_of_week' => 1,
                'start_time' => '08:00',
                'end_time' => '09:40',
                'room_name' => 'Lab 1',
                'delivery_mode' => 'OFFLINE',
            ],
            'validation_status' => 'VALID',
            'is_approved' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('lpm.lecturers.show', $lecturer));
        $response->assertStatus(200);
        $response->assertSee('Dosen Uji Staging');
        $response->assertSee('Staging Mata Kuliah');
    }

    public function test_pdf_download_for_submitted_supervision(): void
    {
        $lecturer = Lecturer::first();
        $stage = SupervisionStage::where('stage_no', 1)->first() ?? SupervisionStage::first();
        $term = $stage->period->academicTerm ?? AcademicTerm::first();

        $rule = SchedulingRule::first();

        $occurrence = TeachingOccurrence::first();
        $form = FormTemplate::first();
        $policy = ScoringPolicy::first();
        $course = Course::firstOrCreate([
            'code' => 'TEST-MK',
        ], [
            'name' => 'Mata Kuliah Tes',
            'normalized_name' => 'mata kuliah tes',
            'is_active' => true,
        ]);

        // Create assignment and submitted supervision
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id,
            'target_lecturer_id' => $lecturer->id,
            'supervisor_lecturer_id' => Lecturer::where('id', '<>', $lecturer->id)->first()?->id ?? $lecturer->id,
            'teaching_occurrence_id' => $occurrence?->id,
            'scheduling_rule_id' => $rule->id,
            'assignment_type' => 'MANUAL',
            'status' => 'FINAL',
            'starts_at' => now(),
            'ends_at' => now()->addMinutes(90),
        ]);

        $supervision = Supervision::create([
            'supervision_assignment_id' => $assignment->id,
            'form_template_id' => $form?->id,
            'scoring_policy_id' => $policy?->id,
            'course_id' => $course->id,
            'status' => 'SUBMITTED',
            'final_score' => 3.750000,
            'raw_score' => 3.7500,
            'scored_question_count' => 20,
            'supervision_date' => now()->toDateString(),
            'starts_at' => now(),
            'ends_at' => now()->addMinutes(100),
            'delivery_mode' => 'OFFLINE',
            'row_version' => 1,
        ]);

        $scoredQuestion = $form->questions()
            ->with('options')
            ->where('is_scored', true)
            ->firstOrFail();
        $selectedOption = $scoredQuestion->options->last();

        SupervisionAnswer::create([
            'supervision_id' => $supervision->id,
            'question_id' => $scoredQuestion->id,
            'question_option_id' => $selectedOption?->id,
            'numeric_score' => $selectedOption?->numeric_score ?? 4,
            'is_applicable' => true,
        ]);

        $stageNo = (int) $stage->stage_no;
        $response = $this->actingAs($this->admin)->get(route('lpm.lecturers.stage_pdf', [
            'lecturer' => $lecturer->id,
            'stageNo' => $stageNo,
        ]));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');

        // Test direct supervision pdf download
        $supervisionPdfResponse = $this->actingAs($this->admin)->get(route('lpm.supervisions.pdf', $supervision));
        $supervisionPdfResponse->assertStatus(200);
        $supervisionPdfResponse->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->admin)
            ->get(route('lpm.lecturers.show', $lecturer))
            ->assertOk()
            ->assertSee('Cetak PDF Nilai T1')
            ->assertSee(route('lpm.supervisions.pdf', $supervision), false);
    }

    public function test_supervisor_lecturer_schedule_displays_supervision_assignments_in_timetable_grid_and_list(): void
    {
        $period = SupervisionPeriod::where('status', 'ACTIVE')->latest('starts_on')->firstOrFail();
        $term = $period->academicTerm;
        $stage = $period->stages()->where('stage_no', 1)->firstOrFail();

        // 1. Create a supervisor lecturer
        $supervisor = Lecturer::create([
            'name' => 'Dr. Supervisor Hebat, M.T.',
            'normalized_name' => 'dr. supervisor hebat, m.t.',
            'external_code' => 'DSN-SUP-01',
            'is_active' => true,
        ]);

        // 2. Create a target lecturer being supervised
        $targetLecturer = Lecturer::create([
            'name' => 'Dosen Binaan, M.Kom.',
            'normalized_name' => 'dosen binaan, m.kom.',
            'external_code' => 'DSN-TARGET-01',
            'is_active' => true,
        ]);

        // 3. Create teaching schedule for target lecturer
        $course = Course::first();
        $targetSchedule = TeachingSchedule::create([
            'academic_term_id' => $term->id,
            'lecturer_id' => $targetLecturer->id,
            'course_id' => $course->id,
            'class_name' => 'IF 22 SUPER',
            'room' => 'Lab Multimedia 1',
            'room_name' => 'Lab Multimedia 1',
            'delivery_mode' => 'OFFLINE',
            'day_of_week' => 2, // Selasa
            'starts_local' => '09:00:00',
            'ends_local' => '10:40:00',
            'is_active' => true,
        ]);

        $targetOccurrence = TeachingOccurrence::create([
            'teaching_schedule_id' => $targetSchedule->id,
            'original_date' => now()->toDateString(),
            'occurs_on' => now()->toDateString(),
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(10, 40),
            'status' => 'SCHEDULED',
        ]);

        // 4. Assign supervisor to supervise target lecturer
        $rule = SchedulingRule::first();
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id,
            'target_lecturer_id' => $targetLecturer->id,
            'supervisor_lecturer_id' => $supervisor->id,
            'teaching_occurrence_id' => $targetOccurrence->id,
            'scheduling_rule_id' => $rule->id,
            'assignment_type' => 'MANUAL',
            'status' => 'SCHEDULED',
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(10, 40),
        ]);

        // 5. Access supervisor detail page
        $response = $this->actingAs($this->admin)->get(route('lpm.lecturers.show', $supervisor));

        $response->assertStatus(200);
        $response->assertSee('Dr. Supervisor Hebat, M.T.');
        $response->assertSee('Supervisor');
        $response->assertSee('Jadwal Supervisi');
        $response->assertSee('IF 22 SUPER');
        $response->assertSee('Lab Multimedia 1');
        $response->assertSee('Dosen Binaan, M.Kom.');
        $response->assertSee('Dosen yang Disupervisi:');
        $response->assertSee('OFFLINE');

        $stageTwo = $period->stages()->where('stage_no', 2)->firstOrFail();
        $secondOccurrence = TeachingOccurrence::create([
            'teaching_schedule_id' => $targetSchedule->id,
            'original_date' => now()->addWeeks(4)->toDateString(),
            'occurs_on' => now()->addWeeks(4)->toDateString(),
            'starts_at' => now()->addWeeks(4)->setTime(9, 0),
            'ends_at' => now()->addWeeks(4)->setTime(10, 40),
            'status' => 'SCHEDULED',
        ]);
        $secondAssignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stageTwo->id,
            'target_lecturer_id' => $targetLecturer->id,
            'supervisor_lecturer_id' => $supervisor->id,
            'teaching_occurrence_id' => $secondOccurrence->id,
            'scheduling_rule_id' => $rule->id,
            'assignment_type' => 'MANUAL',
            'status' => 'SCHEDULED',
        ]);

        $combinedResponse = $this->get(route('lpm.lecturers.show', $supervisor))->assertOk();
        $combinedResponse->assertSee('Tahap 1 &amp; Tahap 2', false);
        $this->assertCount(1, $combinedResponse->viewData('supervisionSchedules'));
        $combinedSchedule = $combinedResponse->viewData('supervisionSchedules')->first();
        $this->assertSame([$assignment->id, $secondAssignment->id], $combinedSchedule->assignment_ids);
        $this->assertCount(2, $combinedSchedule->stage_dates);
        $this->assertSame(1, substr_count($combinedResponse->getContent(), 'class="bg-yellow-200 rounded-md p-2 mb-1 last:mb-0"'));
        $this->assertCount(1, $combinedResponse->viewData('allScheduleEvents')->where('is_supervision', true));
        $this->assertDatabaseCount('supervision_assignments', 2);

        $supervisorUser = User::factory()->create([
            'lecturer_id' => $supervisor->id,
            'must_change_password' => false,
        ]);
        $supervisorUser->assignRole('supervisor');
        foreach ([$stage, $stageTwo] as $inputStage) {
            $inputStage->update(['starts_on' => now()->subDay(), 'ends_on' => now()->addDay()]);
        }
        $this->app['session']->invalidate();
        $personalResponse = $this->actingAs($supervisorUser)->get(route('supervisor.schedule'))->assertOk();
        $this->assertCount(1, $personalResponse->viewData('supervisionSchedules'));
        $this->assertCount(2, $personalResponse->viewData('supervisionAssignments'));

        $stageTwo->update(['starts_on' => now()->addWeek(), 'ends_on' => now()->addWeeks(2)]);
        $stageOneResponse = $this->get(route('supervisor.schedule'))->assertOk();
        $this->assertCount(1, $stageOneResponse->viewData('supervisionAssignments'));
        $this->assertSame([$assignment->id], $stageOneResponse->viewData('supervisionSchedules')->first()->assignment_ids);
    }

    public function test_updating_study_program_automatically_sets_faculty(): void
    {
        $lecturer = Lecturer::first();
        $fsip = Faculty::where('code', 'FSIP')->first();
        $prodiSastra = StudyProgram::where('code', 'SS')->where('faculty_id', $fsip->id)->first();

        $response = $this->actingAs($this->admin)->patchJson(route('lpm.lecturers.update_prodi', $lecturer), [
            'study_program_id' => $prodiSastra->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'faculty_code' => 'FSIP',
            'study_program_name' => 'Sastra Inggris',
        ]);

        $lecturer->refresh();
        $this->assertEquals($prodiSastra->id, $lecturer->study_program_id);
        $this->assertEquals($fsip->id, $lecturer->faculty_id);
    }

    public function test_index_displays_only_real_nidn_and_no_raw_id_under_lecturer_name(): void
    {
        $lecturer = Lecturer::first();
        $response = $this->actingAs($this->admin)->get(route('lpm.lecturers.index'));

        $response->assertStatus(200);
        $response->assertSee('NIDN');
        $response->assertSee('Fakultas');
        $response->assertSee('Program Studi');
        $response->assertDontSee("ID: {$lecturer->id}");
    }

    public function test_directory_hides_invalid_import_names_and_synthetic_codes(): void
    {
        $invalid = Lecturer::create([
            'name' => '---',
            'external_code' => 'DSN-INVALID-TEST',
            'is_active' => false,
        ]);
        $lecturer = Lecturer::create([
            'name' => 'Dosen Verifikasi NIDN',
            'external_code' => 'DSN-VERIFY-TEST',
            'is_active' => true,
        ]);
        $this->attachLecturerToSelectedPeriod($lecturer);

        $response = $this->actingAs($this->admin)->get(route('lpm.lecturers.index', ['search' => 'Dosen Verifikasi NIDN']));

        $response->assertOk()
            ->assertSee($lecturer->name)
            ->assertSee('Belum tersedia')
            ->assertDontSee($lecturer->external_code)
            ->assertDontSee($invalid->name);
    }

    public function test_pagination_renders_page_numbers_and_never_raw_translation_keys(): void
    {
        // Ensure there are at least 25 lecturers so pagination is triggered
        $existing = Lecturer::count();
        if ($existing < 25) {
            for ($i = $existing + 1; $i <= 25; $i++) {
                $lecturer = Lecturer::create([
                    'name' => "Dosen Test {$i}",
                    'external_code' => "DSN-TEST-{$i}",
                    'is_active' => true,
                ]);
                $this->attachLecturerToSelectedPeriod($lecturer);
            }
        }

        $response = $this->actingAs($this->admin)->get(route('lpm.lecturers.index'));

        $response->assertStatus(200);
        $response->assertDontSee('pagination.next');
        $response->assertDontSee('pagination.previous');
        $response->assertSee('Halaman berikutnya');
        $response->assertSee('Halaman sebelumnya');
        $response->assertSee('Menampilkan');
    }

    public function test_dropdown_prodi_only_contains_programs_from_lecturer_faculty(): void
    {
        $ftik = Faculty::where('code', 'FTIK')->first();
        $ifProgram = StudyProgram::where('faculty_id', $ftik->id)->where('code', 'IF')->firstOrFail();
        $lecturerFtik = Lecturer::where('faculty_id', $ftik->id)->where('study_program_id', $ifProgram->id)->first();
        if (! $lecturerFtik) {
            $lecturerFtik = Lecturer::create([
                'name' => 'Dosen FTIK Spesifik',
                'external_code' => 'DSN-FTIK-SPEC',
                'faculty_id' => $ftik->id,
                'study_program_id' => $ifProgram->id,
                'is_active' => true,
            ]);
        }
        if (! $lecturerFtik->teachingSchedules()->whereHas('academicTerm.supervisionPeriods', fn ($query) => $query->where('status', 'ACTIVE'))->exists()) {
            $this->attachLecturerToSelectedPeriod($lecturerFtik);
        }

        $response = $this->actingAs($this->admin)->get(route('lpm.api.faculty.study_programs', $ftik));

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Informatika', 'code' => 'IF'])
            ->assertJsonMissing(['name' => 'Sastra Inggris']);
    }

    private function attachLecturerToPeriod(Lecturer $lecturer, int $academicTermId): void
    {
        TeachingSchedule::create([
            'academic_term_id' => $academicTermId,
            'lecturer_id' => $lecturer->id,
            'course_id' => Course::firstOrFail()->id,
            'faculty_id' => $lecturer->faculty_id,
            'study_program_id' => $lecturer->study_program_id,
            'class_name' => 'IF 26 A',
            'delivery_mode' => 'OFFLINE',
            'day_of_week' => 1,
            'starts_local' => '08:00:00',
            'ends_local' => '09:40:00',
            'is_active' => true,
        ]);
    }

    private function attachLecturerToSelectedPeriod(Lecturer $lecturer): void
    {
        $period = SupervisionPeriod::where('status', 'ACTIVE')->latest('starts_on')->first()
            ?? SupervisionPeriod::latest('starts_on')->first();

        if ($period && ! $lecturer->teachingSchedules()->where('academic_term_id', $period->academic_term_id)->exists()) {
            $this->attachLecturerToPeriod($lecturer, $period->academic_term_id);
        }

        if ($period) {
            PeriodTarget::firstOrCreate([
                'supervision_period_id' => $period->id,
                'lecturer_id' => $lecturer->id,
            ], [
                'scope_status' => 'INCLUDED',
                'selected_by' => $this->admin->id,
            ]);
        }
    }
}
