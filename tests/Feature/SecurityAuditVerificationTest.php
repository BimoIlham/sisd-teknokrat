<?php

namespace Tests\Feature;

use App\Models\AssignmentAudit;
use App\Models\SystemAudit;
use App\Models\User;
use App\Services\Scheduling\ApplyRunService;
use App\Services\Scheduling\ManualOverrideService;
use App\Services\Scheduling\SolverClientService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SecurityAuditVerificationTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
    }

    #[TestWith(['manual'])]
    #[TestWith(['automatic'])]
    public function test_assignment_audit_preserves_its_own_schema_actor_target_and_timestamp(string $operation): void
    {
        if ($operation === 'manual') {
            $service = app(ManualOverrideService::class);
            $assignment = $service->assign($this->stage, $this->target->id, $this->supervisor->id, $this->occurrence->id, $this->admin);
            $service->assign($this->stage, $this->target->id, $this->supervisor->id, $this->occurrence->id, $this->admin);
            $actions = ['MANUAL_ASSIGN', 'MANUAL_OVERRIDE'];
        } else {
            Http::fake(['*' => Http::response([], 503)]);
            $run = app(SolverClientService::class)->solve($this->stage, $this->admin);
            app(ApplyRunService::class)->apply($run, $run->input_revision, $this->admin);
            $assignment = $this->stage->assignments()->where('target_lecturer_id', $this->target->id)->firstOrFail();
            $actions = ['AUTO_ASSIGN'];
        }
        foreach ($actions as $action) {
            $audit = AssignmentAudit::where('supervision_assignment_id', $assignment->id)->where('action', $action)->sole();
            $this->assertSame($this->admin->id, $audit->actor_user_id);
            $this->assertSame($assignment->id, $audit->assignment->id);
            $this->assertNotNull($audit->created_at);
            $this->assertSame($this->supervisor->id, $audit->new_payload['supervisor_id']);
            $this->assertSame($this->occurrence->id, $audit->new_payload['occurrence_id']);
            foreach (array_keys($audit->new_payload) as $key) {
                $this->assertContains($key, ['supervisor_id', 'occurrence_id', 'assignment_type']);
            }
        }
        $this->assertTrue(AssignmentAudit::where('action', 'PERIOD_PAIR_ASSIGN')->exists());
    }

    public function test_inactive_login_audit_has_no_authenticated_actor_or_credential_payload(): void
    {
        $credential = Str::password(24);
        $this->supervisorUser->update(['password' => Hash::make($credential), 'is_active' => false]);
        Auth::logout();
        $this->post(route('login'), ['email' => $this->supervisorUser->email, 'password' => $credential])->assertSessionHasErrors('email');
        $audit = SystemAudit::where('event', 'LOGIN_DENIED_INACTIVE')->sole();
        $this->assertNull($audit->user_id);
        $this->assertSame(User::class, $audit->entity_type);
        $this->assertSame($this->supervisorUser->id, $audit->entity_id);
        $this->assertNotNull($audit->created_at);
        $this->assertSame(['email_fingerprint' => hash('sha256', strtolower($this->supervisorUser->email))], $audit->new_values);
        $this->assertStringNotContainsString($credential, $audit->toJson());
        $this->assertStringNotContainsString($this->supervisorUser->fresh()->password, $audit->toJson());
    }
}
