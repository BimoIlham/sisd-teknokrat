<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\Lecturer;
use App\Models\PeriodTarget;
use App\Models\ScheduleImport;
use App\Models\ScheduleImportRow;
use App\Services\ScheduleImport\LecturerIdentityResolver;
use App\Services\ScheduleImport\PeriodLecturerExclusions;
use App\Services\ScheduleImport\ScheduleCommitService;
use App\Services\ScheduleImport\ScheduleImportService;
use App\Services\ScheduleImport\ScheduleRowValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LecturerNameReviewController extends Controller
{
    public function index(): View
    {
        $imports = ScheduleImport::query()->latest('id')->limit(30)->get()
            ->filter(fn (ScheduleImport $import): bool => $this->unresolvedRows($import)->isNotEmpty());

        return view('lpm.import.names.index', compact('imports'));
    }

    public function show(ScheduleImport $import, LecturerIdentityResolver $resolver): View
    {
        $lecturers = Lecturer::where('is_active', true)->orderBy('name')->get(['id', 'name', 'external_code']);
        $lecturerOptions = $lecturers->map(fn (Lecturer $lecturer): array => [
            'id' => $lecturer->id, 'name' => $lecturer->name, 'nidn' => $lecturer->nidn, 'reference' => false,
        ]);
        foreach ($resolver->references->profiles() as $profile) {
            if (! ($profile['is_active'] ?? false)) {
                continue;
            }
            $alreadyAvailable = $lecturers->contains(fn (Lecturer $lecturer): bool => (! empty($profile['external_code']) && $lecturer->external_code === $profile['external_code'])
                || LecturerIdentityResolver::signature($lecturer->name) === LecturerIdentityResolver::signature($profile['name']));
            if ($alreadyAvailable) {
                continue;
            }
            $externalCode = (string) ($profile['external_code'] ?? '');
            $lecturerOptions->push([
                'id' => 'reference:'.$profile['identity_key'], 'name' => $profile['name'],
                'nidn' => preg_match('/^\d{10}$/', $externalCode) ? $externalCode : null,
                'reference' => true,
            ]);
        }
        $lecturerOptions = $lecturerOptions->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
        $rows = $this->unresolvedRows($import)->map(fn (ScheduleImportRow $row): array => [
            'row' => $row,
            'match' => $resolver->match((string) $row->parsed_lecturer_name, $row->normalized_payload['lecturer_external_code'] ?? null),
        ]);

        return view('lpm.import.names.show', compact('import', 'rows', 'lecturerOptions'));
    }

    public function resolve(Request $request, ScheduleImport $import, ScheduleImportRow $row, LecturerIdentityResolver $resolver, ScheduleImportService $imports, ScheduleCommitService $commits, ScheduleRowValidator $validator): RedirectResponse
    {
        abort_unless($row->schedule_import_id === $import->id, 404);
        $data = $request->validate([
            'action' => ['required', 'in:same,new,defer'],
            'lecturer_id' => ['nullable', 'integer', 'exists:lecturers,id'],
            'reference_key' => ['nullable', 'string', 'max:100'],
        ]);
        $wasExcluded = DB::transaction(function () use ($data, $import, $row, $resolver, $imports, $commits, $validator): bool {
            $import = ScheduleImport::query()->lockForUpdate()->findOrFail($import->id);
            $row = ScheduleImportRow::query()->lockForUpdate()->findOrFail($row->id);
            $name = (string) $row->parsed_lecturer_name;
            if (! $this->unresolvedRows($import)->contains('id', $row->id)) {
                throw ValidationException::withMessages(['row' => 'Baris ini sudah diproses atau sengaja dikeluarkan dari periode.']);
            }
            if ($import->supervision_period_id && app(PeriodLecturerExclusions::class)->contains($import->supervision_period_id, $name)) {
                throw ValidationException::withMessages(['row' => 'Dosen ini sengaja dikeluarkan dari periode.']);
            }
            $lecturer = null;
            if ($data['action'] === 'same') {
                if (isset($data['lecturer_id']) === isset($data['reference_key'])) {
                    throw ValidationException::withMessages(['lecturer_id' => 'Pilih satu dosen dari hasil pencarian.']);
                }
                if (isset($data['reference_key'])) {
                    $profile = collect($resolver->references->profiles())
                        ->firstWhere('identity_key', $data['reference_key']);
                    if (! $profile || ! ($profile['is_active'] ?? false)) {
                        throw ValidationException::withMessages(['reference_key' => 'Referensi dosen tidak tersedia. Muat ulang halaman dan pilih kembali.']);
                    }
                    $externalCode = (string) ($profile['external_code'] ?? '');
                    $canonicalMatch = $resolver->match($profile['name'], preg_match('/^\d{10}$/', $externalCode) ? $externalCode : null);
                    if (($canonicalMatch['profile']['identity_key'] ?? null) !== $profile['identity_key']) {
                        throw ValidationException::withMessages(['reference_key' => 'Referensi dosen memiliki konflik identitas. Periksa melalui audit duplikat.']);
                    }
                    $lecturer = $canonicalMatch['lecturer'] ?? $resolver->references->restoreProfile($profile);
                    if (! $lecturer) {
                        throw ValidationException::withMessages(['reference_key' => 'Dosen dari referensi tidak dapat dipulihkan. Periksa status dosen.']);
                    }
                } else {
                    $lecturer = Lecturer::where('is_active', true)->findOrFail($data['lecturer_id']);
                }
                $pdfNidn = $row->normalized_payload['lecturer_external_code'] ?? null;
                if ($pdfNidn && $lecturer->nidn && $pdfNidn !== $lecturer->nidn) {
                    throw ValidationException::withMessages(['lecturer_id' => 'NIDN pada PDF bertentangan dengan dosen yang dipilih.']);
                }
                if ($pdfNidn && $lecturer->external_code && ! $lecturer->nidn) {
                    throw ValidationException::withMessages(['lecturer_id' => 'Kode identitas dosen perlu diperiksa sebelum NIDN PDF dapat disimpan.']);
                }
                if ($pdfNidn && ! $lecturer->external_code) {
                    if (Lecturer::where('external_code', $pdfNidn)->exists()) {
                        throw ValidationException::withMessages(['lecturer_id' => 'NIDN pada PDF sudah dimiliki dosen lain.']);
                    }
                    $lecturer->update(['external_code' => $pdfNidn]);
                }
                $resolver->verifyAlias($lecturer, $name, (int) auth()->id());
                if ($import->supervision_period_id && PeriodTarget::where('supervision_period_id', $import->supervision_period_id)
                    ->where('lecturer_id', $lecturer->id)->where('scope_status', 'EXCLUDED')->exists()) {
                    app(PeriodLecturerExclusions::class)->exclude($import->supervision_period_id, [$name], (int) auth()->id());
                    foreach ($this->unresolvedRows($import) as $related) {
                        if (LecturerIdentityResolver::signature((string) $related->parsed_lecturer_name) === LecturerIdentityResolver::signature($name)) {
                            $related->update(['validation_status' => 'SKIPPED', 'is_approved' => false,
                                'skip_reason' => PeriodLecturerExclusions::SKIP_REASON,
                                'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
                        }
                    }

                    return true;
                }
            } elseif ($data['action'] === 'new') {
                $nidn = $row->normalized_payload['lecturer_external_code'] ?? null;
                $known = $resolver->match($name, $nidn);
                if ($known['lecturer'] || $known['profile'] || str_starts_with($known['reason'], 'Lebih dari satu kandidat')) {
                    throw ValidationException::withMessages(['row' => 'Nama ini sudah memiliki referensi atau kandidat ganda. Periksa identitas yang ada sebelum membuat dosen baru.']);
                }
                if ($nidn && Lecturer::where('external_code', $nidn)->exists()) {
                    throw ValidationException::withMessages(['row' => 'NIDN ini sudah dimiliki dosen lain. Pilih Orang yang sama.']);
                }
                $lecturer = Lecturer::create([
                    'name' => preg_replace('/\s*\(\s*instruktur\s*\)/iu', '', $name),
                    'external_code' => $nidn,
                    'is_active' => true,
                    'identity_sources' => ['name' => ['source' => 'manual', 'value' => $name, 'reviewed_by' => auth()->id()]],
                ]);
            }

            $signature = LecturerIdentityResolver::signature($name);
            foreach ($this->unresolvedRows($import) as $related) {
                if (LecturerIdentityResolver::signature((string) $related->parsed_lecturer_name) !== $signature) {
                    continue;
                }
                $payload = $related->normalized_payload ?? [];
                $issues = $validator->validate($payload, $related->validation_issues ?? []);
                $valid = $lecturer && (float) $related->parser_confidence >= 0.85
                    && ! collect($issues)->contains(fn (array $issue): bool => ($issue['severity'] ?? '') === 'ERROR');
                $related->update([
                    'matched_lecturer_id' => $valid ? $lecturer->id : null,
                    'validation_status' => $valid ? 'VALID' : 'SKIPPED',
                    'is_approved' => (bool) $valid,
                    'reviewed_by' => auth()->id(), 'reviewed_at' => now(),
                    'skip_reason' => $valid ? null : ($data['action'] === 'defer' ? PeriodLecturerExclusions::DEFERRED_REASON : 'Data jadwal belum valid untuk diaktifkan.'),
                ]);
            }
            if ($lecturer) {
                $imports->retryUnmatchedRows($import->fresh());
            }
            $fresh = $import->fresh();
            if ($fresh->status === 'COMMITTED' && $fresh->rows()->where('validation_status', 'VALID')->whereNull('committed_schedule_id')->exists()) {
                $fresh->update(['status' => 'PENDING_REVIEW']);
            }
            if ($fresh->status === 'PENDING_REVIEW' && $fresh->rows()->where('validation_status', 'VALID')->exists()) {
                $commits->commit($fresh, true);
            }

            return false;
        }, 3);

        $message = $wasExcluded
            ? 'Identitas terverifikasi. Dosen ini tetap dikeluarkan dari periode dan jadwalnya tidak diaktifkan.'
            : 'Keputusan nama dosen tersimpan; baris yang valid telah diproses ulang.';

        return redirect()->route('lpm.import.names.show', $import)->with('success', $message);
    }

    private function unresolvedRows(ScheduleImport $import): Collection
    {
        return $import->rows()->whereNull('matched_lecturer_id')
            ->whereIn('validation_status', ['AMBIGUOUS', 'SKIPPED'])
            ->where(function ($query): void {
                $query->whereNull('skip_reason')
                    ->orWhereIn('skip_reason', ['Nama dosen dari PDF tidak terbaca atau tidak cocok dengan direktori dosen.', PeriodLecturerExclusions::DEFERRED_REASON]);
            })
            ->orderBy('row_no')->get()
            ->reject(fn (ScheduleImportRow $row): bool => in_array($row->skip_reason, PeriodLecturerExclusions::intentionalReasons(), true)
                || ! trim((string) $row->parsed_lecturer_name)
                || preg_match('/^(?:team|tim(?:\s|$))/iu', (string) $row->parsed_lecturer_name) === 1);
    }
}
