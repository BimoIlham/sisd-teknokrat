<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\GeneratedDocument;
use App\Models\SupervisionPeriod;
use App\Models\User;
use App\Services\Reporting\SupervisorAssignmentLetterService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AssignmentLetterController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user()->fresh();
        $this->authorizeLecturer($user);
        $periods = SupervisionPeriod::query()->with('academicTerm')
            ->whereHas('supervisors', fn (Builder $query): Builder => $query
                ->where('lecturer_id', $user->lecturer_id)->where('is_active', true))
            ->latest('starts_on')->get();
        $selectedPeriod = $request->filled('period_id')
            ? $periods->firstWhere('id', $request->integer('period_id')) : null;
        abort_if($request->filled('period_id') && ! $selectedPeriod, 404);
        $letters = $this->availableLetters($user)
            ->when($selectedPeriod, fn (Builder $query): Builder => $query->where('supervision_period_id', $selectedPeriod->id))
            ->with(['period.academicTerm', 'stage'])->latest('generated_at')->latest('id')
            ->paginate(12)->withQueryString();

        return view('supervisor.assignment-letters', compact('letters', 'periods', 'selectedPeriod'));
    }

    public function download(Request $request, int $document, SupervisorAssignmentLetterService $letterService): Response
    {
        $user = $request->user()->fresh();
        $this->authorizeLecturer($user);
        $letterDocument = $this->availableLetters($user)->with('stage')->findOrFail($document);
        $letter = $letterDocument->filter_snapshot ?? [];
        $letter['issued_on'] = now('Asia/Jakarta')->locale('id')->translatedFormat('j F Y');
        $name = Str::slug($letter['supervisor_name'] ?? $user->lecturer->name);

        return $letterService->render($letter)
            ->download('surat-tugas-'.$name.'-tahap-'.$letterDocument->stage->stage_no.'.pdf')
            ->header('Cache-Control', 'no-store, private');
    }

    private function authorizeLecturer(?User $user): void
    {
        abort_unless($user?->is_active && $user->isSupervisor() && $user->lecturer?->is_active, 403);
    }

    private function availableLetters(User $user): Builder
    {
        return GeneratedDocument::query()
            ->where('document_type', SupervisorAssignmentLetterService::DOCUMENT_TYPE)
            ->where('supervisor_lecturer_id', $user->lecturer_id)->where('status', 'READY')
            ->whereHas('stage', fn (Builder $query): Builder => $query
                ->where('status', 'FINALIZED')
                ->whereColumn('supervision_stages.supervision_period_id', 'generated_documents.supervision_period_id')
                ->whereHas('assignments', fn (Builder $assignments): Builder => $assignments
                    ->where('supervisor_lecturer_id', $user->lecturer_id)->whereIn('status', ['FINAL', 'COMPLETED'])))
            ->whereHas('period.supervisors', fn (Builder $query): Builder => $query
                ->where('lecturer_id', $user->lecturer_id)->where('is_active', true));
    }
}
