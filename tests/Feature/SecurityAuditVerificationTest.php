<?php

namespace Tests\Feature;

use App\Models\AssignmentAudit;
use App\Models\FormSection;
use App\Models\FormTemplate;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SystemAudit;
use App\Models\User;
use App\Services\InstrumentVersionService;
use App\Services\Scheduling\ApplyRunService;
use App\Services\Scheduling\ManualOverrideService;
use App\Services\Scheduling\SolverClientService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class SecurityAuditVerificationTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
    }

    public function test_instrument_version_and_publication_record_actor_target_and_safe_metadata(): void
    {
        $source = $this->instrument->formTemplate;
        $this->post(route('lpm.instruments.duplicate', $source))->assertRedirect();
        $copy = FormTemplate::where('code', $source->code)->where('version', 2)->firstOrFail();
        $audit = SystemAudit::where('event', 'INSTRUMENT_VERSION_CREATED')->sole();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame(FormTemplate::class, $audit->entity_type);
        $this->assertSame($copy->id, $audit->entity_id);
        $this->assertNotNull($audit->created_at);
        $this->assertEquals(['source_template_id' => $source->id, 'source_version' => 1, 'version' => 2, 'status' => 'DRAFT'], $audit->new_values);
        $this->post(route('lpm.instruments.publish', $copy))->assertRedirect();
        $publication = SystemAudit::where('event', 'INSTRUMENT_PUBLISHED')->sole();
        $this->assertSame($this->admin->id, $publication->user_id);
        $this->assertSame(FormTemplate::class, $publication->entity_type);
        $this->assertSame($copy->id, $publication->entity_id);
        $this->assertNotNull($publication->created_at);
        $this->assertSame(['status' => 'DRAFT'], $publication->old_values);
        $this->assertEquals(['version' => 2, 'status' => 'PUBLISHED'], $publication->new_values);
        foreach ([$audit, $publication] as $event) {
            $payload = json_encode([$event->old_values, $event->new_values]);
            foreach (['password', 'token', 'secret', 'session', 'question_text', $this->admin->password] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, $payload);
            }
        }
        $this->assertSame(0, SystemAudit::where('event', 'SYSTEM_EVENT')->count());
        $count = SystemAudit::count();
        $this->get(route('lpm.instruments.show', $copy))->assertRedirect();
        $this->assertSame($count, SystemAudit::count());
    }

    #[TestWith(['duplicate'])]
    #[TestWith(['publish'])]
    public function test_audit_failure_rolls_back_the_instrument_operation(string $operation): void
    {
        $template = $operation === 'publish'
            ? app(InstrumentVersionService::class)->duplicate($this->instrument->formTemplate)
            : $this->instrument->formTemplate;
        $models = [FormTemplate::class, FormSection::class, Question::class, QuestionOption::class, SystemAudit::class];
        $counts = array_map(fn (string $model): int => $model::count(), $models);
        $status = $template->status;
        $original = Model::getEventDispatcher();
        $dispatcher = clone $original;
        $dispatcher->listen('eloquent.creating: '.SystemAudit::class, fn () => throw new RuntimeException('Simulated audit failure.'));
        Model::setEventDispatcher($dispatcher);
        $this->withoutExceptionHandling();
        try {
            try {
                $this->post(route('lpm.instruments.'.$operation, $template));
                $this->fail('Audit failure must roll back the associated instrument change.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Simulated audit failure.', $exception->getMessage());
            }
        } finally {
            Model::setEventDispatcher($original);
        }
        $this->assertSame($counts, array_map(fn (string $model): int => $model::count(), $models));
        $this->assertSame($status, $template->fresh()->status);
    }

    public function test_denied_instrument_actions_do_not_create_success_audits(): void
    {
        $count = SystemAudit::count();
        $this->actingAs($this->supervisorUser);
        foreach (['duplicate', 'publish'] as $action) {
            $this->post(route('lpm.instruments.'.$action, $this->instrument->formTemplate))->assertForbidden();
        }
        $this->assertSame($count, SystemAudit::count());
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
