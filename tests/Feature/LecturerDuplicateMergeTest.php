<?php

namespace Tests\Feature;

use App\Models\Lecturer;
use App\Models\PeriodTarget;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\TeachingOccurrence;
use App\Models\User;
use App\Services\LecturerDuplicateMergeService;
use App\Services\ScheduleImport\LecturerIdentityResolver;
use App\Services\ScheduleImport\ScheduleImportReferences;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LecturerDuplicateMergeTest extends TestCase
{
    use CreatesSchedulingFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
        $this->admin->update(['is_root_superadmin' => true]);
    }

    private function duplicate(): array
    {
        $duplicate = Lecturer::create(['name' => 'Dosen Pengujian (Instruktur)', 'is_active' => true]);
        $membership = PeriodTarget::create(['supervision_period_id' => $this->period->id,
            'lecturer_id' => $duplicate->id, 'scope_status' => 'INCLUDED', 'selected_by' => $this->admin->id]);
        $schedule = $this->target->teachingSchedules()->firstOrFail()->replicate(['fingerprint']);
        $schedule->lecturer_id = $duplicate->id;
        $schedule->class_name = 'Kelas Duplikat';
        $schedule->save();
        $occurrence = TeachingOccurrence::create([
            'teaching_schedule_id' => $schedule->id, 'original_date' => '2030-10-07', 'occurs_on' => '2030-10-07',
            'starts_at' => $this->occurrence->starts_at, 'ends_at' => $this->occurrence->ends_at, 'status' => 'SCHEDULED',
        ]);

        return [$duplicate, $membership, $schedule, $occurrence];
    }

    public function test_confirmed_merge_keeps_final_history_schedule_and_one_period_membership(): void
    {
        [$duplicate, $membership, $schedule, $occurrence] = $this->duplicate();
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $duplicate->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $occurrence->id,
            'assignment_type' => 'MANUAL', 'status' => 'FINAL',
        ]);
        $history = Supervision::create(['supervision_assignment_id' => $assignment->id, 'status' => 'SUBMITTED']);
        $merges = app(LecturerDuplicateMergeService::class);
        $impact = $merges->preview($this->target, $duplicate);
        $this->assertSame([], $impact['conflicts']);
        $this->get(route('superadmin.duplicates.index'))->assertOk()->assertSee($duplicate->name);
        $this->get(route('superadmin.duplicates.preview', [$this->target, $duplicate]))
            ->assertOk()->assertSee('Dampak per periode');
        $this->post(route('superadmin.duplicates.merge', [$this->target, $duplicate]), [
            'fingerprint' => $impact['fingerprint'], 'confirm_name' => $duplicate->name, 'password' => 'password',
        ])->assertRedirect(route('superadmin.duplicates.index'));

        $this->assertDatabaseMissing('lecturers', ['id' => $duplicate->id]);
        $this->assertDatabaseMissing('period_targets', ['id' => $membership->id]);
        $this->assertSame(1, PeriodTarget::where('supervision_period_id', $this->period->id)->where('lecturer_id', $this->target->id)->count());
        $this->assertDatabaseHas('teaching_schedules', ['id' => $schedule->id, 'lecturer_id' => $this->target->id]);
        $this->assertDatabaseHas('supervision_assignments', ['id' => $assignment->id, 'target_lecturer_id' => $this->target->id]);
        $this->assertDatabaseHas('supervisions', ['id' => $history->id]);
        $this->assertDatabaseHas('lecturer_identity_redirects', ['source_key' => app(ScheduleImportReferences::class)->identityKey($duplicate->name, null)]);
        $this->assertSame($this->target->id, app(LecturerIdentityResolver::class)->resolve($duplicate->name, null, true)?->id);
    }

    public function test_conflicting_final_history_or_changed_preview_aborts_without_partial_merge(): void
    {
        [$duplicate, , , $occurrence] = $this->duplicate();
        $merges = app(LecturerDuplicateMergeService::class);
        $fingerprint = $merges->preview($this->target, $duplicate)['fingerprint'];
        $duplicate->update(['name' => 'Nama Berubah Setelah Pratinjau']);
        try {
            $merges->merge($this->target, $duplicate, $fingerprint, $this->admin);
            $this->fail('Expected a stale preview to be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('lecturers', ['id' => $duplicate->id]);
        }
        $first = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'assignment_type' => 'MANUAL', 'status' => 'FINAL',
        ]);
        $second = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $duplicate->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $occurrence->id,
            'assignment_type' => 'MANUAL', 'status' => 'FINAL',
        ]);
        $impact = $merges->preview($this->target, $duplicate);
        $this->assertNotEmpty($impact['conflicts']);
        $this->assertDatabaseHas('supervision_assignments', ['id' => $first->id, 'target_lecturer_id' => $this->target->id]);
        $this->assertDatabaseHas('supervision_assignments', ['id' => $second->id, 'target_lecturer_id' => $duplicate->id]);
    }

    public function test_only_root_superadmin_can_open_or_merge_and_password_is_required(): void
    {
        [$duplicate] = $this->duplicate();
        $impact = app(LecturerDuplicateMergeService::class)->preview($this->target, $duplicate);
        $lpm = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $lpm->assignRole('lpm_admin');
        $this->actingAs($lpm)->get(route('superadmin.duplicates.index'))->assertForbidden();
        $this->post(route('superadmin.duplicates.merge', [$this->target, $duplicate]), [
            'fingerprint' => $impact['fingerprint'], 'confirm_name' => $duplicate->name, 'password' => 'password',
        ])->assertForbidden();
        $this->actingAs($this->supervisorUser)->get(route('superadmin.duplicates.index'))->assertForbidden();
        $this->actingAs($this->admin)->post(route('superadmin.duplicates.merge', [$this->target, $duplicate]), [
            'fingerprint' => $impact['fingerprint'], 'confirm_name' => $duplicate->name, 'password' => 'wrong',
        ])->assertSessionHasErrors();
        $this->assertDatabaseHas('lecturers', ['id' => $duplicate->id]);
    }
}
