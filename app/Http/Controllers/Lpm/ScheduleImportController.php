<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\ScheduleImport;
use App\Models\ScheduleImportRow;
use App\Models\SupervisionPeriod;
use App\Services\ScheduleImport\ScheduleCommitService;
use App\Services\ScheduleImport\ScheduleImportService;
use App\Services\UnexpectedErrorReporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ScheduleImportController extends Controller
{
    public function __construct(
        protected ScheduleImportService $importService,
        protected ScheduleCommitService $commitService
    ) {}

    public function index(): View
    {
        $activePeriod = SupervisionPeriod::where('status', 'ACTIVE')->with('academicTerm')->first();
        $activeTerm = $activePeriod?->academicTerm;

        return view('lpm.import.index', compact('activePeriod', 'activeTerm'));
    }

    public function show(ScheduleImport $import): RedirectResponse
    {
        if ($import->status === 'COMMITTED') {
            return redirect()->route('lpm.lecturers.index');
        }

        return redirect()->route('lpm.import.index');
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $request->filled('academic_term_id')) {
            $activePeriod = SupervisionPeriod::where('status', 'ACTIVE')->first();
            if ($activePeriod?->academic_term_id) {
                $request->merge(['academic_term_id' => $activePeriod->academic_term_id]);
            }
        }

        $request->validate([
            'academic_term_id' => ['required', 'exists:academic_terms,id'],
            'schedule_pdf' => ['required', 'file', 'mimes:pdf', 'max:15360'], // 15MB
        ], [
            'academic_term_id.required' => 'Periode supervisi aktif tidak ditemukan. Pastikan ada periode supervisi yang berstatus Aktif.',
            'schedule_pdf.required' => 'Silakan pilih berkas PDF jadwal perkuliahan terlebih dahulu.',
            'schedule_pdf.mimes' => 'Berkas harus berformat PDF resmi jadwal perkuliahan.',
            'schedule_pdf.max' => 'Ukuran berkas PDF maksimal 15 MB.',
        ]);

        $term = AcademicTerm::findOrFail($request->input('academic_term_id'));
        $file = $request->file('schedule_pdf');

        $import = null;
        try {
            $import = $this->importService->import($file, $term, auth()->user());
            $committedCount = $this->commitService->commit($import, skipInvalidRows: true);
            $skippedCount = ScheduleImportRow::where('schedule_import_id', $import->id)
                ->whereNotNull('skip_reason')
                ->count();
            $message = $skippedCount > 0
                ? "Upload selesai: {$committedCount} jadwal aktif. {$skippedCount} baris tidak diaktifkan karena datanya tidak terbaca dengan jelas."
                : "Upload PDF berhasil! {$committedCount} jadwal perkuliahan telah aktif di sistem.";

            return redirect()->route('lpm.lecturers.index')
                ->with('committed_count', $committedCount)
                ->with('skipped_count', $skippedCount)
                ->with('import_filename', $file->getClientOriginalName())
                ->with('success', $message);
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', $e->validator->errors()->first());
        } catch (\Throwable $e) {
            $error = app(UnexpectedErrorReporter::class)->report($e, 'schedule.import');

            return back()->withInput()->with('error', $error['message'])->with('error_reference', $error['reference']);
        }
    }
}
