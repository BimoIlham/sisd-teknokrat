<?php

namespace Tests\Feature;

use App\Models\Lecturer;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResetScheduleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_can_preserve_academic_master_data(): void
    {
        $this->seed([DatabaseSeeder::class, UserSeeder::class, DemoAcademicSeeder::class]);
        $counts = [];
        foreach (['courses', 'academic_terms', 'faculties', 'study_programs', 'form_templates'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $this->artisan('app:reset-schedule-data', ['--keep-academic-master' => true])->assertSuccessful();
        foreach ($counts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertDatabaseCount('lecturers', 0);
        $this->assertDatabaseCount('supervision_periods', 0);
        $this->assertDatabaseCount('teaching_schedules', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_reset_removes_lecturers_periods_and_related_data_but_preserves_lpm_and_instruments(): void
    {
        Storage::fake('local');
        $this->seed([DatabaseSeeder::class, UserSeeder::class, DemoAcademicSeeder::class]);
        $admin = User::where('email', 'lpm@teknokrat.ac.id')->firstOrFail();
        $admin->update(['lecturer_id' => Lecturer::whereDoesntHave('user')->firstOrFail()->id]);
        $admin->assignRole('supervisor');
        $adminPassword = $admin->password;
        $supervisor = User::where('email', 'supervisor1@teknokrat.ac.id')->firstOrFail();
        $stage = SupervisionStage::firstOrFail();
        $occurrence = TeachingOccurrence::with('schedule')->firstOrFail();
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id,
            'target_lecturer_id' => $occurrence->schedule->lecturer_id,
            'supervisor_lecturer_id' => $supervisor->lecturer_id,
            'teaching_occurrence_id' => $occurrence->id,
            'status' => 'FINAL',
        ]);
        $supervision = Supervision::create(['supervision_assignment_id' => $assignment->id]);
        $answer = $supervision->answers()->create([
            'question_id' => $supervision->formTemplate->questions()->firstOrFail()->id,
            'notes' => 'Catatan pengujian lama',
        ]);
        $answer->evidence()->create([
            'uploaded_by' => $supervisor->id,
            'evidence_type' => 'FILE',
            'storage_disk' => 'local',
            'file_path' => 'evidence/old.pdf',
        ]);
        $importId = DB::table('schedule_imports')->insertGetId([
            'academic_term_id' => $occurrence->schedule->academic_term_id,
            'uploaded_by' => $admin->id,
            'original_filename' => 'old.pdf',
            'storage_disk' => 'local',
            'stored_path' => 'imports/old.pdf',
            'sha256' => hash('sha256', 'old-import'),
            'size_bytes' => 1,
            'mime_type' => 'application/pdf',
        ]);
        $rowId = DB::table('schedule_import_rows')->insertGetId([
            'schedule_import_id' => $importId,
            'row_no' => 1,
            'raw_payload' => '{}',
            'normalized_payload' => '{}',
            'matched_lecturer_id' => $occurrence->schedule->lecturer_id,
            'matched_course_id' => $occurrence->schedule->course_id,
            'committed_schedule_id' => $occurrence->teaching_schedule_id,
        ]);
        $occurrence->schedule->update(['source_import_id' => $importId, 'source_row_id' => $rowId]);
        DB::table('generated_documents')->insert([
            'document_type' => 'SUPERVISION_REPORT',
            'supervision_period_id' => $stage->supervision_period_id,
            'supervision_stage_id' => $stage->id,
            'source_revision' => 1,
            'source_hash' => hash('sha256', 'old-document'),
            'storage_disk' => 'local',
            'file_path' => 'reports/old.pdf',
            'generated_by' => $supervisor->id,
        ]);
        DB::table('sessions')->insert([
            'id' => 'old-supervisor-session',
            'user_id' => $supervisor->id,
            'payload' => '',
            'last_activity' => time(),
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $supervisor->email,
            'token' => 'old-token',
        ]);
        foreach (['imports/old.pdf', 'evidence/old.pdf', 'reports/old.pdf', 'unrelated/keep.pdf'] as $path) {
            Storage::disk('local')->put($path, 'test');
        }
        $retainedCounts = [];
        foreach (['faculties', 'study_programs', 'form_templates', 'form_sections', 'questions', 'question_options', 'scoring_policies', 'roles', 'permissions', 'role_has_permissions'] as $table) {
            $retainedCounts[$table] = DB::table($table)->count();
        }

        $initialRevision = (int) DB::table('scheduling_state')->where('id', 1)->value('revision');
        $this->artisan('app:reset-schedule-data')->assertExitCode(0);

        foreach (['lecturers', 'supervision_periods', 'supervision_stages', 'stage_instruments', 'period_targets', 'period_supervisors', 'scheduling_rules', 'supervision_assignments', 'supervisions', 'supervision_answers', 'supervision_evidence', 'teaching_schedules', 'teaching_occurrences', 'schedule_imports', 'schedule_import_rows', 'generated_documents', 'courses', 'academic_terms', 'academic_non_teaching_dates'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        foreach ($retainedCounts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertTrue($admin->fresh()->hasRole('lpm_admin'));
        $this->assertNull($admin->fresh()->lecturer_id);
        $this->assertSame($adminPassword, $admin->fresh()->password);
        $this->assertDatabaseMissing('model_has_roles', ['model_type' => User::class, 'model_id' => $supervisor->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-supervisor-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $supervisor->email]);
        $this->assertDatabaseHas('scheduling_state', ['id' => 1, 'revision' => $initialRevision + 1]);
        Storage::disk('local')->assertMissing(['imports/old.pdf', 'evidence/old.pdf', 'reports/old.pdf']);
        Storage::disk('local')->assertExists('unrelated/keep.pdf');

        foreach (['lpm.dashboard', 'lpm.periods.index', 'lpm.lecturers.index', 'lpm.supervisors.index', 'lpm.import.index'] as $routeName) {
            $this->actingAs($admin->fresh())->get(route($routeName))->assertOk();
        }
    }

    public function test_reset_on_an_empty_database_keeps_the_application_ready_for_a_new_period(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $initialRevision = (int) DB::table('scheduling_state')->where('id', 1)->value('revision');
        $this->artisan('app:reset-schedule-data')->assertExitCode(0);
        $this->artisan('app:reset-schedule-data')->assertExitCode(0);

        $this->assertDatabaseHas('scheduling_state', ['id' => 1, 'revision' => $initialRevision + 2]);
        $this->actingAs($admin)->post(route('lpm.periods.store'), [
            'academic_year' => '2026/2027',
            'semester' => 'GANJIL',
            'stage_1_starts_on' => '2026-09-01',
            'stage_1_ends_on' => '2026-10-31',
            'stage_2_starts_on' => '2026-11-01',
            'stage_2_ends_on' => '2027-01-31',
            'status' => 'ACTIVE',
        ])->assertRedirect(route('lpm.periods.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('supervision_periods', 1);
        $this->assertDatabaseCount('supervision_stages', 2);
        $this->assertDatabaseCount('stage_instruments', 4);
    }
}
