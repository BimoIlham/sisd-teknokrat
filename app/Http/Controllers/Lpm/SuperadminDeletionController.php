<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\Lecturer;
use App\Models\ScheduleImport;
use App\Models\SupervisionPeriod;
use App\Models\TeachingSchedule;
use App\Services\PermanentDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SuperadminDeletionController extends Controller
{
    public function index(Request $request): View
    {
        $type = $request->query('type', 'lecturer');
        abort_unless(in_array($type, ['lecturer', 'supervisor', 'schedule', 'import', 'period'], true), 404);
        $periods = SupervisionPeriod::latest('starts_on')->get();
        $selectedPeriod = $request->filled('period_id')
            ? $periods->firstWhere('id', $request->integer('period_id'))
            : ($periods->firstWhere('status', 'ACTIVE') ?? $periods->first());
        abort_if($request->filled('period_id') && ! $selectedPeriod, 404);
        $bulkCount = $type === 'lecturer' ? Lecturer::count() : null;
        $items = match ($type) {
            'lecturer' => Lecturer::where(function ($query) use ($selectedPeriod): void {
                $query->whereHas('periodTargets', fn ($targets) => $targets->where('supervision_period_id', $selectedPeriod?->id)->where('scope_status', 'INCLUDED'))
                    ->orWhereHas('periodSupervisors', fn ($supervisors) => $supervisors->where('supervision_period_id', $selectedPeriod?->id))
                    ->orWhereHas('teachingSchedules', fn ($schedules) => $schedules->where('supervision_period_id', $selectedPeriod?->id));
            })->orderBy('name')->paginate(20),
            'supervisor' => Lecturer::whereHas('periodSupervisors')->orWhereHas('user', fn ($query) => $query->role('supervisor'))->orderBy('name')->paginate(20),
            'schedule' => TeachingSchedule::with(['lecturer', 'course'])->latest('id')->paginate(20),
            'import' => ScheduleImport::latest('id')->paginate(20),
            'period' => SupervisionPeriod::latest('id')->paginate(20),
        };

        return view('lpm.superadmin.deletions', compact('type', 'items', 'periods', 'selectedPeriod', 'bulkCount'));
    }

    public function preview(Request $request, string $type, int $id, PermanentDeletionService $deletions): View
    {
        $periodId = $type === 'lecturer'
            ? ($request->integer('period_id') ?: SupervisionPeriod::where('status', 'ACTIVE')->value('id'))
            : null;
        if ($type === 'lecturer' && ! $periodId) {
            throw ValidationException::withMessages(['period_id' => 'Pilih periode supervisi terlebih dahulu.']);
        }
        $impact = $type === 'lecturer'
            ? $deletions->previewPeriodLecturer($id, $periodId)
            : $deletions->preview($type, $id);

        return view('lpm.superadmin.deletion-preview', compact('type', 'id', 'impact', 'periodId'));
    }

    public function previewBulk(string $type, PermanentDeletionService $deletions): View
    {
        $impact = $deletions->previewBulk($type);

        return view('lpm.superadmin.deletion-bulk-preview', compact('type', 'impact'));
    }

    public function destroyBulk(Request $request, string $type, PermanentDeletionService $deletions): RedirectResponse
    {
        $data = $request->validate([
            'confirmation_phrase' => ['required', 'string'],
            'preview_token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string'],
        ]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Kata sandi tidak sesuai.']);
        }
        $result = $deletions->deleteBulk($type, $data['confirmation_phrase'], $data['preview_token'], $request->user());
        $message = 'Seluruh '.($type === 'lecturer' ? 'dosen' : 'supervisor').' dan data terkait berhasil dihapus permanen.';
        if ($result['failed_files'] !== []) {
            $message .= ' Beberapa file gagal dibersihkan: '.implode(', ', $result['failed_files']);
        }

        return redirect()->route('superadmin.deletions.index', ['type' => $type])->with('success', $message);
    }

    public function destroy(Request $request, string $type, int $id, PermanentDeletionService $deletions): RedirectResponse
    {
        $data = $request->validate([
            'confirmation_name' => ['required', 'string'],
            'preview_token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string'],
            'period_id' => [Rule::requiredIf($type === 'lecturer'), 'nullable', 'integer', 'exists:supervision_periods,id'],
        ]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Kata sandi tidak sesuai.']);
        }
        $periodId = $request->integer('period_id') ?: SupervisionPeriod::where('status', 'ACTIVE')->value('id');
        $result = $type === 'lecturer'
            ? $deletions->deletePeriodLecturer($id, $periodId, $data['confirmation_name'], $data['preview_token'], $request->user())
            : $deletions->delete($type, $id, $data['confirmation_name'], $data['preview_token'], $request->user());
        $message = 'Data dan relasi terkait dihapus permanen.';
        if ($result['failed_files'] !== []) {
            $message .= ' Beberapa file gagal dibersihkan: '.implode(', ', $result['failed_files']);
        }

        return redirect()->route('superadmin.deletions.index', ['type' => $type])->with('success', $message);
    }
}
