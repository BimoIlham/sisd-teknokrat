<?php

namespace Tests\Feature;

use App\Models\Lecturer;
use App\Models\PeriodSupervisor;
use App\Models\PeriodTarget;
use App\Models\StageInstrument;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\SystemAudit;
use App\Models\TeachingOccurrence;
use App\Models\User;
use App\Services\Reporting\SupervisorAssignmentLetterService;
use App\Services\Scheduling\StageFinalizationService;
use App\Services\Supervision\DraftService;
use App\Services\Supervision\EvidenceService;
use App\Services\Supervision\SubmitService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityAuditEventsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(DemoAcademicSeeder::class);
        Storage::fake('local');
    }

    public function test_login_password_change_and_logout_use_canonical_events_without_credentials(): void
    {
        $password = Str::random(32);
        $newPassword = 'Changed9'.Str::random(32);
        $user = User::factory()->create(['password' => Hash::make($password), 'must_change_password' => false]);
        $user->assignRole('supervisor');
        $this->withHeader('User-Agent', 'Audit regression')->post('/login', ['email' => $user->email, 'password' => $password, 'remember' => true])->assertRedirect();
        $this->post(route('password.update'), ['current_password' => $password, 'password' => $newPassword, 'password_confirmation' => $newPassword])->assertRedirect();
        $this->post(route('logout'))->assertRedirect();
        foreach (['LOGIN', 'CHANGE_PASSWORD', 'LOGOUT'] as $event) {
            $audit = SystemAudit::where('event', $event)->where('entity_id', $user->id)->firstOrFail();
            $this->assertSame(User::class, $audit->entity_type);
            $this->assertSame($user->id, $audit->user_id);
            $this->assertSame('Audit regression', $audit->user_agent);
            $this->assertNotEmpty($audit->ip_address);
            $serialized = $audit->toJson();
            foreach ([$password, $newPassword, $user->fresh()->password, $user->fresh()->remember_token] as $secret) {
                $this->assertStringNotContainsString($secret, $serialized);
            }
            $this->assertSafePayload($audit);
        }
    }

    public function test_failed_login_and_lockout_are_logged_without_passwords(): void
    {
        $user = User::factory()->create(['password' => Hash::make(Str::random(32))]);
        $wrongPassword = Str::random(32);
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => $wrongPassword])->assertSessionHasErrors('email');
        }
        $this->assertSame(5, SystemAudit::where('event', 'LOGIN_FAILED')->where('entity_id', $user->id)->count());
        $this->assertSame(1, SystemAudit::where('event', 'LOGIN_LOCKOUT')->where('entity_id', $user->id)->count());
        foreach (SystemAudit::where('entity_id', $user->id)->get() as $audit) {
            $this->assertNull($audit->user_id);
            $this->assertSame(User::class, $audit->entity_type);
            $this->assertStringNotContainsString($wrongPassword, $audit->toJson());
            $this->assertSafePayload($audit);
        }
    }

    public function test_supervisor_grant_and_period_revocation_are_logged(): void
    {
        $admin = User::where('email', 'lpm@teknokrat.ac.id')->firstOrFail();
        $period = SupervisionStage::firstOrFail()->period;
        $lecturer = Lecturer::create(['name' => 'Dosen Audit', 'external_code' => 'audit-'.Str::random(10), 'is_active' => true]);
        $this->actingAs($admin)->post(route('lpm.supervisors.store'), ['lecturer_id' => $lecturer->id, 'supervision_period_id' => $period->id])->assertSessionHas('success');
        $initialPassword = Crypt::decryptString(session('supervisor_initial_password'));
        $user = $lecturer->fresh()->user;
        $membership = PeriodSupervisor::where('lecturer_id', $lecturer->id)->where('supervision_period_id', $period->id)->firstOrFail();
        $this->delete(route('lpm.supervisors.destroy', $lecturer), ['period_id' => $period->id])->assertSessionHas('success');
        foreach (['ROLE_GRANTED' => [$user, User::class], 'SUPERVISOR_ACTIVATED' => [$membership, PeriodSupervisor::class], 'SUPERVISOR_REVOKED' => [$membership, PeriodSupervisor::class]] as $event => [$entity, $type]) {
            $audit = SystemAudit::where('event', $event)->where('entity_id', $entity->id)->firstOrFail();
            $this->assertSame($type, $audit->entity_type);
            $this->assertSame($admin->id, $audit->user_id);
            $this->assertSafePayload($audit);
            $this->assertStringNotContainsString((string) $initialPassword, $audit->toJson());
        }
        $this->assertTrue($user->fresh()->hasRole('supervisor'));
        $this->assertFalse($membership->fresh()->is_active);
    }

    public function test_submit_and_finalization_events_store_the_right_entity(): void
    {
        [$user, $supervision] = $this->createSupervision();
        $this->actingAs($user);
        $answers = [];
        foreach ($supervision->formTemplate->questions()->with('options')->get() as $question) {
            if (in_array($question->question_type, ['SCORE', 'YES_NO'], true)) {
                $answers[$question->id] = $question->options->last()->id;
            } elseif ($question->question_type === 'FILE') {
                app(EvidenceService::class)->storeEvidence($supervision, UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf'), $question->id);
            } else {
                $answers[$question->id] = $question->question_type === 'LINK' ? 'https://example.test/evidence' : 'Catatan pengamatan';
            }
        }
        app(DraftService::class)->saveDraft($supervision, $answers, $supervision->fresh()->row_version);
        app(SubmitService::class)->submit($supervision, $user, $supervision->fresh()->row_version);
        $this->assertArrayHasKey('metadata', $supervision->fresh()->snapshot_json);
        $this->assertArrayNotHasKey('new_values', $supervision->fresh()->snapshot_json);
        $audit = SystemAudit::where('event', 'SUBMIT_SUPERVISION')->where('entity_id', $supervision->id)->firstOrFail();
        $this->assertSame(Supervision::class, $audit->entity_type);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSafePayload($audit);

        $stage = $supervision->assignment->stage;
        $stage->period->stages()->update(['status' => 'DRAFT']);
        PeriodTarget::where('supervision_period_id', $stage->supervision_period_id)->delete();
        $this->mock(SupervisorAssignmentLetterService::class)->shouldReceive('issueForStages')->once();
        $admin = User::where('email', 'lpm@teknokrat.ac.id')->firstOrFail();
        $this->actingAs($admin);
        app(StageFinalizationService::class)->finalize($stage->fresh(), $admin);
        $audit = SystemAudit::where('event', 'FINALIZE_STAGE')->where('entity_id', $stage->id)->firstOrFail();
        $this->assertSame(SupervisionStage::class, $audit->entity_type);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSafePayload($audit);
        $this->assertSame(0, SystemAudit::where('event', 'SYSTEM_EVENT')->count());
    }

    private function assertSafePayload(SystemAudit $audit): void
    {
        $values = $audit->new_values ?? [];
        unset($values['must_change_password']);
        $payload = json_encode([$audit->old_values, $values]);
        foreach (['password', 'remember_token', 'session_id', 'csrf', 'service_secret', 'Authorization'] as $key) {
            $this->assertStringNotContainsString($key, $payload);
        }
    }

    /** @return array{User, Supervision} */
    private function createSupervision(): array
    {
        $user = User::where('email', 'supervisor1@teknokrat.ac.id')->firstOrFail();
        $stage = SupervisionStage::firstOrFail();
        $stage->update(['status' => 'FINALIZED', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString()]);
        $occurrence = TeachingOccurrence::with('schedule')->whereHas('schedule', fn ($query) => $query->where('lecturer_id', '<>', $user->lecturer_id))->firstOrFail();
        $instrument = StageInstrument::where('supervision_stage_id', $stage->id)->where('delivery_mode', strtoupper($occurrence->schedule->delivery_mode))->firstOrFail();
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id, 'target_lecturer_id' => $occurrence->schedule->lecturer_id,
            'supervisor_lecturer_id' => $user->lecturer_id, 'teaching_occurrence_id' => $occurrence->id,
            'assignment_type' => 'MANUAL', 'status' => 'FINAL',
        ]);
        $supervision = Supervision::create([
            'supervision_assignment_id' => $assignment->id, 'stage_instrument_id' => $instrument->id,
            'form_template_id' => $instrument->form_template_id, 'scoring_policy_id' => $instrument->scoring_policy_id,
            'status' => 'DRAFT', 'row_version' => 1,
        ]);

        return [$user, $supervision];
    }
}
