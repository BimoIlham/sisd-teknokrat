<?php

namespace Tests\Feature;

use App\Models\ScheduleRun;
use App\Models\User;
use App\Services\Scheduling\SolverClientService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class SchedulingResourceProtectionTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    private ?\Closure $responseFactory = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
        Http::fake(fn () => $this->responseFactory ? ($this->responseFactory)() : Http::response([], 503));
    }

    public function test_generation_is_limited_per_user_across_stages(): void
    {
        foreach ([$this->stage, $this->secondStage] as $stage) {
            $this->postJson(route('lpm.assignments.run_solver', $stage))->assertRedirect();
        }
        $this->postJson(route('lpm.assignments.run_solver', $this->stage))->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(2, ScheduleRun::count());
    }

    public function test_browser_generation_limit_returns_to_workspace_with_a_message(): void
    {
        $workspace = route('lpm.assignments.index', ['stage_id' => $this->stage->id]);
        $solve = route('lpm.assignments.run_solver', $this->stage);

        $this->from($workspace)->post($solve)->assertRedirect($workspace);
        $this->from($workspace)->post($solve)->assertRedirect($workspace);
        $this->from($workspace)->post($solve)
            ->assertRedirect($workspace)
            ->assertSessionHas('error', 'Penjadwalan otomatis dibatasi dua kali per menit. Tunggu sebentar sebelum mencoba lagi.');

        $this->assertSame(2, ScheduleRun::count());
    }

    public function test_a_different_administrator_has_a_separate_request_budget(): void
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson(route('lpm.assignments.run_solver', $this->stage))->assertRedirect();
        }
        $other = User::factory()->create(['must_change_password' => false, 'is_active' => true]);
        $other->assignRole('superadmin');
        $this->app['session']->invalidate();
        $this->actingAs($other)->postJson(route('lpm.assignments.run_solver', $this->stage))->assertRedirect();
        $this->assertSame(3, ScheduleRun::count());
    }

    public function test_parallel_generation_in_either_stage_of_a_period_is_rejected(): void
    {
        $called = false;
        $this->responseFactory = function () use (&$called) {
            $called = true;
            $this->assertSame(1, ScheduleRun::where('status', 'RUNNING')->count());
            try {
                app(SolverClientService::class)->solve($this->secondStage, $this->admin);
                $this->fail('Concurrent work on the same period must be rejected.');
            } catch (ConflictHttpException $exception) {
                $this->assertStringContainsString('masih berjalan', $exception->getMessage());
            }
            $this->assertSame(1, ScheduleRun::count());

            return Http::response([], 503);
        };
        $run = app(SolverClientService::class)->solve($this->stage, $this->admin);
        $this->assertSame('OPTIMAL', $run->status);
        $this->assertTrue($called);
    }

    public function test_interrupted_generation_from_another_stage_does_not_lock_the_period_forever(): void
    {
        $interrupted = ScheduleRun::create([
            'supervision_stage_id' => $this->secondStage->id,
            'created_by' => $this->admin->id,
            'status' => 'RUNNING',
            'started_at' => now()->subMinutes(6),
        ]);

        $replacement = app(SolverClientService::class)->solve($this->stage, $this->admin);

        $this->assertSame('FAILED', $interrupted->fresh()->status);
        $this->assertSame('STALE_RUN_RECOVERED', $interrupted->fresh()->error_code);
        $this->assertNotNull($interrupted->fresh()->finished_at);
        $this->assertSame('OPTIMAL', $replacement->status);
    }

    #[TestWith([0])]
    #[TestWith([61])]
    #[TestWith([999999999])]
    public function test_extreme_solver_time_is_rejected_before_work_is_started(int $seconds): void
    {
        try {
            app(SolverClientService::class)->solve($this->stage, $this->admin, $seconds);
            $this->fail('Invalid resource option must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('timeout_seconds', $exception->errors());
        }
        $this->assertSame(0, ScheduleRun::count());
        Http::assertNothingSent();
    }

    public function test_import_has_its_own_budget_without_throttling_normal_pages(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson(route('lpm.import.store'))->assertUnprocessable();
        }
        $this->postJson(route('lpm.import.store'))->assertStatus(429);
        $this->get(route('lpm.import.index'))->assertOk();
    }

    public function test_guests_are_rejected_by_auth_before_user_based_limits(): void
    {
        auth()->logout();
        $this->postJson(route('lpm.assignments.run_solver', $this->stage))->assertUnauthorized();
    }

    public function test_normal_pdf_export_works_and_export_budget_is_enforced(): void
    {
        $this->supervisorUser->assignRole('superadmin');
        $this->app['session']->invalidate();
        $this->actingAs($this->supervisorUser);
        for ($attempt = 0; $attempt < 15; $attempt++) {
            $this->get(route('supervisor.schedule.pdf'))->assertOk()->assertHeader('content-type', 'application/pdf');
        }
        $this->get(route('supervisor.schedule.pdf'))->assertStatus(429);
        $this->get(route('supervisor.schedule'))->assertOk();
    }

    #[TestWith([503])]
    #[TestWith([413])]
    public function test_busy_python_does_not_trigger_internal_fallback_and_the_reservation_is_released(int $status): void
    {
        $this->responseFactory = fn () => Http::response(['detail' => ['code' => 'RESOURCE_BUSY']], $status);
        try {
            app(SolverClientService::class)->solve($this->stage, $this->admin);
            $this->fail('Busy resource must not trigger more solver work.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('solver', $exception->errors());
        }
        $this->assertSame(0, ScheduleRun::where('status', 'RUNNING')->count());
        $this->assertSame('FAILED', ScheduleRun::sole()->status);
        $this->responseFactory = null;
        $this->assertSame('OPTIMAL', app(SolverClientService::class)->solve($this->stage, $this->admin)->status);
    }
}
