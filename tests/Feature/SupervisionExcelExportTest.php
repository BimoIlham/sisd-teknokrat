<?php

namespace Tests\Feature;

use App\Models\Faculty;
use App\Models\Lecturer;
use App\Models\StageInstrument;
use App\Models\StudyProgram;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Models\TeachingOccurrence;
use App\Models\User;
use App\Services\Reporting\SupervisionExcelExportService;
use App\Services\Reporting\SupervisionResultPdfService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAcademicSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SupervisionExcelExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_contains_three_consistent_sheets_with_raw_scores_categories_and_stage_notes(): void
    {
        [$admin, $period] = $this->createFixture();
        $first = $this->createLecturer('Dosen Alfa');
        $second = $this->createLecturer('Dosen Beta');
        $this->createResult($period, $first, 1, 0, 0, 'Catatan pertama');
        $this->createResult($period, $first, 2, 7, 3.51, 'Catatan kedua');
        $this->createResult($period, $second, 1, 4, 3.0, 'Catatan Beta');

        $response = $this->actingAs($admin)->get(route('lpm.reports.supervision.excel', ['period_id' => $period->id, 'stage' => 1]));

        $response->assertOk()->assertDownload('Hasil Supervisi - Semester Ganjil 2026-2027.xlsx')
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $workbook = $this->readDownload($response->streamedContent());
        $this->assertSame(['Rekap Tahap 1&2', 'Tahap 1', 'Tahap 2'], $workbook->getSheetNames());
        $summary = $workbook->getSheet(0);
        $firstRow = $this->rowFor($summary, 'Dosen Alfa');
        $secondRow = $this->rowFor($summary, 'Dosen Beta');
        $this->assertSame(0.0, $summary->getCell('C'.$firstRow)->getValue());
        $this->assertSame('Perlu Pembinaan', $summary->getCell('D'.$firstRow)->getValue());
        $this->assertSame(7.0, $summary->getCell('E'.$firstRow)->getValue());
        $this->assertSame('Sangat Baik', $summary->getCell('F'.$firstRow)->getValue());
        $this->assertSame("Tahap 1:\nCatatan pertama\n\nTahap 2:\nCatatan kedua", $summary->getCell('G'.$firstRow)->getValue());
        $this->assertSame('-', $summary->getCell('E'.$secondRow)->getValue());
        $this->assertSame('-', $summary->getCell('F'.$secondRow)->getValue());
        foreach ([1, 2] as $stage) {
            $sheet = $workbook->getSheet($stage);
            $this->assertSame(['Dosen Alfa', 'Dosen Beta'], $this->names($sheet));
            $row = $this->rowFor($sheet, 'Dosen Alfa');
            $this->assertSame($summary->getCell(($stage === 1 ? 'C' : 'E').$firstRow)->getValue(), $sheet->getCell('C'.$row)->getValue());
            $this->assertSame('Catatan '.($stage === 1 ? 'pertama' : 'kedua'), $sheet->getCell('E'.$row)->getValue());
            $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $sheet->getPageSetup()->getOrientation());
            $this->assertSame([1, 8], $sheet->getPageSetup()->getRowsToRepeatAtTop());
            $this->assertSame('C9', $sheet->getFreezePane());
        }
        $workbook->disconnectWorksheets();
    }

    public function test_export_excludes_drafts_cancelled_assignments_and_other_periods_but_keeps_stage_two_only_results(): void
    {
        [$admin, $period] = $this->createFixture();
        $submitted = $this->createLecturer('Hanya Tahap Dua');
        $this->createResult($period, $submitted, 2, 2, 2.5, 'Hasil tahap kedua');
        $this->createResult($period, $this->createLecturer('Masih Draf'), 1, 4, 4, '-', 'DRAFT');
        $cancelled = $this->createResult($period, $this->createLecturer('Dibatalkan'), 1, 4, 4, '-');
        $cancelled->assignment->update(['status' => 'CANCELLED']);
        $otherPeriod = $period->replicate();
        $otherPeriod->name = 'Periode lain';
        $otherPeriod->save();
        foreach ($period->stages as $stage) {
            $copy = $stage->replicate();
            $copy->supervision_period_id = $otherPeriod->id;
            $copy->save();
        }
        $otherStage = $otherPeriod->stages()->where('stage_no', 1)->firstOrFail();
        $foreignAssignment = $this->createResult($period, $this->createLecturer('Periode Lain'), 1, 4, 4, '-')->assignment;
        $foreignAssignment->update(['supervision_stage_id' => $otherStage->id]);

        $response = $this->actingAs($admin)->get(route('lpm.reports.supervision.excel', ['period_id' => $period->id]));

        $response->assertOk();
        $workbook = $this->readDownload($response->streamedContent());
        $sheet = $workbook->getSheet(0);
        $this->assertSame(['Hanya Tahap Dua'], $this->names($sheet));
        $row = $this->rowFor($sheet, 'Hanya Tahap Dua');
        $this->assertSame('-', $sheet->getCell('C'.$row)->getValue());
        $this->assertSame(2.0, $sheet->getCell('E'.$row)->getValue());
        $this->assertSame('Cukup', $sheet->getCell('F'.$row)->getValue());
        $workbook->disconnectWorksheets();
    }

    public function test_export_preserves_snapshot_values_and_does_not_merge_different_lecturers_with_the_same_name(): void
    {
        [$admin, $period] = $this->createFixture();
        $first = $this->createLecturer('Nama Sama');
        $result = $this->createResult($period, $first, 1, 3, 3, 'Catatan saat disubmit');
        $this->createResult($period, $this->createLecturer('Nama Sama'), 1, 4, 4, 'Catatan dosen lain');
        $first->update(['name' => 'Nama master yang berubah', 'study_program_id' => null, 'faculty_id' => null]);
        $result->update(['raw_score' => 999, 'final_score' => 1]);
        $result->answers()->update(['text_value' => 'Catatan berubah setelah submit']);

        $response = $this->actingAs($admin)->get(route('lpm.reports.supervision.excel', ['period_id' => $period->id]));

        $response->assertOk();
        $workbook = $this->readDownload($response->streamedContent());
        $sheet = $workbook->getSheet(0);
        $this->assertSame(['Nama Sama', 'Nama Sama'], $this->names($sheet));
        $row = $this->rowFor($sheet, 'Nama Sama');
        $this->assertSame(3.0, $sheet->getCell('C'.$row)->getValue());
        $this->assertSame('Baik', $sheet->getCell('D'.$row)->getValue());
        $this->assertStringContainsString('Catatan saat disubmit', $sheet->getCell('G'.$row)->getValue());
        $this->assertStringNotContainsString('Catatan berubah', $sheet->getCell('G'.$row)->getValue());
        $this->assertNotContains('Fakultas belum tersedia', array_column($sheet->toArray(), 0));
        $workbook->disconnectWorksheets();
    }

    public function test_export_separates_different_delivery_modes_and_writes_untrusted_names_and_notes_as_literal_text(): void
    {
        [$admin, $period] = $this->createFixture();
        $name = '=HYPERLINK("https://example.com","Nama Dosen")';
        $lecturer = $this->createLecturer($name);
        $this->createResult($period, $lecturer, 1, 4, 4, '=SUM(1,2)');
        $this->createResult($period, $lecturer, 2, 2, 2, 'Catatan online', mode: 'ONLINE');

        $response = $this->actingAs($admin)->get(route('lpm.reports.supervision.excel', ['period_id' => $period->id]));

        $response->assertOk();
        $workbook = $this->readDownload($response->streamedContent());
        $sheet = $workbook->getSheet(1);
        $this->assertSame([$name, $name], $this->names($sheet));
        $row = $this->rowFor($sheet, $name);
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B'.$row)->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('E'.$row)->getDataType());
        $this->assertSame('=SUM(1,2)', $sheet->getCell('E'.$row)->getValue());
        $values = array_column($sheet->toArray(), 0);
        $this->assertContains('Offline', $values);
        $this->assertContains('Online', $values);
        $workbook->disconnectWorksheets();
    }

    public function test_long_structured_notes_remain_complete_and_fit_across_continuation_rows(): void
    {
        [$admin, $period] = $this->createFixture();
        $note = str_repeat("Mahasiswa aktif berdiskusi; materi dan interaksi terus ditingkatkan.\n", 100);
        $result = $this->createResult($period, $this->createLecturer('Dosen Catatan Panjang'), 1, 4, 4, '-');
        $answer = $result->answers()->firstOrFail();
        $answer->update(['evaluation_notes' => ['positive' => $note, 'improvement' => 'Perbaiki materi.', 'suggestion' => 'Tambahkan contoh.']]);
        $this->freezeResult($result->fresh());

        $response = $this->actingAs($admin)->get(route('lpm.reports.supervision.excel', ['period_id' => $period->id]));

        $response->assertOk();
        $workbook = $this->readDownload($response->streamedContent());
        $sheet = $workbook->getSheet(1);
        $row = $this->rowFor($sheet, 'Dosen Catatan Panjang');
        $actualNotes = '';
        $chunkCount = 0;
        while ($row <= $sheet->getHighestDataRow() && $sheet->getCell('E'.$row)->getValue() !== null) {
            $actualNotes .= $sheet->getCell('E'.$row)->getValue();
            $this->assertLessThanOrEqual(409, $sheet->getRowDimension($row)->getRowHeight());
            $row++;
            $chunkCount++;
        }
        $this->assertGreaterThan(1, $chunkCount);
        $this->assertSame("Hal Positif:\n".trim($note)."\n\nHal yang Perlu Diperbaiki:\nPerbaiki materi.\n\nSaran Tindak Lanjut:\nTambahkan contoh.", $actualNotes);
        $workbook->disconnectWorksheets();
    }

    public function test_report_button_exports_both_stages_even_when_the_selected_stage_has_no_results(): void
    {
        [$admin, $period] = $this->createFixture();
        $this->actingAs($admin)->get(route('lpm.reports.supervision', ['period_id' => $period->id]))
            ->assertSee('disabled class="filter-button disabled:cursor-not-allowed', false);
        $this->createResult($period, $this->createLecturer('Dosen Satu Tahap'), 1, 4, 4, '-');

        $response = $this->get(route('lpm.reports.supervision', ['period_id' => $period->id, 'stage' => 2]));

        $response->assertSee(route('lpm.reports.supervision.excel', ['period_id' => $period->id]))
            ->assertSee('Ekspor Excel mencakup Tahap 1 dan 2 pada semester terpilih.');
    }

    public function test_missing_excel_library_redirects_to_the_report_with_a_safe_message(): void
    {
        [$admin, $period] = $this->createFixture();
        $this->createResult($period, $this->createLecturer('Dosen Siap Ekspor'), 1, 4, 4, '-');
        $exporter = $this->partialMock(SupervisionExcelExportService::class);
        $exporter->shouldReceive('isAvailable')->once()->andReturn(false);
        $exporter->shouldNotReceive('build');

        $response = $this->actingAs($admin)->get(route('lpm.reports.supervision.excel', ['period_id' => $period->id]));

        $response->assertRedirect(route('lpm.reports.supervision', ['period_id' => $period->id]))
            ->assertSessionHas('error', 'Ekspor Excel belum siap digunakan. Silakan hubungi administrator untuk menyelesaikan pembaruan aplikasi.');
        $this->followRedirects($response)->assertSee('Ekspor Excel belum siap digunakan.')
            ->assertDontSee('PhpOffice')->assertDontSee('composer install');
    }

    public function test_empty_missing_and_invalid_periods_return_clear_responses(): void
    {
        [$admin, $period] = $this->createFixture();

        $this->actingAs($admin)->get(route('lpm.reports.supervision.excel', ['period_id' => $period->id]))
            ->assertRedirect(route('lpm.reports.supervision', ['period_id' => $period->id]))->assertSessionHas('error');
        $this->getJson(route('lpm.reports.supervision.excel'))->assertUnprocessable()->assertJsonValidationErrors('period_id');
        $this->getJson(route('lpm.reports.supervision.excel', ['period_id' => 'bad']))->assertUnprocessable()->assertJsonValidationErrors('period_id');
        $this->get(route('lpm.reports.supervision.excel', ['period_id' => 999999]))->assertNotFound();
    }

    public function test_guest_cannot_download_excel(): void
    {
        $this->get(route('lpm.reports.supervision.excel', ['period_id' => 1]))->assertRedirect(route('login'));
    }

    public function test_legacy_results_use_saved_raw_scores_without_turning_missing_scores_into_zero(): void
    {
        [$admin, $period] = $this->createFixture();
        $legacy = $this->createResult($period, $this->createLecturer('Hasil Legacy'), 1, 3, 3, 'Catatan legacy');
        $legacy->update(['submission_snapshot' => null]);
        $missing = $this->createResult($period, $this->createLecturer('Total Belum Tersedia'), 1, 0, 3, '-');
        $missing->update(['submission_snapshot' => null, 'raw_score' => null]);

        $response = $this->actingAs($admin)->get(route('lpm.reports.supervision.excel', ['period_id' => $period->id]));

        $response->assertOk();
        $workbook = $this->readDownload($response->streamedContent());
        $sheet = $workbook->getSheet(0);
        $this->assertSame(3.0, $sheet->getCell('C'.$this->rowFor($sheet, 'Hasil Legacy'))->getValue());
        $this->assertSame('-', $sheet->getCell('C'.$this->rowFor($sheet, 'Total Belum Tersedia'))->getValue());
        $workbook->disconnectWorksheets();
    }

    public function test_excel_uses_the_same_category_boundaries_as_the_pdf_report(): void
    {
        [$admin, $period] = $this->createFixture();
        foreach ([['3.51', 'Sangat Baik'], ['3.50', 'Baik'], ['3.00', 'Baik'], ['2.50', 'Cukup'], ['2.49', 'Perlu Pembinaan']] as [$score, $category]) {
            $this->createResult($period, $this->createLecturer('Nilai '.$score), 1, 4, (float) $score, '-');
        }

        $response = $this->actingAs($admin)->get(route('lpm.reports.supervision.excel', ['period_id' => $period->id]));

        $response->assertOk();
        $workbook = $this->readDownload($response->streamedContent());
        $sheet = $workbook->getSheet(0);
        foreach (['3.51' => 'Sangat Baik', '3.50' => 'Baik', '3.00' => 'Baik', '2.50' => 'Cukup', '2.49' => 'Perlu Pembinaan'] as $score => $category) {
            $this->assertSame($category, $sheet->getCell('D'.$this->rowFor($sheet, 'Nilai '.$score))->getValue());
        }
        $workbook->disconnectWorksheets();
    }

    #[TestWith(['supervisor'])]
    #[TestWith(['fakultas'])]
    #[TestWith(['prodi'])]
    public function test_accounts_other_than_lpm_cannot_download_excel(string $role): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user)->get(route('lpm.reports.supervision.excel', ['period_id' => 1]))->assertForbidden();
    }

    public function test_report_and_excel_use_the_same_unit_scope_for_faculty_program_and_leadership(): void
    {
        [, $period] = $this->createFixture();
        $faculty = Faculty::create(['code' => 'EX-A', 'name' => 'Fakultas A', 'is_active' => true]);
        $outsideFaculty = Faculty::create(['code' => 'EX-B', 'name' => 'Fakultas B', 'is_active' => true]);
        $program = $faculty->studyPrograms()->create(['code' => 'EX-ONE', 'name' => 'Prodi Satu', 'is_active' => true]);
        $siblingProgram = $faculty->studyPrograms()->create(['code' => 'EX-TWO', 'name' => 'Prodi Dua', 'is_active' => true]);
        $outsideProgram = $outsideFaculty->studyPrograms()->create(['code' => 'EX-OUT', 'name' => 'Prodi Luar', 'is_active' => true]);
        $emptyProgram = $faculty->studyPrograms()->create(['code' => 'EX-EMPTY', 'name' => 'Prodi Kosong', 'is_active' => true]);
        $lecturers = [
            $this->createLecturer('Dosen A', $program),
            $this->createLecturer('Dosen B', $siblingProgram),
            $this->createLecturer('Dosen C', $outsideProgram),
            Lecturer::create(['name' => 'Dosen Tanpa Prodi', 'faculty_id' => $faculty->id, 'is_active' => true]),
        ];
        foreach ($lecturers as $lecturer) {
            $this->createResult($period, $lecturer, 1, 4, 4, 'Tahap 1');
            $this->createResult($period, $lecturer, 2, 3, 3, 'Tahap 2');
        }

        $facultyAccount = User::factory()->create(['faculty_id' => $faculty->id])->assignRole('fakultas');
        $programAccount = User::factory()->create(['study_program_id' => $program->id])->assignRole('prodi');
        $leadership = User::factory()->create()->assignRole('pimpinan');
        $periodFilter = ['period_id' => $period->id];
        $outsideFilters = ['faculty_id' => $outsideFaculty->id, 'study_program_id' => $outsideProgram->id];
        $facultyPage = ['faculty' => $faculty];
        $programPage = $facultyPage + ['program' => $program];
        $cases = [
            [$facultyAccount, 'unit', $outsideFilters, $faculty->name, ['Dosen A', 'Dosen B']],
            [$programAccount, 'unit', $outsideFilters, $program->name, ['Dosen A']],
            [$leadership, 'pimpinan', [], 'Seluruh Fakultas', ['Dosen A', 'Dosen B', 'Dosen C', 'Dosen Tanpa Prodi']],
            [$leadership, 'pimpinan.faculties', $facultyPage, $faculty->name, ['Dosen A', 'Dosen B']],
            [$leadership, 'pimpinan.faculties.programs', $programPage, $program->name, ['Dosen A']],
        ];
        foreach ($cases as [$account, $prefix, $parameters, $label, $expectedLecturers]) {
            $page = $prefix.'.reports.supervision';
            $excelUrl = route($page.'.excel', $periodFilter + array_diff_key($parameters, $outsideFilters));
            $this->actingAs($account)->get(route($page, $periodFilter + $parameters))->assertOk()
                ->assertViewHas('summary', fn (array $summary): bool => $summary['submitted_count'] === 2 * count($expectedLecturers))
                ->assertSee($excelUrl);
            $response = $this->get(route($page.'.excel', $periodFilter + $parameters))->assertOk()->assertDownload();
            $workbook = $this->readDownload($response->streamedContent());
            $this->assertSame(['Rekap Tahap 1&2', 'Tahap 1', 'Tahap 2'], $workbook->getSheetNames());
            foreach ($workbook->getWorksheetIterator() as $sheet) {
                $this->assertEqualsCanonicalizing($expectedLecturers, $this->names($sheet));
                $this->assertSame($label, $sheet->getCell('A6')->getValue());
            }
            $workbook->disconnectWorksheets();
        }

        $programRoute = 'pimpinan.faculties.programs.reports.supervision';
        $this->get(route($programRoute.'.excel', $periodFilter + $facultyPage + ['program' => $outsideProgram]))->assertNotFound();
        $emptyPage = $periodFilter + $facultyPage + ['program' => $emptyProgram];
        $this->get(route($programRoute, $emptyPage))->assertOk()->assertDontSee(route($programRoute.'.excel', $emptyPage));
        $this->get(route($programRoute.'.excel', $emptyPage))->assertRedirect(route($programRoute, $emptyPage))->assertSessionHas('error');
        $this->actingAs($programAccount)->get(route('pimpinan.reports.supervision.excel', $periodFilter))->assertForbidden();
    }

    /** @return array{User, SupervisionPeriod} */
    private function createFixture(): array
    {
        $this->seed([DatabaseSeeder::class, UserSeeder::class, DemoAcademicSeeder::class]);

        return [User::where('email', 'lpm@teknokrat.ac.id')->firstOrFail(), SupervisionPeriod::active()->with('stages')->firstOrFail()];
    }

    private function createLecturer(string $name, ?StudyProgram $program = null): Lecturer
    {
        return Lecturer::create([
            'name' => $name, 'is_active' => true,
            'study_program_id' => $program?->id ?? Lecturer::where('external_code', 'DSN001')->firstOrFail()->study_program_id,
        ]);
    }

    private function createResult(SupervisionPeriod $period, Lecturer $lecturer, int $stageNumber, float $rawScore, float $finalScore, string $note, string $status = 'SUBMITTED', string $mode = 'OFFLINE'): Supervision
    {
        $stage = $period->stages()->where('stage_no', $stageNumber)->firstOrFail();
        $assignment = SupervisionAssignment::create([
            'supervision_stage_id' => $stage->id, 'target_lecturer_id' => $lecturer->id,
            'supervisor_lecturer_id' => Lecturer::where('external_code', 'DSN001')->firstOrFail()->id,
            'teaching_occurrence_id' => TeachingOccurrence::whereHas('teachingSchedule', fn (Builder $schedules): Builder => $schedules->where('delivery_mode', $mode))->firstOrFail()->id,
            'assignment_type' => 'MANUAL', 'status' => 'FINAL',
        ]);
        $instrument = StageInstrument::where('supervision_stage_id', $stage->id)->where('delivery_mode', $mode)->firstOrFail();
        $result = Supervision::create([
            'supervision_assignment_id' => $assignment->id, 'stage_instrument_id' => $instrument->id,
            'form_template_id' => $instrument->form_template_id, 'scoring_policy_id' => $instrument->scoring_policy_id,
            'raw_score' => $rawScore, 'final_score' => $finalScore, 'status' => $status,
            'delivery_mode' => $mode, 'submitted_at' => $status === 'SUBMITTED' ? '2026-10-15 03:00:00' : null,
        ]);
        $result->answers()->create([
            'question_id' => $instrument->formTemplate->questions()->where('question_type', 'TEXTAREA')->firstOrFail()->id,
            'text_value' => $note,
        ]);
        $this->freezeResult($result->fresh());

        return $result->fresh();
    }

    private function freezeResult(Supervision $result): void
    {
        $report = app(SupervisionResultPdfService::class)->snapshotData($result);
        $result->update(['submission_snapshot' => [
            'schema_version' => 2, 'instrument' => ['delivery_mode' => $result->delivery_mode],
            'scores' => ['raw_score' => (float) $result->raw_score, 'final_score' => (float) $result->final_score],
            'report' => $report,
        ]]);
    }

    private function readDownload(string $content): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'supervision-excel-');
        try {
            file_put_contents($path, $content);

            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    private function rowFor(Worksheet $sheet, string $name): int
    {
        for ($row = 9; $row <= $sheet->getHighestDataRow(); $row++) {
            if ($sheet->getCell('B'.$row)->getValue() === $name) {
                return $row;
            }
        }

        $this->fail('Dosen tidak ditemukan pada sheet: '.$name);
    }

    /** @return list<string> */
    private function names(Worksheet $sheet): array
    {
        $names = [];
        for ($row = 9; $row <= $sheet->getHighestDataRow(); $row++) {
            $value = $sheet->getCell('B'.$row)->getValue();
            if (is_string($value) && $value !== '') {
                $names[] = $value;
            }
        }

        return $names;
    }
}
