<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Faculty;
use App\Models\Lecturer;
use App\Models\PeriodSupervisor;
use App\Models\PeriodTarget;
use App\Models\ScheduleRun;
use App\Models\SchedulingState;
use App\Models\StudyProgram;
use App\Models\SupervisionAssignment;
use App\Services\Scheduling\ApplyRunService;
use App\Services\Scheduling\ManualOverrideService;
use App\Services\Scheduling\SchedulingInputSnapshot;
use App\Services\Scheduling\SolverClientService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ScheduleRunFreshnessTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
        Http::fake(['*' => Http::response([], 503)]);
    }

    private function solve(): ScheduleRun
    {
        return app(SolverClientService::class)->solve($this->stage, $this->admin);
    }

    public function test_solver_captures_server_revision_and_real_snapshot(): void
    {
        $transactionLevel = DB::transactionLevel();
        Http::fake(function () use ($transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel(), 'Solver HTTP must not be inside its own transaction.');

            return Http::response([], 503);
        });
        $run = $this->solve();
        $this->assertSame(10, $run->input_revision);
        $this->assertSame(10, $run->fresh()->input_revision);
        $this->assertNotEmpty($run->input_payload['sources']['occurrences']);
        $this->assertGreaterThan(0, $run->input_payload['solver']['candidate_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $run->input_payload['solver']['payload_hash']);
        $this->assertSame(app(SchedulingInputSnapshot::class)->hash($run->fresh()->input_payload), $run->input_hash);
        $this->assertSame('OPTIMAL', $run->status);
    }

    public function test_old_run_is_rejected_even_when_frontend_sends_latest_revision(): void
    {
        $run = $this->solve();
        SchedulingState::whereKey(1)->update(['revision' => 11]);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Usulan sudah kedaluwarsa karena data penjadwalan berubah. Buat usulan baru.');
        try {
            app(ApplyRunService::class)->apply($run, 11, $this->admin);
        } finally {
            $this->assertSame(0, $this->stage->assignments()->count());
            $this->assertSame('OPTIMAL', $run->fresh()->status);
        }
    }

    public function test_fresh_run_uses_server_revision_even_with_fake_frontend_revision(): void
    {
        $run = $this->solve();
        $this->assertSame(1, app(ApplyRunService::class)->apply($run, 999, $this->admin));
        $this->assertSame('APPLIED', $run->fresh()->status);
        $this->assertSame(11, SchedulingState::findOrFail(1)->revision);
        $this->assertSame(2, SupervisionAssignment::where('target_lecturer_id', $this->target->id)->count());
        $this->assertSame(1, SupervisionAssignment::where('target_lecturer_id', $this->target->id)->pluck('supervisor_lecturer_id')->unique()->count());
    }

    public function test_run_is_stale_when_revision_changes_during_solver_call(): void
    {
        Http::fake(function () {
            SchedulingState::whereKey(1)->update(['revision' => 11]);

            return Http::response([], 503);
        });
        $run = $this->solve();
        $this->assertSame(10, $run->input_revision);
        $this->assertSame('STALE', $run->fresh()->status);
        $this->assertNotEmpty($run->proposed_assignments);
        $this->expectException(ConflictHttpException::class);
        app(ApplyRunService::class)->apply($run, 11, $this->admin);
    }

    public function test_material_change_without_revision_bump_during_solver_is_also_stale(): void
    {
        Http::fake(function () {
            PeriodSupervisor::where('supervision_period_id', $this->period->id)->update(['is_active' => false]);

            return Http::response([], 503);
        });
        $this->assertSame('STALE', $this->solve()->status);
    }

    public function test_revoked_supervisor_cannot_be_applied_even_without_revision_bump(): void
    {
        $run = $this->solve();
        PeriodSupervisor::where('supervision_period_id', $this->period->id)->update(['is_active' => false]);
        $this->expectException(ConflictHttpException::class);
        app(ApplyRunService::class)->apply($run, 10, $this->admin);
    }

    public function test_changed_target_schedule_invalidates_run_without_revision_bump(): void
    {
        $run = $this->solve();
        $this->occurrence->teachingSchedule->update(['is_active' => false]);
        $this->expectException(ConflictHttpException::class);
        app(ApplyRunService::class)->apply($run, 10, $this->admin);
    }

    public function test_input_hash_changes_with_material_schedule_or_constraint_changes(): void
    {
        $snapshots = app(SchedulingInputSnapshot::class);
        $initial = $snapshots->hash($snapshots->capture($this->stage));
        $this->occurrence->update(['status' => 'CANCELLED']);
        $changed = $snapshots->hash($snapshots->capture($this->stage));
        $this->assertNotSame($initial, $changed);
        $this->occurrence->update(['status' => 'SCHEDULED']);
        $this->assertSame($initial, $snapshots->hash($snapshots->capture($this->stage)));
        $this->period->activeRule->update(['config' => ['buffer_minutes' => 15]]);
        $this->assertNotSame($initial, $snapshots->hash($snapshots->capture($this->stage)));
    }

    public function test_canonical_hash_ignores_associative_key_order(): void
    {
        $snapshots = app(SchedulingInputSnapshot::class);
        $first = ['stage' => ['ends_on' => '2030-10-31', 'id' => 1], 'rules' => ['a' => 1, 'b' => ['x' => 2, 'y' => 3]]];
        $second = ['rules' => ['b' => ['y' => 3, 'x' => 2], 'a' => 1], 'stage' => ['id' => 1, 'ends_on' => '2030-10-31']];
        $this->assertSame($snapshots->hash($first), $snapshots->hash($second));
    }

    public function test_legacy_run_without_source_snapshot_is_rejected(): void
    {
        $run = ScheduleRun::create(['supervision_stage_id' => $this->stage->id, 'status' => 'OPTIMAL']);
        $this->expectException(ConflictHttpException::class);
        app(ApplyRunService::class)->apply($run, 10, $this->admin);
    }

    public function test_python_recommendation_for_excluded_target_is_rejected(): void
    {
        $excluded = $this->createSchedulingTarget('Dosen Dikecualikan');
        PeriodTarget::where('lecturer_id', $excluded->id)->update(['scope_status' => 'EXCLUDED']);
        $run = $this->solve();
        $run->proposed_assignments = [['target_lecturer_id' => $excluded->id, 'supervisor_lecturer_id' => $this->supervisor->id,
            'occurrence_id' => $excluded->teachingSchedules()->first()->occurrences()->first()->id]];
        $run->save();
        $this->expectException(ConflictHttpException::class);
        app(ApplyRunService::class)->apply($run, 10, $this->admin);
    }

    public function test_python_recommendation_cannot_use_another_targets_occurrence(): void
    {
        $other = $this->createSchedulingTarget('Dosen Lain');
        $run = $this->solve();
        $run->proposed_assignments = [['target_lecturer_id' => $this->target->id, 'supervisor_lecturer_id' => $this->supervisor->id,
            'occurrence_id' => $other->teachingSchedules()->first()->occurrences()->first()->id]];
        $run->save();
        $this->expectException(ConflictHttpException::class);
        app(ApplyRunService::class)->apply($run, 10, $this->admin);
    }

    public function test_overlapping_recommendations_roll_back_entire_apply(): void
    {
        $other = $this->createSchedulingTarget('Dosen Bentrok');
        $run = $this->solve();
        $run->proposed_assignments = array_map(fn ($target): array => [
            'target_lecturer_id' => $target->id, 'supervisor_lecturer_id' => $this->supervisor->id,
            'occurrence_id' => $target->teachingSchedules()->first()->occurrences()->where('occurs_on', '2030-10-07')->first()->id,
        ], [$this->target, $other]);
        $run->save();
        $this->expectException(ConflictHttpException::class);
        try {
            app(ApplyRunService::class)->apply($run, 10, $this->admin);
        } finally {
            $this->assertSame(0, SupervisionAssignment::where('supervision_stage_id', $this->stage->id)->count());
            $this->assertSame(0, SupervisionAssignment::where('supervision_stage_id', $this->secondStage->id)->count());
        }
    }

    public function test_apply_reads_status_again_instead_of_trusting_old_model_instance(): void
    {
        $run = $this->solve();
        ScheduleRun::whereKey($run->id)->update(['status' => 'APPLIED']);
        $this->expectException(ConflictHttpException::class);
        app(ApplyRunService::class)->apply($run, 10, $this->admin);
    }

    public function test_apply_endpoint_does_not_require_frontend_revision(): void
    {
        $run = $this->solve();
        $this->post(route('lpm.assignments.apply_run', $run), [])->assertSessionHas('success');
        $this->assertSame('APPLIED', $run->fresh()->status);
    }

    public function test_rule_bootstrap_happens_before_snapshot_capture_and_does_not_stale_its_own_run(): void
    {
        $rule = $this->period->activeRule;
        $this->period->update(['active_scheduling_rule_id' => null]);
        $rule->delete();
        $run = $this->solve();
        $this->assertSame('OPTIMAL', $run->status);
        $this->assertSame(11, $run->input_revision);
        $this->assertNotNull($run->input_payload['sources']['rule']);
        $this->assertSame($run->scheduling_rule_id, $this->period->fresh()->active_scheduling_rule_id);
        $this->assertSame(1, app(ApplyRunService::class)->apply($run, 11, $this->admin));
    }

    /** @return array<string, array{string}> */
    public static function invalidCurrentCandidates(): array
    {
        return ['inactive target' => ['target'], 'inactive supervisor' => ['supervisor'],
            'inactive schedule' => ['schedule'], 'cancelled occurrence' => ['occurrence'],
            'outside stage dates' => ['dates'], 'different term' => ['term']];
    }

    #[DataProvider('invalidCurrentCandidates')]
    public function test_solver_recommendations_are_revalidated_even_if_the_source_hash_is_current(string $invalidField): void
    {
        $occurrenceId = $this->occurrence->id;
        match ($invalidField) {
            'target' => $this->target->update(['is_active' => false]),
            'supervisor' => $this->supervisor->update(['is_active' => false]),
            'schedule' => $this->occurrence->teachingSchedule->update(['is_active' => false]),
            'occurrence' => $this->occurrence->update(['status' => 'CANCELLED']),
            'dates' => $occurrenceId = $this->target->teachingSchedules()->first()->occurrences()->where('occurs_on', '2031-01-06')->first()->id,
            'term' => $this->occurrence->teachingSchedule->update(['academic_term_id' => AcademicTerm::create([
                'academic_year' => '2031/2032', 'semester' => 'GENAP', 'starts_on' => '2031-02-01', 'ends_on' => '2031-06-30', 'timezone' => 'Asia/Jakarta',
            ])->id]),
        };
        $run = $this->solve();
        $run->status = 'OPTIMAL';
        $run->proposed_assignments = [['target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'occurrence_id' => $occurrenceId]];
        $run->save();
        $this->expectException(ConflictHttpException::class);
        try {
            app(ApplyRunService::class)->apply($run, 10, $this->admin);
        } finally {
            $this->assertSame(0, SupervisionAssignment::whereIn('supervision_stage_id', [$this->stage->id, $this->secondStage->id])->count());
        }
    }

    public function test_apply_rejects_locked_counterpart_with_another_lecturers_occurrence(): void
    {
        $other = $this->createSchedulingTarget('Dosen Pemilik Slot Lain');
        app(ManualOverrideService::class)->assign($this->stage, $this->target->id, $this->supervisor->id, $this->occurrence->id, $this->admin);
        $foreignOccurrence = $other->teachingSchedules()->first()->occurrences()->where('occurs_on', '2031-01-06')->first();
        $counterpart = $this->secondStage->assignments()->where('target_lecturer_id', $this->target->id)->firstOrFail();
        $counterpart->update(['teaching_occurrence_id' => $foreignOccurrence->id, 'status' => 'FINAL']);
        $run = $this->solve();
        $this->expectException(ValidationException::class);
        try {
            app(ApplyRunService::class)->apply($run, 11, $this->admin);
        } finally {
            $this->assertSame('FINAL', $counterpart->fresh()->status);
            $this->assertSame('MANUAL', $this->stage->assignments()->where('target_lecturer_id', $this->target->id)->first()->assignment_type);
        }
    }

    public function test_supervisor_revocation_endpoint_bumps_revision_and_cannot_be_bypassed_with_latest_revision(): void
    {
        $run = $this->solve();
        $this->delete(route('lpm.supervisors.destroy', $this->supervisor), ['period_id' => $this->period->id])->assertSessionHas('success');
        $this->assertSame(11, SchedulingState::findOrFail(1)->revision);
        $this->post(route('lpm.assignments.apply_run', $run), ['revision' => 11])
            ->assertSessionHas('error', 'Usulan sudah kedaluwarsa karena data penjadwalan berubah. Buat usulan baru.');
        $this->assertSame(0, $this->stage->assignments()->count());
    }

    public function test_solver_endpoint_does_not_report_success_or_open_preview_for_stale_output(): void
    {
        Http::fake(function () {
            SchedulingState::whereKey(1)->increment('revision');

            return Http::response([], 503);
        });
        $this->post(route('lpm.assignments.run_solver', $this->stage))
            ->assertSessionHas('error', 'Usulan sudah kedaluwarsa karena data penjadwalan berubah. Buat usulan baru.')
            ->assertSessionMissing('success')->assertSessionMissing('show_proposal_preview');
        $this->assertSame('STALE', ScheduleRun::where('supervision_stage_id', $this->stage->id)->latest('id')->firstOrFail()->status);
    }

    /** @return array<string, array{string}> */
    public static function sourceEditEndpoints(): array
    {
        return ['profile' => ['update_profile'], 'study program' => ['update_prodi']];
    }

    #[DataProvider('sourceEditEndpoints')]
    public function test_material_directory_edits_bump_revision_and_invalidate_older_runs(string $endpoint): void
    {
        $faculty = Faculty::create(['code' => 'SCHED_TEST', 'name' => 'Fakultas Pengujian', 'is_active' => true]);
        $program = StudyProgram::create(['code' => 'SCHED_TEST', 'name' => 'Prodi Pengujian', 'faculty_id' => $faculty->id, 'is_active' => true]);
        $run = $this->solve();
        $payload = ['study_program_id' => $program->id];
        if ($endpoint === 'update_profile') {
            $payload += ['name' => 'Nama Baru Dosen Pengujian', 'nidn' => null, 'faculty_id' => $faculty->id];
        }
        $this->patchJson(route('lpm.lecturers.'.$endpoint, $this->target), $payload)->assertOk()->assertJson(['success' => true]);
        $this->assertSame(11, SchedulingState::findOrFail(1)->revision);
        $this->assertSame($program->id, $this->target->fresh()->study_program_id);
        $this->expectException(ConflictHttpException::class);
        app(ApplyRunService::class)->apply($run, 11, $this->admin);
    }

    public function test_apply_keeps_a_valid_manual_pair_when_solver_recommends_a_different_active_supervisor(): void
    {
        app(ManualOverrideService::class)->assign($this->stage, $this->target->id, $this->supervisor->id, $this->occurrence->id, $this->admin);
        $other = Lecturer::create(['name' => 'Supervisor Pengujian Kedua', 'is_active' => true]);
        PeriodSupervisor::create(['supervision_period_id' => $this->period->id, 'lecturer_id' => $other->id, 'is_active' => true, 'selected_by' => $this->admin->id]);
        $run = $this->solve();
        $run->proposed_assignments = [['target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $other->id, 'occurrence_id' => $this->occurrence->id]];
        $run->save();
        $this->assertSame(1, app(ApplyRunService::class)->apply($run, 11, $this->admin));
        $assignments = SupervisionAssignment::where('target_lecturer_id', $this->target->id)->get();
        $this->assertCount(2, $assignments);
        $this->assertSame([$this->supervisor->id], $assignments->pluck('supervisor_lecturer_id')->unique()->values()->all());
        $this->assertSame(['MANUAL'], $assignments->pluck('assignment_type')->unique()->values()->all());
        $this->assertTrue($assignments->every(fn (SupervisionAssignment $assignment): bool => $assignment->is_locked));
    }
}
