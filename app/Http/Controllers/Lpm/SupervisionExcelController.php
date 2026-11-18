<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\SupervisionPeriod;
use App\Services\Reporting\SupervisionExcelExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupervisionExcelController extends Controller
{
    public function __invoke(Request $request, SupervisionExcelExportService $exporter): StreamedResponse|RedirectResponse
    {
        $request->validate(['period_id' => ['required', 'integer']]);
        $period = SupervisionPeriod::with('academicTerm')->findOrFail($request->integer('period_id'));
        if (! $exporter->hasResults($period)) {
            return redirect()->route('lpm.reports.supervision', ['period_id' => $period->id])
                ->with('error', 'Belum ada hasil supervisi yang sudah dikirim pada semester ini untuk diekspor.');
        }

        if (! $exporter->isAvailable()) {
            Log::error('Supervision Excel export unavailable: PhpSpreadsheet is missing from the Composer autoloader. Run composer install with the deployed composer.lock.');

            return redirect()->route('lpm.reports.supervision', ['period_id' => $period->id])
                ->with('error', 'Ekspor Excel belum siap digunakan. Silakan hubungi administrator untuk menyelesaikan pembaruan aplikasi.');
        }

        $workbook = $exporter->build($period);
        $semester = $period->academicTerm?->semester_label ?? 'Semester Supervisi';
        $academicYear = str_replace('/', '-', $period->academicTerm?->academic_year ?? '-');

        return response()->streamDownload(static function () use ($workbook): void {
            try {
                $writer = new Xlsx($workbook);
                $writer->setPreCalculateFormulas(false);
                $writer->save('php://output');
            } finally {
                $workbook->disconnectWorksheets();
            }
        }, "Hasil Supervisi - {$semester} {$academicYear}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
