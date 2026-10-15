<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\FormTemplate;
use App\Models\Lecturer;
use App\Models\PeriodSupervisor;
use App\Models\PeriodTarget;
use App\Models\ScheduleImport;
use App\Models\ScheduleImportRow;
use App\Models\SchedulingRule;
use App\Models\SchedulingState;
use App\Models\ScoringPolicy;
use App\Models\StageInstrument;
use App\Models\Supervision;
use App\Models\SupervisionAnswer;
use App\Models\SupervisionPeriod;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\TeachingSchedule;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use RuntimeException;

trait CreatesSchedulingFixtures
{
    protected User $admin;

    protected User $supervisorUser;

    protected Lecturer $supervisor;

    protected Lecturer $target;

    protected SupervisionPeriod $period;

    protected SupervisionStage $stage;

    protected SupervisionStage $secondStage;

    protected TeachingOccurrence $occurrence;

    protected StageInstrument $instrument;

    public function createApplication(): Application
    {
        $application = parent::createApplication();
        $connection = $application['config']->get('database.default');
        if (! $application->environment('testing') || $connection !== 'mysql'
            || $application['config']->get('database.connections.mysql.database') !== 'sisd_teknokrat_test'
            || $application['db']->connection()->getDatabaseName() !== 'sisd_teknokrat_test') {
            throw new RuntimeException('Scheduling security tests require the isolated MySQL testing database.');
        }

        return $application;
    }

    protected function buildSchedulingFixture(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $this->travelTo(Carbon::parse('2030-10-07 10:00:00', 'Asia/Jakarta'));
        $this->admin = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->admin->assignRole('superadmin');
        $this->actingAs($this->admin);
        $term = AcademicTerm::firstOrCreate(['academic_year' => '2030/2031', 'semester' => 'GANJIL'], [
            'starts_on' => '2030-09-01', 'ends_on' => '2031-01-31', 'timezone' => 'Asia/Jakarta',
        ]);
        SupervisionPeriod::where('status', 'ACTIVE')->update(['status' => 'CLOSED']);
        $this->period = SupervisionPeriod::create([
            'academic_term_id' => $term->id, 'name' => 'Periode Pengujian Penjadwalan',
            'starts_on' => '2030-10-01', 'ends_on' => '2031-01-31', 'status' => 'ACTIVE', 'created_by' => $this->admin->id,
        ]);
        $this->stage = $this->period->stages()->create([
            'stage_no' => 1, 'name' => 'Tahap 1', 'starts_on' => '2030-10-01', 'ends_on' => '2030-10-31', 'status' => 'DRAFT',
        ]);
        $this->secondStage = $this->period->stages()->create([
            'stage_no' => 2, 'name' => 'Tahap 2', 'starts_on' => '2031-01-01', 'ends_on' => '2031-01-31', 'status' => 'DRAFT',
        ]);
        $rule = SchedulingRule::create([
            'supervision_period_id' => $this->period->id, 'version' => 1, 'schema_version' => '1.0',
            'config' => ['stage_supervisor_policy' => 'SAME', 'require_t2_after_t1' => true],
            'status' => 'APPROVED', 'created_by' => $this->admin->id,
        ]);
        $this->period->update(['active_scheduling_rule_id' => $rule->id]);
        $this->supervisor = Lecturer::create(['name' => 'Supervisor Pengujian', 'external_code' => 'SUP-'.Str::random(10), 'is_active' => true]);
        PeriodSupervisor::create(['supervision_period_id' => $this->period->id, 'lecturer_id' => $this->supervisor->id,
            'is_active' => true, 'selected_by' => $this->admin->id]);
        $this->supervisorUser = User::factory()->create(['lecturer_id' => $this->supervisor->id, 'is_active' => true, 'must_change_password' => false]);
        $this->supervisorUser->assignRole('supervisor');
        $this->target = $this->createSchedulingTarget('Dosen Pengujian');
        $this->occurrence = $this->target->teachingSchedules()->firstOrFail()->occurrences()
            ->where('occurs_on', '2030-10-07')->firstOrFail();
        $template = FormTemplate::create(['code' => 'F07-'.Str::random(10), 'version' => 1,
            'name' => 'Instrumen Pengujian', 'delivery_mode' => 'OFFLINE', 'status' => 'PUBLISHED']);
        $section = $template->sections()->create(['code' => 'SEC', 'title' => 'Pengamatan', 'sort_order' => 1]);
        $question = $section->questions()->create(['form_template_id' => $template->id, 'code' => 'SCORE',
            'question_text' => 'Pengamatan pengujian', 'question_type' => 'SCORE', 'is_required' => true, 'is_scored' => true, 'sort_order' => 1]);
        $question->options()->create(['value' => '3', 'numeric_score' => 3, 'label' => 'Baik', 'sort_order' => 1]);
        $policy = ScoringPolicy::create(['code' => 'F07-'.Str::random(10), 'version' => 1, 'strategy' => 'MEAN_0_4', 'config' => [], 'status' => 'APPROVED']);
        foreach ([$this->stage, $this->secondStage] as $stage) {
            $instrument = StageInstrument::create(['supervision_stage_id' => $stage->id, 'delivery_mode' => 'OFFLINE',
                'form_template_id' => $template->id, 'scoring_policy_id' => $policy->id, 'bound_by' => $this->admin->id]);
            if ($stage->id === $this->stage->id) {
                $this->instrument = $instrument;
            }
        }
        SchedulingState::updateOrCreate(['id' => 1], ['revision' => 10, 'updated_at' => now()]);
        config(['services.python.secret' => Str::random(48), 'services.python.url' => 'http://127.0.0.1:18100']);
    }

