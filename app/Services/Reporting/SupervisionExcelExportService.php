<?php

namespace App\Services\Reporting;

use App\Models\Supervision;
use App\Models\SupervisionPeriod;
use App\Services\OrganizationalScope;
use App\Services\Supervision\EvaluationNotes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SupervisionExcelExportService
{
    public function __construct(private SupervisionResultPdfService $reports) {}

    public function isAvailable(): bool
    {
        return class_exists(Spreadsheet::class) && class_exists(Xlsx::class);
    }

    public function hasResults(SupervisionPeriod $period, ?OrganizationalScope $scope = null): bool
    {
        return $this->submittedResults($period, $scope)->exists();
    }

    public function build(SupervisionPeriod $period, ?OrganizationalScope $scope = null): Spreadsheet
    {
        $period->loadMissing('academicTerm');
        $rows = $this->rows($period, $scope);
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['export' => 'Belum ada hasil supervisi yang sudah dikirim untuk diekspor.']);
        }

        $workbook = new Spreadsheet;
        $workbook->getDefaultStyle()->getFont()->setName('Times New Roman')->setSize(11);
        $workbook->getProperties()->setCreator('Lembaga Penjaminan Mutu - Universitas Teknokrat Indonesia')
            ->setTitle('Hasil Supervisi '.$period->semester_display);

        foreach (['Rekap Tahap 1&2' => null, 'Tahap 1' => 1, 'Tahap 2' => 2] as $title => $stageNumber) {
            $sheet = $stageNumber === null ? $workbook->getActiveSheet() : $workbook->createSheet();
            $sheet->setTitle($title);
            $this->writeSheet($sheet, $period, $rows, $stageNumber, $scope?->label());
        }
        $workbook->setActiveSheetIndex(0);

        return $workbook;
    }

    private function submittedResults(SupervisionPeriod $period, ?OrganizationalScope $scope): Builder
    {
        return Supervision::query()->where('status', 'SUBMITTED')
            ->when($scope, fn (Builder $query): Builder => $scope->supervisions($query, requireStudyProgram: true))
            ->whereHas('assignment', fn (Builder $assignments): Builder => $assignments->where('status', '<>', 'CANCELLED')
                ->whereHas('supervisionStage', fn (Builder $stages): Builder => $stages
                    ->where('supervision_period_id', $period->id)->whereIn('stage_no', [1, 2])));
    }

    /**
     * @return Collection<int, array{lecturer_id: int, name: string, faculty: string, program: string, mode: string, stages: array<int, array{total: ?float, category: string, notes: string}>}>
     */
    private function rows(SupervisionPeriod $period, ?OrganizationalScope $scope): Collection
    {
        $submissions = $this->submittedResults($period, $scope)->with([
            'assignment.targetLecturer.studyProgram.faculty', 'assignment.targetLecturer.faculty',
            'assignment.supervisorLecturer', 'assignment.teachingOccurrence.teachingSchedule.course',
            'assignment.supervisionStage.period.academicTerm', 'formTemplate.sections.questions.options',
            'answers.question.section', 'answers.option', 'answers.evidence', 'scoringPolicy',
        ])->orderBy('id')->get();
        $rows = [];
        foreach ($submissions as $supervision) {
            $report = $this->reports->reportData($supervision);
            $currentProgram = $supervision->assignment->targetLecturer?->studyProgram;
            $faculty = $scope?->facultyId() !== null
                ? $currentProgram?->faculty?->name
                : data_get($report, 'lecturer.faculty.name');
            $program = $scope?->facultyId() !== null ? $currentProgram?->name : data_get($report, 'lecturer.studyProgram.name');
            $faculty = (string) ($faculty ?: 'Fakultas belum tersedia');
            $program = (string) ($program ?: 'Program studi belum tersedia');
            $snapshot = $supervision->submission_snapshot ?? [];
            $mode = match (strtoupper((string) ($snapshot['instrument']['delivery_mode'] ?? $supervision->delivery_mode))) {
                'ONLINE', 'DARING' => 'Online',
                'HYBRID' => 'Hibrida',
                default => 'Offline',
            };
            $lecturerId = (int) $supervision->assignment->target_lecturer_id;
            $key = json_encode([$lecturerId, $mode, $faculty, $program], JSON_THROW_ON_ERROR);
            $rows[$key] ??= [
                'lecturer_id' => $lecturerId, 'name' => '', 'faculty' => $faculty,
                'program' => $program, 'mode' => $mode, 'stages' => [],
            ];
            $stageNumber = (int) $supervision->assignment->supervisionStage->stage_no;
            if ($stageNumber === 2 || $rows[$key]['name'] === '') {
                $rows[$key]['name'] = (string) (data_get($report, 'lecturer.name') ?: 'Dosen tidak tersedia');
            }
            $rawScore = $snapshot['scores']['raw_score'] ?? $supervision->raw_score;
            if ($rawScore === null && ($report['scoredCount'] ?? 0) > 0) {
                $rawScore = str_replace(',', '', (string) $report['rawScore']);
            }
            $rows[$key]['stages'][$stageNumber] = [
                'total' => is_numeric($rawScore) ? (float) $rawScore : null,
                'category' => (string) ($report['predicate'] ?? '-'),
                'notes' => $this->notes($report['structuredSections'] ?? []),
            ];
        }

        return collect(array_values($rows))->sortBy([
            fn (array $left, array $right): int => array_search($left['mode'], ['Offline', 'Online', 'Hibrida'], true)
                <=> array_search($right['mode'], ['Offline', 'Online', 'Hibrida'], true),
            ['faculty', 'asc'], ['program', 'asc'], ['name', 'asc'], ['lecturer_id', 'asc'],
        ])->values();
    }

    /** @param array<int, array{questions?: array<int, array<string, mixed>>}> $sections */
    private function notes(array $sections): string
    {
        $parts = [];
        foreach ($sections as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                $evaluation = EvaluationNotes::toText($question['evaluation_notes'] ?? []);
                if ($evaluation !== '') {
                    $parts[] = $evaluation;
                } elseif (($question['type'] ?? '') === 'TEXTAREA' && ($question['is_answered'] ?? false)) {
                    $parts[] = (string) ($question['answer_display'] ?? '');
                }
                $parts[] = (string) ($question['notes'] ?? '');
            }
        }
        $parts = array_unique(array_filter(array_map('trim', $parts), static fn (string $text): bool => ! in_array($text, ['', '-', '--'], true)));

        return $parts === [] ? '-' : implode("\n\n", $parts);
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function writeSheet(Worksheet $sheet, SupervisionPeriod $period, Collection $rows, ?int $stageNumber, ?string $scopeLabel): void
    {
        $lastColumn = $stageNumber === null ? 'G' : 'E';
        $sheet->setShowGridlines(false);
        $sheet->getDefaultRowDimension()->setRowHeight(24);
        $sheet->mergeCells("A1:{$lastColumn}3");
        $sheet->mergeCells("A4:{$lastColumn}4");
        $sheet->mergeCells("A5:{$lastColumn}5");
        $this->writeText($sheet, 'A1', 'UNIVERSITAS TEKNOKRAT INDONESIA'."\nLEMBAGA PENJAMINAN MUTU");
        $this->writeText($sheet, 'A4', 'HASIL REKAP SUPERVISI PERKULIAHAN');
        $this->writeText($sheet, 'A5', mb_strtoupper($period->semester_display));
        $sheet->getStyle("A1:{$lastColumn}5")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle("A1:{$lastColumn}5")->getFont()->setBold(true)->setSize(14);
        if ($scopeLabel !== null) {
            $sheet->mergeCells("A6:{$lastColumn}6");
            $this->writeText($sheet, 'A6', $scopeLabel);
            $sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $logoPath = public_path('images/logo-teknokrat-sm.png');
        if (is_file($logoPath)) {
            $logo = new Drawing;
            $logo->setName('Logo Universitas Teknokrat Indonesia');
            $logo->setPath($logoPath);
            $logo->setHeight(64);
            $logo->setCoordinates('B1');
            $logo->setOffsetX(6);
            $logo->setWorksheet($sheet);
        }

        $headers = $stageNumber === null
            ? ['NO', 'NAMA DOSEN', 'TOTAL', 'NILAI MUTU AKHIR', 'TOTAL', 'NILAI MUTU AKHIR', 'CATATAN SUPERVISOR']
            : ['NO', 'NAMA DOSEN', 'TOTAL', 'NILAI MUTU AKHIR', 'CATATAN SUPERVISOR'];
        foreach ($headers as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $this->writeText($sheet, $column.'7', $header);
            if (in_array($column, ['A', 'B', $lastColumn], true)) {
                $sheet->mergeCells("{$column}7:{$column}8");
            }
        }
        if ($stageNumber === null) {
            $sheet->mergeCells('C8:D8')->setCellValue('C8', 'Tahap 1');
            $sheet->mergeCells('E8:F8')->setCellValue('E8', 'Tahap 2');
        } else {
            $sheet->mergeCells('C8:D8')->setCellValue('C8', 'Tahap '.$stageNumber);
        }
        $this->fill($sheet, "A7:{$lastColumn}8", '95B3D7');
        $sheet->getStyle("A7:{$lastColumn}8")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension(7)->setRowHeight(32);
        $widths = $stageNumber === null ? [6, 35, 10, 20, 10, 20, 85] : [6, 35, 12, 24, 85];
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }

        $rowNumber = 9;
        $mode = $faculty = $program = null;
        $number = 0;
        foreach ($rows as $row) {
            if ($row['mode'] !== $mode) {
                $mode = $row['mode'];
                $faculty = $program = null;
                $this->section($sheet, $rowNumber++, $lastColumn, $mode, 'E36C09');
            }
            if ($row['faculty'] !== $faculty) {
                $faculty = $row['faculty'];
                $program = null;
                $number = 0;
                $this->section($sheet, $rowNumber++, $lastColumn, $faculty, 'E36C09');
            }
            if ($row['program'] !== $program) {
                $program = $row['program'];
                $this->section($sheet, $rowNumber++, $lastColumn, $program, 'B7B7B7');
            }
            $firstStage = $row['stages'][1] ?? null;
            $secondStage = $row['stages'][2] ?? null;
            $values = $stageNumber === null
                ? [++$number, $row['name'], $firstStage['total'] ?? '-', $firstStage['category'] ?? '-', $secondStage['total'] ?? '-', $secondStage['category'] ?? '-']
                : [++$number, $row['name'], $row['stages'][$stageNumber]['total'] ?? '-', $row['stages'][$stageNumber]['category'] ?? '-'];
            $notes = $stageNumber === null
                ? "Tahap 1:\n".($firstStage['notes'] ?? '-')."\n\nTahap 2:\n".($secondStage['notes'] ?? '-')
                : ($row['stages'][$stageNumber]['notes'] ?? '-');
            $chunks = $this->noteChunks($notes);
            $startRow = $rowNumber;
            foreach ($values as $index => $value) {
                $coordinate = Coordinate::stringFromColumnIndex($index + 1).$startRow;
                if (is_string($value)) {
                    $this->writeText($sheet, $coordinate, $value);
                } else {
                    $sheet->setCellValue($coordinate, $value);
                }
            }
            foreach ($chunks as $chunk) {
                $this->writeText($sheet, $lastColumn.$rowNumber, $chunk['text']);
                $sheet->getRowDimension($rowNumber)->setRowHeight(max(36, $chunk['lines'] * 15 + 12));
                $rowNumber++;
            }
            if (count($chunks) > 1) {
                foreach (array_keys($values) as $index) {
                    $column = Coordinate::stringFromColumnIndex($index + 1);
                    $sheet->mergeCells("{$column}{$startRow}:{$column}".($rowNumber - 1));
                }
            }
            $sheet->getStyle("C{$startRow}:".($stageNumber === null ? 'F' : 'D').($rowNumber - 1))
                ->getNumberFormat()->setFormatCode('General');
        }

        $lastRow = $rowNumber - 1;
        $sheet->getStyle("A7:{$lastColumn}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A7:{$lastColumn}{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle('C9:'.($stageNumber === null ? 'F' : 'D').$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A9:A{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('C9');
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 8)
            ->setPrintArea("A1:{$lastColumn}{$lastRow}");
        $sheet->getHeaderFooter()->setOddFooter('&LUniversitas Teknokrat Indonesia&RHalaman &P / &N');
        $sheet->setSelectedCell('A1');
    }

    private function writeText(Worksheet $sheet, string $coordinate, string $value): void
    {
        $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
    }

    private function fill(Worksheet $sheet, string $range, string $color): void
    {
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
        $sheet->getStyle($range)->getFont()->setBold(true);
    }

    private function section(Worksheet $sheet, int $row, string $lastColumn, string $text, string $color): void
    {
        $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        $this->writeText($sheet, 'A'.$row, $text);
        $this->fill($sheet, "A{$row}:{$lastColumn}{$row}", $color);
    }

    /** @return list<array{text: string, lines: int}> */
    private function noteChunks(string $notes): array
    {
        $chunks = [];
        $text = '';
        $lines = 0;
        foreach (preg_split('/(?<=\n)/u', $notes, -1, PREG_SPLIT_NO_EMPTY) as $line) {
            foreach (mb_str_split($line, 70) as $fragment) {
                if ($lines === 22) {
                    $chunks[] = ['text' => $text, 'lines' => $lines];
                    $text = '';
                    $lines = 0;
                }
                $text .= $fragment;
                $lines++;
            }
        }
        if ($text !== '') {
            $chunks[] = ['text' => $text, 'lines' => $lines];
        }

        return $chunks;
    }
}
