<?php

namespace Tests\Feature;

use App\Models\Supervision;
use App\Models\SupervisionAnswer;
use App\Models\SupervisionAssignment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ListSubmittedSupervisionsTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
    }

    public function test_submitted_list_shows_the_lecturer_and_supervisor_for_zero_and_nonzero_results_without_writing(): void
    {
        $zero = $this->createSupervision('Dosen Nilai Nol', 'SUBMITTED', 0);
        $positive = $this->createSupervision('Dosen Nilai Positif', 'SUBMITTED', 3);
        $draft = $this->createSupervision('Dosen Masih Draf', 'DRAFT', 0);
        $question = $this->instrument->formTemplate->questions()->firstOrFail();
        SupervisionAnswer::create(['supervision_id' => $zero->id, 'question_id' => $question->id, 'numeric_score' => 0]);
        $zeroBefore = $zero->fresh()->load('answers')->toArray();
        $positiveBefore = $positive->fresh()->toArray();

        $this->artisan('supervisions:submitted')
            ->expectsOutput('ID penilaian: '.$positive->id)->expectsOutput('Dosen dinilai: Dosen Nilai Positif')
            ->expectsOutput('Supervisor: '.$this->supervisor->name)
            ->expectsOutput('ID penilaian: '.$zero->id)->expectsOutput('Dosen dinilai: Dosen Nilai Nol')
            ->expectsOutput('Supervisor: '.$this->supervisor->name)
            ->expectsOutput('Nilai akhir: 0.00 | Baris jawaban: 1')
            ->doesntExpectOutput('ID penilaian: '.$draft->id)
            ->assertSuccessful();

        $this->assertSame($zeroBefore, $zero->fresh()->load('answers')->toArray());
        $this->assertSame($positiveBefore, $positive->fresh()->toArray());
    }

    #[TestWith(['Dosen Dicari'])]
    #[TestWith(['Supervisor Pengujian'])]
    public function test_name_search_matches_either_the_target_lecturer_or_supervisor(string $name): void
    {
        $submitted = $this->createSupervision('Dosen Dicari', 'SUBMITTED', 2);
        $draft = $this->createSupervision('Dosen Dicari Draf', 'DRAFT', 0);
        $this->artisan('supervisions:submitted', ['--name' => $name])
            ->expectsOutput('ID penilaian: '.$submitted->id)
            ->doesntExpectOutput('ID penilaian: '.$draft->id)->assertSuccessful();

        $this->artisan('supervisions:submitted', ['--name' => 'Nama Tidak Ada'])
            ->expectsOutput('Tidak ada penilaian yang sudah disubmit sesuai pencarian.')->assertSuccessful();
    }

    public function test_result_limit_uses_the_latest_submission_time(): void
    {
        $older = $this->createSupervision('Dosen Submit Lama', 'SUBMITTED', 2);
        $older->update(['submitted_at' => now()->subDay()]);
        $newer = $this->createSupervision('Dosen Submit Baru', 'SUBMITTED', 0);

        $this->artisan('supervisions:submitted', ['--limit' => '1'])
            ->expectsOutput('ID penilaian: '.$newer->id)
            ->doesntExpectOutput('ID penilaian: '.$older->id)->assertSuccessful();
    }

    #[TestWith(['0'])]
    #[TestWith(['101'])]
    #[TestWith(['abc'])]
    public function test_invalid_limits_are_rejected(string $limit): void
    {
        $this->artisan('supervisions:submitted', ['--limit' => $limit])
            ->expectsOutput('Batas hasil harus berupa angka antara 1 dan 100.')->assertFailed();
    }

    private function createSupervision(string $lecturerName, string $status, float $score): Supervision
    {
        $target = $this->createSchedulingTarget($lecturerName);
        $occurrence = $target->teachingSchedules()->firstOrFail()->occurrences()->firstOrFail();
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $this->stage->id, 'target_lecturer_id' => $target->id,
            'supervisor_lecturer_id' => $this->supervisor->id, 'teaching_occurrence_id' => $occurrence->id,
            'status' => 'FINAL', 'assignment_type' => 'MANUAL',
        ]);

        return Supervision::create([
            'supervision_assignment_id' => $assignment->id, 'stage_instrument_id' => $this->instrument->id,
            'form_template_id' => $this->instrument->form_template_id, 'scoring_policy_id' => $this->instrument->scoring_policy_id,
            'status' => $status, 'row_version' => 1, 'final_score' => $score,
            'submitted_at' => $status === 'SUBMITTED' ? now() : null,
            'submission_snapshot' => $status === 'SUBMITTED' ? ['legacy' => 'Original final result'] : null,
        ]);
    }
}
