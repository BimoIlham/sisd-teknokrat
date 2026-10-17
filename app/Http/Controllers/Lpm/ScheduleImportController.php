<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\ScheduleImport;
use App\Models\ScheduleImportRow;
use App\Models\SupervisionPeriod;
use App\Models\TeachingSchedule;
use App\Services\ScheduleImport\PeriodLecturerExclusions;
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
        $activePeriod = SupervisionPeriod::where('status', 'ACTIVE')->first();
        if (! $activePeriod || (int) $activePeriod->academic_term_id !== (int) $term->id) {
            throw ValidationException::withMessages(['academic_term_id' => 'Pilih semester dari periode supervisi yang aktif.']);
        }
        $file = $request->file('schedule_pdf');

        $import = null;
        try {
            $import = $this->importService->import($file, $term, auth()->user());
            $alreadyCommitted = $import->status === 'COMMITTED';
            $onlyDeferred = $import->rows()->exists()
                && ! $import->rows()->where('skip_reason', '!=', PeriodLecturerExclusions::DEFERRED_REASON)->exists()
                && ! $import->rows()->whereNull('skip_reason')->exists();
            $committedCount = $alreadyCommitted || $onlyDeferred ? 0 : $this->commitService->commit($import, skipInvalidRows: true);
            $skippedCount = ScheduleImportRow::where('schedule_import_id', $import->id)
                ->whereNotNull('skip_reason')
                ->whereNotIn('skip_reason', [...PeriodLecturerExclusions::intentionalReasons(), PeriodLecturerExclusions::DEFERRED_REASON])
                ->count();
            $activeCount = TeachingSchedule::where('source_import_id', $import->id)
                ->where('is_active', true)
                ->count();
            $intentionalCount = ScheduleImportRow::where('schedule_import_id', $import->id)
                ->whereIn('skip_reason', PeriodLecturerExclusions::intentionalReasons())
                ->count();
            $deferredCount = ScheduleImportRow::where('schedule_import_id', $import->id)
                ->where('skip_reason', PeriodLecturerExclusions::DEFERRED_REASON)->count();
            if ($activeCount === 0 && $skippedCount === 0 && $intentionalCount > 0) {
                $message = 'PDF berhasil diproses. Tidak ada jadwal aktif karena semua dosen pada PDF sudah dikeluarkan dari periode ini.';
            } elseif ($activeCount === 0 && $skippedCount === 0 && $deferredCount > 0) {
                $message = 'PDF berhasil diproses. Nama dosen yang ditunda masih menunggu keputusan LPM.';
            } elseif ($skippedCount > 0) {
                $message = "Upload selesai: {$activeCount} jadwal aktif. {$skippedCount} baris dilewati karena data jadwal atau dosennya belum dapat dipastikan.";
            } elseif ($alreadyCommitted) {
                $message = "Upload jadwal berhasil! {$activeCount} jadwal dari PDF ini tetap aktif.";
            } else {
                $message = "Upload PDF berhasil! {$activeCount} jadwal perkuliahan telah aktif di sistem.";
            }

            if ($this->hasIdentityReviewRows($import)) {
                return redirect()->route('lpm.import.names.show', $import)
                    ->with('success', $message.' Nama dosen yang belum pasti tersedia untuk ditinjau LPM.');
            }

            return redirect()->route('lpm.lecturers.index')
                ->with('committed_count', $committedCount)
                ->with('skipped_count', $skippedCount)
                ->with('import_filename', $file->getClientOriginalName())
                ->with('success', $message);
        } catch (ValidationException $e) {
            if ($import && $this->hasIdentityReviewRows($import)) {
                return redirect()->route('lpm.import.names.show', $import)
                    ->with('error', $e->validator->errors()->first());
            }

            return back()->withInput()->with('error', $e->validator->errors()->first());
        } catch (\Throwable $e) {
            $error = app(UnexpectedErrorReporter::class)->report($e, 'schedule.import');

            return back()->withInput()->with('error', $error['message'])->with('error_reference', $error['reference']);
        }
    }

    private function hasIdentityReviewRows(ScheduleImport $import): bool
    {
        return $import->rows()->whereNull('matched_lecturer_id')
            ->whereIn('validation_status', ['AMBIGUOUS', 'SKIPPED'])
            ->where(function ($query): void {
                $query->whereNull('skip_reason')
                    ->orWhereIn('skip_reason', ['Nama dosen dari PDF tidak terbaca atau tidak cocok dengan direktori dosen.', PeriodLecturerExclusions::DEFERRED_REASON]);
            })->exists();
    }
}
