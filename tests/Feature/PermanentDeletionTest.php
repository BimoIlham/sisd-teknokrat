<?php

namespace Tests\Feature;

use App\Models\GeneratedDocument;
use App\Models\Lecturer;
use App\Models\PeriodSupervisor;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionEvidence;
use App\Models\SupervisionPeriod;
use App\Models\User;
use App\Services\PermanentDeletionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PermanentDeletionTest extends TestCase
{
    use CreatesSchedulingFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildSchedulingFixture();
        $this->admin->update(['is_root_superadmin' => true]);
    }

    public function test_schedule_deletion_removes_final_supervision_evidence_and_documents_but_keeps_other_lecturers(): void
    {
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'assignment_type' => 'MANUAL', 'status' => 'FINAL',
        ]);
        $supervision = Supervision::create(['supervision_assignment_id' => $assignment->id, 'status' => 'SUBMITTED']);
        $this->answerSupervision($supervision);
        $answer = $supervision->answers()->firstOrFail();
        Storage::disk('local')->put('evidence/test.pdf', 'test');
        $evidence = SupervisionEvidence::create(['supervision_answer_id' => $answer->id, 'uploaded_by' => $this->supervisorUser->id, 'evidence_type' => 'FILE', 'storage_disk' => 'local', 'file_path' => 'evidence/test.pdf']);
        Storage::disk('local')->put('reports/test.pdf', 'test');
        $document = GeneratedDocument::create([
            'document_type' => 'SUPERVISION_REPORT', 'supervision_period_id' => $this->period->id,
            'supervision_stage_id' => $this->stage->id, 'source_revision' => 1,
            'source_hash' => hash('sha256', 'report'), 'status' => 'READY', 'storage_disk' => 'local',
            'file_path' => 'reports/test.pdf', 'generated_by' => $this->admin->id,
        ]);
        $this->stage->update(['status' => 'FINALIZED']);
        $this->secondStage->update(['status' => 'FINALIZED']);

        $scheduleId = $this->occurrence->teaching_schedule_id;
        $this->deleteConfirmed('schedule', $scheduleId, 'Kelas Pengujian / #'.$scheduleId);
        $this->assertDatabaseMissing('teaching_schedules', ['id' => $scheduleId]);
        $this->assertDatabaseMissing('supervisions', ['id' => $supervision->id]);
        $this->assertDatabaseMissing('supervision_evidence', ['id' => $evidence->id]);
        $this->assertDatabaseMissing('generated_documents', ['id' => $document->id]);
        $this->assertDatabaseHas('lecturers', ['id' => $this->target->id]);
        $this->assertDatabaseHas('lecturers', ['id' => $this->supervisor->id]);
        $this->assertDatabaseHas('supervision_stages', ['id' => $this->stage->id, 'status' => 'DRAFT']);
        $this->assertDatabaseHas('supervision_stages', ['id' => $this->secondStage->id, 'status' => 'DRAFT']);
        Storage::disk('local')->assertMissing('evidence/test.pdf');
        Storage::disk('local')->assertMissing('reports/test.pdf');
        $this->assertDatabaseCount('pending_file_deletions', 0);
    }

    public function test_supervisor_deletion_keeps_lecturer_and_teaching_schedule(): void
    {
        $schedule = $this->target->teachingSchedules()->firstOrFail()->replicate(['fingerprint']);
        $schedule->lecturer_id = $this->supervisor->id;
        $schedule->class_name = 'Kelas Supervisor';
        $schedule->save();
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'assignment_type' => 'MANUAL', 'status' => 'FINAL',
        ]);
        $this->deleteConfirmed('supervisor', $this->supervisor->id, $this->supervisor->name);
        $this->assertDatabaseMissing('period_supervisors', ['lecturer_id' => $this->supervisor->id]);
        $this->assertDatabaseMissing('supervision_assignments', ['id' => $assignment->id]);
        $this->assertDatabaseMissing('users', ['id' => $this->supervisorUser->id]);
        $this->assertDatabaseHas('lecturers', ['id' => $this->supervisor->id]);
        $this->assertDatabaseHas('teaching_schedules', ['id' => $schedule->id]);
    }

    public function test_lecturer_deletion_keeps_shared_import_and_other_lecturer(): void
    {
        $import = $this->createSchedulingImport();
        $otherId = $this->supervisor->id;
        $this->deleteConfirmed('lecturer', $this->target->id, $this->target->name);
        $this->assertDatabaseHas('lecturers', ['id' => $this->target->id]);
        $this->assertDatabaseHas('period_targets', ['supervision_period_id' => $this->period->id, 'lecturer_id' => $this->target->id, 'scope_status' => 'EXCLUDED']);
        $this->assertDatabaseMissing('teaching_schedules', ['supervision_period_id' => $this->period->id, 'lecturer_id' => $this->target->id]);
        $this->assertDatabaseHas('schedule_imports', ['id' => $import->id]);
        $this->assertDatabaseHas('lecturers', ['id' => $otherId]);
    }

    public function test_lecturer_deletion_affects_only_selected_period_and_rejects_stale_preview(): void
    {
        $otherPeriod = SupervisionPeriod::create([
            'academic_term_id' => $this->period->academic_term_id, 'name' => 'Periode Berikutnya',
            'starts_on' => '2030-10-01', 'ends_on' => '2031-01-31', 'status' => 'CLOSED', 'created_by' => $this->admin->id,
        ]);
        $otherSchedule = $this->target->teachingSchedules()->firstOrFail()->replicate(['fingerprint']);
        $otherSchedule->supervision_period_id = $otherPeriod->id;
        $otherSchedule->save();
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'assignment_type' => 'MANUAL', 'status' => 'FINAL',
        ]);
        $supervision = Supervision::create(['supervision_assignment_id' => $assignment->id, 'status' => 'SUBMITTED']);
        Storage::disk('local')->put('reports/other-period.pdf', 'untouched');
        $otherDocument = GeneratedDocument::create([
            'document_type' => 'SUPERVISION_REPORT', 'supervision_period_id' => $otherPeriod->id,
            'source_revision' => 1, 'source_hash' => hash('sha256', 'other-period'), 'status' => 'READY',
            'storage_disk' => 'local', 'file_path' => 'reports/other-period.pdf', 'generated_by' => $this->admin->id,
        ]);
        $impact = app(PermanentDeletionService::class)->previewPeriodLecturer($this->target->id, $this->period->id);
        $this->target->update(['row_version' => $this->target->fresh()->row_version + 1]);
        $this->assertSame(2, $this->target->fresh()->row_version);
        $this->assertNotSame($impact['token'], app(PermanentDeletionService::class)->previewPeriodLecturer($this->target->id, $this->period->id)['token']);
        $this->actingAs($this->admin)->delete(route('superadmin.deletions.destroy', ['type' => 'lecturer', 'id' => $this->target->id]), [
            'period_id' => $this->period->id, 'confirmation_name' => $impact['name'],
            'preview_token' => $impact['token'], 'password' => 'password',
        ])->assertRedirect()->assertSessionHasErrors('confirmation_name');
        $this->assertDatabaseHas('supervisions', ['id' => $supervision->id]);

        $this->deleteConfirmed('lecturer', $this->target->id, $this->target->name);
        $this->assertDatabaseMissing('supervisions', ['id' => $supervision->id]);
        $this->assertDatabaseHas('lecturers', ['id' => $this->target->id]);
        $this->assertDatabaseHas('teaching_schedules', ['id' => $otherSchedule->id, 'supervision_period_id' => $otherPeriod->id]);
        $this->assertDatabaseHas('generated_documents', ['id' => $otherDocument->id, 'status' => 'READY']);
        Storage::disk('local')->assertExists('reports/other-period.pdf');
        $this->assertDatabaseMissing('period_targets', ['supervision_period_id' => $otherPeriod->id, 'lecturer_id' => $this->target->id, 'scope_status' => 'EXCLUDED']);
    }

    public function test_import_deletion_clears_pdf_and_parser_reference(): void
    {
        $import = $this->createSchedulingImport();
        Storage::disk('local')->put($import->stored_path, 'pdf');
        DB::table('schedule_document_references')->insert([
            'sha256' => $import->sha256, 'original_filename' => $import->original_filename,
            'parser_version' => '1.2.0', 'page_count' => 1, 'stored_path' => 'imports/reference-test.pdf',
            'parsed_rows' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Storage::disk('local')->put('imports/reference-test.pdf', 'pdf');
        $this->deleteConfirmed('import', $import->id, $import->original_filename);
        $this->assertDatabaseMissing('schedule_imports', ['id' => $import->id]);
        $this->assertDatabaseMissing('schedule_document_references', ['sha256' => $import->sha256]);
        Storage::disk('local')->assertMissing($import->stored_path);
        Storage::disk('local')->assertMissing('imports/reference-test.pdf');
    }

    public function test_period_deletion_preserves_semester_schedule(): void
    {
        $scheduleId = $this->occurrence->teaching_schedule_id;
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'assignment_type' => 'MANUAL', 'status' => 'FINAL',
        ]);
        $supervision = Supervision::create(['supervision_assignment_id' => $assignment->id, 'status' => 'SUBMITTED']);
        $this->deleteConfirmed('period', $this->period->id, $this->period->name);
        $this->assertDatabaseMissing('supervision_periods', ['id' => $this->period->id]);
        $this->assertDatabaseMissing('supervisions', ['id' => $supervision->id]);
        $this->assertDatabaseHas('teaching_schedules', ['id' => $scheduleId]);
    }

    public function test_changed_data_or_wrong_password_cannot_be_deleted(): void
    {
        $url = route('superadmin.deletions.preview', ['type' => 'lecturer', 'id' => $this->target->id]);
        $this->actingAs($this->admin)->get($url)->assertOk();
        $token = app(PermanentDeletionService::class)->previewPeriodLecturer($this->target->id, $this->period->id)['token'];
        $this->target->update(['row_version' => $this->target->fresh()->row_version + 1]);
        $this->assertSame(2, $this->target->fresh()->row_version);
        $this->assertNotSame($token, app(PermanentDeletionService::class)->previewPeriodLecturer($this->target->id, $this->period->id)['token']);
        $deleteUrl = route('superadmin.deletions.destroy', ['type' => 'lecturer', 'id' => $this->target->id]);
        $this->actingAs($this->admin)->delete($deleteUrl, ['period_id' => $this->period->id, 'confirmation_name' => $this->target->name, 'preview_token' => $token, 'password' => 'password'])->assertSessionHasErrors('confirmation_name');
        $this->actingAs($this->admin)->delete($deleteUrl, ['period_id' => $this->period->id, 'confirmation_name' => $this->target->name, 'preview_token' => $token, 'password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertDatabaseHas('lecturers', ['id' => $this->target->id]);
    }

    public function test_pending_file_cleanup_can_be_retried_without_deleting_a_still_referenced_pdf(): void
    {
        Storage::disk('local')->put('reports/pending.pdf', 'report');
        DB::table('pending_file_deletions')->insert([
            'storage_disk' => 'local', 'path_hash' => hash('sha256', 'reports/pending.pdf'),
            'file_path' => 'reports/pending.pdf', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->artisan('app:retry-pending-file-deletions')->assertSuccessful();
        Storage::disk('local')->assertMissing('reports/pending.pdf');
        $this->assertDatabaseCount('pending_file_deletions', 0);

        $import = $this->createSchedulingImport();
        Storage::disk('local')->put($import->stored_path, 'shared');
        DB::table('pending_file_deletions')->insert([
            'storage_disk' => 'local', 'path_hash' => hash('sha256', $import->stored_path),
            'file_path' => $import->stored_path, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->artisan('app:retry-pending-file-deletions')->assertSuccessful();
        Storage::disk('local')->assertExists($import->stored_path);
        $this->assertDatabaseCount('pending_file_deletions', 0);

        DB::table('pending_file_deletions')->insert([
            'storage_disk' => 'unavailable-test-disk', 'path_hash' => hash('sha256', 'reports/unavailable.pdf'),
            'file_path' => 'reports/unavailable.pdf', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->artisan('app:retry-pending-file-deletions')->assertFailed();
        $this->assertDatabaseHas('pending_file_deletions', ['storage_disk' => 'unavailable-test-disk', 'attempts' => 1]);
    }

    public function test_bulk_lecturer_deletion_includes_other_pages_and_preserves_imports_and_periods(): void
    {
        foreach (range(1, 20) as $number) {
            Lecturer::create(['name' => 'Dosen Tambahan '.$number, 'is_active' => true]);
        }
        $import = $this->createSchedulingImport();
        Storage::disk('local')->put($import->stored_path, 'shared pdf');
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'assignment_type' => 'MANUAL', 'status' => 'FINAL',
        ]);
        $supervision = Supervision::create(['supervision_assignment_id' => $assignment->id, 'status' => 'SUBMITTED']);
        $this->answerSupervision($supervision);
        $answer = $supervision->answers()->firstOrFail();
        Storage::disk('local')->put('evidence/bulk.pdf', 'evidence');
        SupervisionEvidence::create([
            'supervision_answer_id' => $answer->id, 'uploaded_by' => $this->supervisorUser->id,
            'evidence_type' => 'FILE', 'storage_disk' => 'local', 'file_path' => 'evidence/bulk.pdf',
        ]);
        Storage::disk('local')->put('reports/bulk.pdf', 'report');
        $document = GeneratedDocument::create([
            'document_type' => 'SUPERVISION_REPORT', 'supervision_period_id' => $this->period->id,
            'supervision_stage_id' => $this->stage->id, 'source_revision' => 1,
            'source_hash' => hash('sha256', 'bulk report'), 'status' => 'READY',
            'storage_disk' => 'local', 'file_path' => 'reports/bulk.pdf', 'generated_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->get(route('superadmin.deletions.index', ['type' => 'lecturer', 'page' => 2]))->assertOk()->assertSee('Hapus Semua Dosen');
        $this->actingAs($this->admin)->get(route('superadmin.deletions.bulk.preview', ['type' => 'lecturer']))->assertOk()->assertSee('HAPUS SEMUA DOSEN');
        $impact = app(PermanentDeletionService::class)->previewBulk('lecturer');
        $this->assertGreaterThanOrEqual(22, $impact['counts']['dosen']);
        $this->deleteBulkConfirmed('lecturer', $impact);

        $this->assertDatabaseCount('lecturers', 0);
        $this->actingAs($this->admin)->get(route('superadmin.deletions.index', ['type' => 'lecturer']))->assertOk()->assertDontSee('Hapus Semua Dosen');
        $this->assertDatabaseMissing('supervisions', ['id' => $supervision->id]);
        $this->assertDatabaseMissing('supervision_assignments', ['id' => $assignment->id]);
        $this->assertDatabaseMissing('generated_documents', ['id' => $document->id]);
        $this->assertDatabaseHas('schedule_imports', ['id' => $import->id]);
        $this->assertDatabaseHas('supervision_periods', ['id' => $this->period->id]);
        $this->assertDatabaseHas('schedule_import_rows', ['schedule_import_id' => $import->id, 'validation_status' => 'SKIPPED']);
        Storage::disk('local')->assertExists($import->stored_path);
        Storage::disk('local')->assertMissing('evidence/bulk.pdf');
        Storage::disk('local')->assertMissing('reports/bulk.pdf');
        $this->assertDatabaseHas('system_audits', ['event' => 'PERMANENT_DELETE_ALL', 'entity_type' => 'lecturer']);
    }

    public function test_bulk_supervisor_deletion_preserves_lecturers_and_teaching_schedules(): void
    {
        $secondSupervisor = Lecturer::create(['name' => 'Supervisor Kedua', 'is_active' => true]);
        PeriodSupervisor::create([
            'supervision_period_id' => $this->period->id, 'lecturer_id' => $secondSupervisor->id,
            'is_active' => true, 'selected_by' => $this->admin->id,
        ]);
        $scheduleId = $this->target->teachingSchedules()->firstOrFail()->id;
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'assignment_type' => 'MANUAL', 'status' => 'FINAL',
        ]);
        $supervision = Supervision::create(['supervision_assignment_id' => $assignment->id, 'status' => 'SUBMITTED']);
        $this->answerSupervision($supervision);
        $import = $this->createSchedulingImport();

        $impact = app(PermanentDeletionService::class)->previewBulk('supervisor');
        $this->assertGreaterThanOrEqual(2, $impact['counts']['supervisor']);
        $this->deleteBulkConfirmed('supervisor', $impact);

        $this->assertDatabaseMissing('period_supervisors', ['lecturer_id' => $this->supervisor->id]);
        $this->assertDatabaseMissing('period_supervisors', ['lecturer_id' => $secondSupervisor->id]);
        $this->assertDatabaseMissing('supervisions', ['id' => $supervision->id]);
        $this->assertDatabaseHas('lecturers', ['id' => $this->supervisor->id]);
        $this->assertDatabaseHas('lecturers', ['id' => $secondSupervisor->id]);
        $this->assertDatabaseHas('lecturers', ['id' => $this->target->id]);
        $this->assertDatabaseMissing('users', ['id' => $this->supervisorUser->id]);
        $this->assertDatabaseHas('teaching_schedules', ['id' => $scheduleId]);
        $this->assertDatabaseHas('schedule_imports', ['id' => $import->id]);
        $this->assertDatabaseHas('supervision_periods', ['id' => $this->period->id]);
    }

    public function test_bulk_deletion_rejects_wrong_password_stale_preview_and_protected_account(): void
    {
        $deletions = app(PermanentDeletionService::class);
        $impact = $deletions->previewBulk('lecturer');
        $url = route('superadmin.deletions.bulk.destroy', ['type' => 'lecturer']);
        $payload = ['confirmation_phrase' => $impact['phrase'], 'preview_token' => $impact['token'], 'password' => 'wrong'];
        $this->actingAs($this->admin)->delete($url, $payload)->assertSessionHasErrors('password');
        $this->actingAs($this->admin)->delete($url, [...$payload, 'password' => 'password', 'confirmation_phrase' => 'HAPUS DOSEN'])->assertSessionHasErrors('confirmation_phrase');
        $this->assertDatabaseHas('lecturers', ['id' => $this->target->id]);

        $this->target->update(['row_version' => $this->target->fresh()->row_version + 1]);
        $this->assertSame(2, $this->target->fresh()->row_version);
        $this->assertNotSame($impact['token'], $deletions->previewBulk('lecturer')['token']);
        $this->actingAs($this->admin)->delete($url, [...$payload, 'password' => 'password'])->assertSessionHasErrors('confirmation_phrase');
        $this->assertDatabaseHas('lecturers', ['id' => $this->target->id]);

        $protected = User::factory()->create(['lecturer_id' => $this->target->id, 'must_change_password' => false]);
        $protected->assignRole('lpm_admin');
        $impact = $deletions->previewBulk('lecturer');
        $this->assertContains($this->target->name, $impact['protected_names']);
        $this->actingAs($this->admin)->delete($url, [
            'confirmation_phrase' => $impact['phrase'], 'preview_token' => $impact['token'], 'password' => 'password',
        ])->assertSessionHasErrors('confirmation_phrase');
        $this->assertDatabaseHas('lecturers', ['id' => $this->target->id]);
        $this->assertDatabaseHas('lecturers', ['id' => $this->supervisor->id]);
    }

    /** @param array{phrase: string, token: string} $impact */
    private function deleteBulkConfirmed(string $type, array $impact): void
    {
        $this->actingAs($this->admin)->delete(route('superadmin.deletions.bulk.destroy', ['type' => $type]), [
            'confirmation_phrase' => $impact['phrase'], 'preview_token' => $impact['token'], 'password' => 'password',
        ])->assertRedirect(route('superadmin.deletions.index', ['type' => $type]))->assertSessionHas('success');
    }

    private function deleteConfirmed(string $type, int $id, string $name): void
    {
        $preview = $type === 'lecturer'
            ? app(PermanentDeletionService::class)->previewPeriodLecturer($id, $this->period->id)
            : app(PermanentDeletionService::class)->preview($type, $id);
        $this->actingAs($this->admin)->delete(route('superadmin.deletions.destroy', ['type' => $type, 'id' => $id]), [
            'confirmation_name' => $name, 'preview_token' => $preview['token'], 'password' => 'password',
            'period_id' => $type === 'lecturer' ? $this->period->id : null,
        ])->assertRedirect(route('superadmin.deletions.index', ['type' => $type]))->assertSessionHas('success');

        $this->assertSame($name, $preview['name']);
    }
}
