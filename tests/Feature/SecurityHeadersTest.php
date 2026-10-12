<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Services\Supervision\SubmitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
    }

    private function assertBaseline(TestResponse $response): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_authenticated_html_and_guest_login_have_headers_without_hsts_or_enforced_csp(): void
    {
        $response = $this->get(route('lpm.assignments.index'))->assertOk();
        $this->assertBaseline($response);
        $response->assertHeaderMissing('Strict-Transport-Security')->assertHeaderMissing('Content-Security-Policy');
        auth()->logout();
        $response = $this->get(route('login'))->assertOk();
        $this->assertBaseline($response);
        $response->assertHeaderMissing('Strict-Transport-Security')->assertHeaderMissing('Content-Security-Policy');
    }

    #[TestWith(['local', false, false])]
    #[TestWith(['local', true, false])]
    #[TestWith(['production', false, false])]
    #[TestWith(['production', true, true])]
    public function test_hsts_requires_both_production_and_https(string $environment, bool $secure, bool $expected): void
    {
        $this->app['env'] = $environment;
        auth()->logout();
        $url = ($secure ? 'https' : 'http').'://sisd-teknokrat.test/login';
        $response = $this->get($url)->assertOk();
        $this->assertBaseline($response);
        if ($expected) {
            $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        } else {
            $response->assertHeaderMissing('Strict-Transport-Security');
        }
    }

    public function test_redirect_and_json_responses_keep_status_body_and_headers(): void
    {
        $response = $this->get(route('login'))->assertRedirect(route('lpm.dashboard'));
        $this->assertBaseline($response);
        $response = $this->actingAs($this->supervisorUser)->getJson(route('lpm.assignments.index'))->assertForbidden();
        $this->assertBaseline($response);
        $this->assertStringStartsWith('application/json', $response->headers->get('Content-Type'));
    }

    public function test_real_report_pdf_response_keeps_its_mime_and_contents(): void
    {
        $this->stage->update(['status' => 'FINALIZED']);
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $this->target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $this->occurrence->id,
            'status' => 'FINAL', 'assignment_type' => 'MANUAL',
        ]);
        $supervision = Supervision::create([
            'supervision_assignment_id' => $assignment->id, 'form_template_id' => $this->instrument->form_template_id,
            'scoring_policy_id' => $this->instrument->scoring_policy_id, 'status' => 'DRAFT', 'row_version' => 1,
        ]);
        $this->answerSupervision($supervision);
        $this->actingAs($this->supervisorUser);
        app(SubmitService::class)->submit($supervision, $this->supervisorUser, 1);
        $response = $this->actingAs($this->admin)->get(route('lpm.supervisions.pdf', $supervision))->assertOk();
        $this->assertBaseline($response);
        $response->assertHeader('Content-Type', 'application/pdf')->assertDownload();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_signed_storage_image_preserves_signature_protection_mime_and_existing_csp(): void
    {
        Storage::fake('local');
        $image = UploadedFile::fake()->image('header-test.png');
        Storage::disk('local')->put('header-test.png', $image->getContent());
        $url = URL::temporarySignedRoute('storage.local', now()->addMinutes(5), ['path' => 'header-test.png'], absolute: false);
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertBaseline($response);
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        $this->get('/storage/header-test.png')->assertForbidden();
    }

    #[TestWith(['text/css', 'body { color: red; }'])]
    #[TestWith(['application/javascript', 'window.headerTest = true;'])]
    public function test_middleware_preserves_asset_mime_and_body(string $mime, string $body): void
    {
        $response = app(SecurityHeaders::class)->handle(Request::create('/build/assets/test'), fn (Request $request) => response($body, 200, ['Content-Type' => $mime]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($mime, $response->headers->get('Content-Type'));
        $this->assertSame($body, $response->getContent());
    }
}