    protected function createSchedulingTarget(string $name): Lecturer
    {
        $target = Lecturer::create(['name' => $name, 'external_code' => 'TARGET-'.Str::random(10), 'is_active' => true]);
        PeriodTarget::create(['supervision_period_id' => $this->period->id, 'lecturer_id' => $target->id,
            'scope_status' => 'INCLUDED', 'selected_by' => $this->admin->id]);
        $course = Course::create(['code' => 'COURSE-'.Str::random(10), 'name' => 'Mata Kuliah Pengujian', 'is_active' => true]);
        $schedule = TeachingSchedule::create(['academic_term_id' => $this->period->academic_term_id, 'lecturer_id' => $target->id,
            'course_id' => $course->id, 'class_name' => 'Kelas Pengujian', 'room_name' => 'Ruang Pengujian',
            'delivery_mode' => 'OFFLINE', 'day_of_week' => 1, 'starts_local' => '08:00', 'ends_local' => '09:40', 'is_active' => true]);
        foreach (['2030-10-07', '2031-01-06'] as $date) {
            TeachingOccurrence::create(['teaching_schedule_id' => $schedule->id, 'original_date' => $date, 'occurs_on' => $date,
                'starts_at' => Carbon::parse($date.' 08:00', 'Asia/Jakarta')->utc(),
                'ends_at' => Carbon::parse($date.' 09:40', 'Asia/Jakarta')->utc(), 'status' => 'SCHEDULED']);
        }

        return $target;
    }

    protected function answerSupervision(Supervision $supervision): void
    {
        $question = $supervision->formTemplate->questions()->firstOrFail();
        SupervisionAnswer::create(['supervision_id' => $supervision->id, 'question_id' => $question->id,
            'question_option_id' => $question->options()->firstOrFail()->id, 'numeric_score' => 3]);
    }

    protected function createSchedulingImport(): ScheduleImport
    {
        $import = ScheduleImport::create(['academic_term_id' => $this->period->academic_term_id, 'uploaded_by' => $this->admin->id,
            'original_filename' => 'synthetic.pdf', 'storage_disk' => 'local', 'stored_path' => 'imports/synthetic.pdf',
            'sha256' => hash('sha256', 'synthetic'), 'size_bytes' => 100, 'mime_type' => 'application/pdf', 'status' => 'PENDING_REVIEW']);
        ScheduleImportRow::create(['schedule_import_id' => $import->id, 'row_no' => 1, 'source_page' => 1,
            'matched_lecturer_id' => $this->target->id, 'validation_status' => 'VALID', 'is_approved' => true,
            'normalized_payload' => ['lecturer_name' => $this->target->name, 'course_name' => 'Mata Kuliah Impor', 'class_name' => 'Kelas Impor',
                'day_of_week' => 2, 'start_time' => '10:00', 'end_time' => '11:40', 'room_name' => 'Ruang Impor', 'delivery_mode' => 'OFFLINE']]);

        return $import;
    }

    protected function legacyTemplateCopy(FormTemplate $source): FormTemplate
    {
        $source->load('sections.questions.options');
        $copy = $source->replicate(['published_by', 'published_at']);
        $copy->version = FormTemplate::where('code', $source->code)->max('version') + 1;
        $copy->status = 'PUBLISHED';
        $copy->save();

        foreach ($source->sections as $section) {
            $newSection = $section->replicate();
            $newSection->form_template_id = $copy->id;
            $newSection->save();

            foreach ($section->questions as $question) {
                $newQuestion = $question->replicate();
                $newQuestion->form_template_id = $copy->id;
                $newQuestion->form_section_id = $newSection->id;
                $newQuestion->save();

                foreach ($question->options as $option) {
                    $newOption = $option->replicate();
                    $newOption->question_id = $newQuestion->id;
                    $newOption->save();
                }
            }
        }

        return $copy->unsetRelations();
    }
}
