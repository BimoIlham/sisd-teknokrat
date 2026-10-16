<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\Lecturer;
use App\Models\PeriodSupervisor;
use App\Models\PeriodTarget;
use App\Models\ScheduleImport;
use App\Models\ScheduleImportRow;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Models\TeachingOccurrence;
use App\Models\TeachingSchedule;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LecturerDeletionTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Lecturer $lecturer;

    private SupervisionPeriod $period;

    private TeachingSchedule $schedule;

    private TeachingOccurrence $occurrence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sisd_teknokrat_test', DB::connection()->getDatabaseName());
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->admin->assignRole('superadmin');
        $this->lecturer = Lecturer::create(['name' => 'Dosen Uji Hapus Manual', 'external_code' => '0211234567', 'is_active' => true, 'row_version' => 1]);
        $term = AcademicTerm::create(['academic_year' => '2040/2041', 'semester' => 'GANJIL', 'starts_on' => '2040-09-01', 'ends_on' => '2041-01-31']);
        $this->period = SupervisionPeriod::create(['academic_term_id' => $term->id, 'name' => 'Periode Uji Hapus', 'starts_on' => $term->starts_on, 'ends_on' => $term->ends_on, 'status' => 'ACTIVE', 'created_by' => $this->admin->id]);
        PeriodTarget::create(['supervision_period_id' => $this->period->id, 'lecturer_id' => $this->lecturer->id, 'scope_status' => 'INCLUDED', 'selected_by' => $this->admin->id]);
        $course = Course::create(['name' => 'Mata Kuliah Uji Hapus']);
        $this->schedule = TeachingSchedule::create(['academic_term_id' => $term->id, 'lecturer_id' => $this->lecturer->id, 'course_id' => $course->id, 'class_name' => 'IF 40 A', 'day_of_week' => 1, 'starts_local' => '09:00', 'ends_local' => '10:40', 'delivery_mode' => 'OFFLINE', 'is_active' => true]);
        $this->occurrence = TeachingOccurrence::create(['teaching_schedule_id' => $this->schedule->id, 'original_date' => '2040-10-01', 'occurs_on' => '2040-10-01', 'starts_at' => '2040-10-01 02:00:00', 'ends_at' => '2040-10-01 03:40:00', 'status' => 'SCHEDULED']);
    }

    public function test_admin_can_delete_a_lecturer_and_unused_schedules_while_preserving_references_and_other_lecturers(): void
    {
        $other = Lecturer::create(['name' => 'Dosen Lain Tetap Disimpan', 'is_active' => true]);
        $import = ScheduleImport::create(['academic_term_id' => $this->period->academic_term_id, 'uploaded_by' => $this->admin->id, 'original_filename' => 'jadwal.pdf', 'storage_disk' => 'local', 'stored_path' => 'imports/jadwal.pdf', 'sha256' => hash('sha256', 'deletion-test'), 'size_bytes' => 100, 'mime_type' => 'application/pdf', 'status' => 'COMMITTED']);
        $row = ScheduleImportRow::create(['schedule_import_id' => $import->id, 'row_no' => 1, 'normalized_payload' => ['lecturer_name' => $this->lecturer->name], 'matched_lecturer_id' => $this->lecturer->id, 'committed_schedule_id' => $this->schedule->id, 'validation_status' => 'VALID', 'is_approved' => true]);
        $this->schedule->update(['source_import_id' => $import->id, 'source_row_id' => $row->id]);
        $revision = (int) DB::table('scheduling_state')->where('id', 1)->value('revision');
        $this->actingAs($this->admin)->get(route('lpm.lecturers.index', ['period_id' => $this->period->id]))
            ->assertOk()->assertSee(route('lpm.lecturers.destroy', $this->lecturer))->assertSee('Hapus');

        $this->actingAs($this->admin)->delete(route('lpm.lecturers.destroy', $this->lecturer), $this->deletePayload())
            ->assertRedirect(route('lpm.lecturers.index', ['period_id' => $this->period->id]))->assertSessionHas('success');
        $this->assertDatabaseMissing('lecturers', ['id' => $this->lecturer->id]);
        $this->assertDatabaseMissing('teaching_schedules', ['id' => $this->schedule->id]);
        $this->assertDatabaseMissing('teaching_occurrences', ['id' => $this->occurrence->id]);
        $this->assertDatabaseMissing('period_targets', ['lecturer_id' => $this->lecturer->id]);
        $this->assertDatabaseHas('lecturers', ['id' => $other->id]);
        $this->assertDatabaseHas('supervision_periods', ['id' => $this->period->id]);
        $this->assertDatabaseHas('courses', ['id' => $this->schedule->course_id]);
        $this->assertDatabaseHas('lecturer_identity_references', ['identity_key' => 'code:0211234567']);
        $this->assertDatabaseHas('system_audits', ['event' => 'LECTURER_DELETED', 'entity_id' => $this->lecturer->id, 'user_id' => $this->admin->id]);
        $this->assertSame('SKIPPED', $row->fresh()->validation_status);
        $this->assertNull($row->fresh()->matched_lecturer_id);
        $this->assertFalse($row->fresh()->is_approved);
        $this->assertGreaterThan($revision, (int) DB::table('scheduling_state')->where('id', 1)->value('revision'));
    }

    #[TestWith(['account'])]
    #[TestWith(['membership'])]
    #[TestWith(['assignment'])]
    public function test_lecturers_with_protected_relationships_cannot_be_deleted(string $case): void
    {
        if ($case === 'account') {
            User::factory()->create(['lecturer_id' => $this->lecturer->id]);
        } elseif ($case === 'membership') {
            PeriodSupervisor::create(['supervision_period_id' => $this->period->id, 'lecturer_id' => $this->lecturer->id, 'is_active' => true, 'selected_by' => $this->admin->id]);
        } else {
            $this->actingAs($this->admin);
            $stage = $this->period->stages()->create(['stage_no' => 1, 'name' => 'Tahap 1', 'status' => 'DRAFT', 'starts_on' => '2040-10-01', 'ends_on' => '2040-10-31']);
            $supervisor = Lecturer::create(['name' => 'Supervisor Uji Hapus', 'is_active' => true]);
            SupervisionAssignment::create(['supervision_stage_id' => $stage->id, 'target_lecturer_id' => $this->lecturer->id, 'supervisor_lecturer_id' => $supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id, 'assignment_type' => 'MANUAL', 'status' => 'FINAL']);
        }
        $this->actingAs($this->admin)->delete(route('lpm.lecturers.destroy', $this->lecturer), $this->deletePayload())->assertSessionHas('error');
        $this->assertDatabaseHas('lecturers', ['id' => $this->lecturer->id]);
        $this->assertDatabaseHas('teaching_schedules', ['id' => $this->schedule->id]);
        $this->assertDatabaseHas('teaching_occurrences', ['id' => $this->occurrence->id]);
        $this->assertDatabaseHas('period_targets', ['lecturer_id' => $this->lecturer->id]);
        $this->assertDatabaseMissing('system_audits', ['event' => 'LECTURER_DELETED', 'entity_id' => $this->lecturer->id]);
    }

    public function test_stale_confirmation_cannot_delete_an_updated_lecturer(): void
    {
        $payload = $this->deletePayload();
        $this->lecturer->update(['row_version' => $this->lecturer->row_version + 1]);
        $this->actingAs($this->admin)->delete(route('lpm.lecturers.destroy', $this->lecturer), $payload)
            ->assertSessionHas('error', 'Data dosen sudah berubah. Muat ulang halaman sebelum menghapus.');
        $this->assertDatabaseHas('lecturers', ['id' => $this->lecturer->id]);
        $this->assertDatabaseHas('teaching_schedules', ['id' => $this->schedule->id]);
    }

    public function test_guests_and_supervisors_cannot_delete_lecturers(): void
    {
        $url = route('lpm.lecturers.destroy', $this->lecturer);
        $this->delete($url, $this->deletePayload())->assertRedirect(route('login'));
        $supervisor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $supervisor->assignRole('supervisor');
        $this->actingAs($supervisor)->delete($url, $this->deletePayload())->assertForbidden();
        $this->assertDatabaseHas('lecturers', ['id' => $this->lecturer->id]);
    }

    public function test_lecturer_with_only_unfinalized_draft_assignments_can_be_deleted_and_cleans_up_drafts(): void
    {
        $this->actingAs($this->admin);
        $stage = $this->period->stages()->create(['stage_no' => 1, 'name' => 'Tahap 1', 'status' => 'DRAFT', 'starts_on' => '2040-10-01', 'ends_on' => '2040-10-31']);
        $supervisor = Lecturer::create(['name' => 'Supervisor Draft', 'is_active' => true]);
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id,
            'target_lecturer_id' => $this->lecturer->id,
            'supervisor_lecturer_id' => $supervisor->id,
            'teaching_occurrence_id' => $this->occurrence->id,
            'assignment_type' => 'AUTO',
            'status' => 'DRAFT',
        ]);

        $this->delete(route('lpm.lecturers.destroy', $this->lecturer), $this->deletePayload())
            ->assertRedirect(route('lpm.lecturers.index', ['period_id' => $this->period->id]))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('lecturers', ['id' => $this->lecturer->id]);
        $this->assertDatabaseMissing('supervision_assignments', ['id' => $assignment->id]);
    }

    /** @return array{period_id: int, row_version: int} */
    private function deletePayload(): array
    {
        return ['period_id' => $this->period->id, 'row_version' => $this->lecturer->row_version];
    }
}
