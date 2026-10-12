<?php

namespace Tests\Feature;

use App\Models\StageInstrument;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminPopupSecurityTest extends TestCase
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

    #[TestWith(['<img src=x onerror=alert(1)>'])]
    #[TestWith(['</script><script>alert(1)</script>'])]
    public function test_period_popup_names_are_passed_as_escaped_data(string $payload): void
    {
        $period = SupervisionStage::firstOrFail()->period;
        $period->update(['name' => $payload, 'status' => 'CLOSED']);
        $response = $this->actingAs(User::where('email', 'lpm@teknokrat.ac.id')->firstOrFail())->get(route('lpm.periods.index'))->assertOk();
        $this->assertSafeDataset($response->getContent(), 'data-period-name', $payload, 2);
    }

    public function test_assignment_popup_names_are_passed_as_escaped_data(): void
    {
        [, $supervision] = $this->createSupervision();
        $stage = $supervision->assignment->stage;
        $stage->update(['status' => 'DRAFT']);
        $supervision->assignment->targetLecturer->update(['name' => '<img src=x onerror=alert(1)>']);
        $response = $this->actingAs(User::where('email', 'lpm@teknokrat.ac.id')->firstOrFail())
            ->get(route('lpm.assignments.index', ['stage_id' => $stage->id]))->assertOk();
        $this->assertSafeDataset($response->getContent(), 'data-lecturer-name', '<img src=x onerror=alert(1)>', 1);
    }

    private function assertSafeDataset(string $html, string $attribute, string $payload, int $minimum): void
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//*[@'.$attribute.']');
        $this->assertGreaterThanOrEqual($minimum, $nodes->length);
        foreach ($nodes as $node) {
            $this->assertSame($payload, $node->getAttribute($attribute));
            $this->assertStringNotContainsString($payload, $node->getAttribute('onclick').$node->getAttribute('@click'));
        }
        $this->assertSame(0, $xpath->query('//img[@src="x" or @onerror]')->length);
        $this->assertSame(0, $xpath->query('//script[contains(text(), "alert(1)")]')->length);
        $this->assertStringNotContainsString($payload, $html);
        $this->assertStringContainsString('name.textContent = value', $html);
        $this->assertStringNotContainsString('addslashes(', $html);
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
