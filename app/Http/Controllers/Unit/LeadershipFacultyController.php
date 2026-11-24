<?php

namespace App\Http\Controllers\Unit;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Lpm\LecturerDirectoryController;
use App\Models\Faculty;
use App\Models\Lecturer;
use App\Models\Supervision;
use App\Services\OrganizationalScope;
use App\Services\Reporting\SupervisionResultPdfService;
use App\Services\Scheduling\WeeklySupervisionScheduleGrouper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class LeadershipFacultyController extends Controller
{
    public function redirectToDashboard(): RedirectResponse
    {
        return redirect()->route('pimpinan.dashboard');
    }

    public function redirectToReport(): RedirectResponse
    {
        return redirect()->route('pimpinan.reports.supervision');
    }

    public function showLecturer(Request $request, Faculty $faculty, Lecturer $lecturer, LecturerDirectoryController $directory, WeeklySupervisionScheduleGrouper $scheduleGrouper): View
    {
        return $directory->show($request, $lecturer, $scheduleGrouper);
    }

    public function downloadResult(Request $request, Faculty $faculty, Supervision $supervision, LecturerDirectoryController $directory, SupervisionResultPdfService $pdfService): Response
    {
        $scope = OrganizationalScope::forRequest($request);
        abort_unless($scope->supervisions(Supervision::whereKey($supervision->id))->exists(), 404);

        return $directory->downloadSupervisionPdf($supervision, $pdfService);
    }
}
