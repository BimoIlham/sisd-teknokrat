<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Lpm\AnalyticsController;
use App\Http\Controllers\Lpm\AssignmentWorkspaceController;
use App\Http\Controllers\Lpm\DashboardController as LpmDashboardController;
use App\Http\Controllers\Lpm\ExecutiveReportController;
use App\Http\Controllers\Lpm\InstrumentController;
use App\Http\Controllers\Lpm\LecturerDirectoryController;
use App\Http\Controllers\Lpm\LecturerNameReviewController;
use App\Http\Controllers\Lpm\ScheduleImportController;
use App\Http\Controllers\Lpm\SuperadminDeletionController;
use App\Http\Controllers\Lpm\SuperadminDuplicateController;
use App\Http\Controllers\Lpm\SuperadminUserController;
use App\Http\Controllers\Lpm\SupervisedLecturerController;
use App\Http\Controllers\Lpm\SupervisionPeriodController;
use App\Http\Controllers\Lpm\SupervisorDirectoryController;
use App\Http\Controllers\Lpm\UnitAccountController;
use App\Http\Controllers\Supervisor\AssignmentLetterController;
use App\Http\Controllers\Supervisor\DashboardController as SupervisorDashboardController;
use App\Http\Controllers\Supervisor\PersonalScheduleController;
use App\Http\Controllers\Supervisor\SupervisionExecutionController;
use App\Http\Controllers\Unit\UnitAnalyticsController;
use App\Http\Controllers\Unit\UnitDashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes - Sistem Supervisi Mutu Perkuliahan UTI
|--------------------------------------------------------------------------
*/

// Always start from the login screen.
Route::get('/', fn () => redirect()->route('login'));

// Authentication Routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('guest');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/password/change', [AuthController::class, 'showChangePassword'])->name('password.change');
    Route::post('/password/change', [AuthController::class, 'updatePassword'])->name('password.update');
});

// LPM Routes
Route::middleware(['auth', 'role:superadmin|lpm_admin'])->prefix('lpm')->name('lpm.')->group(function () {
    Route::get('/dashboard', [LpmDashboardController::class, 'index'])->name('dashboard');

    // Supervision Periods & Stages (Periode Supervisi)
    Route::get('/periods', [SupervisionPeriodController::class, 'index'])->name('periods.index');
    Route::post('/periods', [SupervisionPeriodController::class, 'store'])->name('periods.store');
    Route::get('/periods/{period}', [SupervisionPeriodController::class, 'show'])->name('periods.show');
    Route::put('/periods/{period}', [SupervisionPeriodController::class, 'update'])->name('periods.update');
    Route::post('/periods/{period}/toggle-status', [SupervisionPeriodController::class, 'toggleStatus'])->name('periods.toggle_status');
    Route::delete('/periods/{period}', [SupervisionPeriodController::class, 'destroy'])->name('periods.destroy');
    Route::get('/periode-supervisi', [SupervisionPeriodController::class, 'index'])->name('supervision_periods.index');
    Route::get('/periode-audit', [SupervisionPeriodController::class, 'index'])->name('audit_periods.index');

    // Schedule PDF Import
    Route::get('/import', [ScheduleImportController::class, 'index'])->name('import.index');
    Route::post('/import', [ScheduleImportController::class, 'store'])->middleware('throttle:schedule-imports')->name('import.store');
    Route::get('/import/names', [LecturerNameReviewController::class, 'index'])->name('import.names.index');
    Route::get('/import/{import}/names', [LecturerNameReviewController::class, 'show'])->name('import.names.show');
    Route::post('/import/{import}/names/{row}', [LecturerNameReviewController::class, 'resolve'])->name('import.names.resolve');
    Route::get('/import/{import}', [ScheduleImportController::class, 'show'])->name('import.show');

    // Assignment Workspace & Solver
    Route::get('/assignments', [AssignmentWorkspaceController::class, 'index'])->name('assignments.index');
    Route::post('/assignments/periods/{period}/prepare', [AssignmentWorkspaceController::class, 'prepare'])->name('assignments.prepare');
    Route::get('/assignments/stages/{stage}/lecturers/{lecturer}/options', [AssignmentWorkspaceController::class, 'manualOptions'])->name('assignments.manual_options');
    Route::get('/assignments/runs/{run}/preview', [AssignmentWorkspaceController::class, 'proposalPreview'])->name('assignments.proposal_preview');
    Route::post('/assignments/stages/{stage}/solve', [AssignmentWorkspaceController::class, 'runSolver'])->middleware('throttle:schedule-generation')->name('assignments.run_solver');
    Route::post('/assignments/runs/{run}/apply', [AssignmentWorkspaceController::class, 'applyRun'])->middleware('throttle:schedule-commits')->name('assignments.apply_run');
    Route::post('/assignments/stages/{stage}/manual', [AssignmentWorkspaceController::class, 'manualOverride'])->name('assignments.manual_override');
    Route::post('/assignments/stages/{stage}/manual/check', [AssignmentWorkspaceController::class, 'checkManualAssignment'])->name('assignments.manual_check');
    Route::post('/assignments/stages/{stage}/finalize', [AssignmentWorkspaceController::class, 'finalizeStage'])->middleware('throttle:schedule-commits')->name('assignments.finalize_stage');
    Route::post('/assignments/stages/{stage}/reopen', [AssignmentWorkspaceController::class, 'reopenStage'])->name('assignments.reopen_stage');
    Route::delete('/assignments/stages/{stage}/assignments/{assignment}', [AssignmentWorkspaceController::class, 'unassign'])->name('assignments.unassign');
    Route::post('/assignments/stages/{stage}/reset-auto', [AssignmentWorkspaceController::class, 'resetAuto'])->name('assignments.reset_auto');
    Route::post('/assignments/stages/{stage}/lecturers/{lecturer}/toggle-scope', [AssignmentWorkspaceController::class, 'toggleTargetScope'])->name('assignments.toggle_scope');

    // Bank Pertanyaan (Question Banks) & Kebijakan Skor
    Route::get('/instruments', [InstrumentController::class, 'index'])->name('instruments.index');
    Route::get('/instruments/mode/{mode}', [InstrumentController::class, 'byMode'])->whereIn('mode', ['offline', 'online'])->name('instruments.mode');
    Route::get('/instruments/{template}', [InstrumentController::class, 'show'])->name('instruments.show');

    // Question & Section CRUD
    Route::post('/instruments/{template}/questions', [InstrumentController::class, 'storeQuestion'])->name('instruments.questions.store');
    Route::put('/instruments/questions/{question}', [InstrumentController::class, 'updateQuestion'])->name('instruments.questions.update');
    Route::delete('/instruments/questions/{question}', [InstrumentController::class, 'destroyQuestion'])->name('instruments.questions.destroy');
    Route::put('/instruments/sections/{section}', [InstrumentController::class, 'updateSection'])->name('instruments.sections.update');
    Route::delete('/instruments/sections/{section}', [InstrumentController::class, 'destroySection'])->name('instruments.sections.destroy');
    Route::post('/instruments/{template}/sections', [InstrumentController::class, 'storeSection'])->name('instruments.sections.store');

    // Indonesian Aliases for Bank Pertanyaan
    Route::get('/bank-pertanyaan', [InstrumentController::class, 'index'])->name('question_bank.index');
    Route::get('/bank-pertanyaan/{mode}', [InstrumentController::class, 'byMode'])->whereIn('mode', ['offline', 'online'])->name('question_bank.mode');

    // Analytics & Paired Cohort Comparison
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');

    // Executive Infographic Supervision Report (Laporan Hasil Supervisi)
    Route::get('/reports/supervision', [ExecutiveReportController::class, 'index'])->name('reports.supervision');
    Route::get('/supervisions/completed', [SupervisedLecturerController::class, 'index'])->name('supervisions.completed');

    // Lecturer Directory & Reports
    Route::get('/lecturers', [LecturerDirectoryController::class, 'index'])->name('lecturers.index');
    Route::post('/lecturers', [LecturerDirectoryController::class, 'store'])->name('lecturers.store');
    Route::delete('/lecturers/{lecturer}', [LecturerDirectoryController::class, 'destroy'])->name('lecturers.destroy');
    Route::patch('/lecturers/{lecturer}', [LecturerDirectoryController::class, 'updateProfile'])->name('lecturers.update_profile');
    Route::patch('/lecturers/{lecturer}/prodi', [LecturerDirectoryController::class, 'updateStudyProgram'])->name('lecturers.update_prodi');
    Route::get('/lecturers/{lecturer}/periods/{period}/schedules/create', [LecturerDirectoryController::class, 'createSchedule'])->name('lecturers.schedules.create');
    Route::post('/lecturers/{lecturer}/periods/{period}/schedules', [LecturerDirectoryController::class, 'storeSchedule'])->name('lecturers.schedules.store');
    Route::get('/lecturers/{lecturer}/periods/{period}/schedules/{schedule}/edit', [LecturerDirectoryController::class, 'editSchedule'])->name('lecturers.schedules.edit');
    Route::put('/lecturers/{lecturer}/periods/{period}/schedules/{schedule}', [LecturerDirectoryController::class, 'updateSchedule'])->name('lecturers.schedules.update');
    Route::delete('/lecturers/{lecturer}/periods/{period}/schedules/{schedule}', [LecturerDirectoryController::class, 'destroySchedule'])->name('lecturers.schedules.destroy');
    Route::get('/lecturers/{lecturer}/pdf/stage/{stageNo}', [LecturerDirectoryController::class, 'downloadStagePdf'])->middleware('throttle:report-exports')->name('lecturers.stage_pdf');
    Route::get('/lecturers/{lecturer}', [LecturerDirectoryController::class, 'show'])->name('lecturers.show');
    Route::get('/supervisions/{supervision}/pdf', [LecturerDirectoryController::class, 'downloadSupervisionPdf'])->middleware('throttle:report-exports')->name('supervisions.pdf');
    Route::get('/api/faculties/{faculty}/study-programs', [LecturerDirectoryController::class, 'studyProgramsByFaculty'])->name('api.faculty.study_programs');

    // Supervisor Directory & Management
    Route::get('/supervisors', [SupervisorDirectoryController::class, 'index'])->block(30, 10)->name('supervisors.index');
    Route::get('/supervisors/periods/{period}/candidates', [SupervisorDirectoryController::class, 'candidates'])->name('supervisors.candidates');
    Route::post('/supervisors', [SupervisorDirectoryController::class, 'store'])->block(30, 10)->name('supervisors.store');
    Route::post('/supervisors/{supervisor}/reset-password', [SupervisorDirectoryController::class, 'resetPassword'])
        ->middleware('throttle:5,1')->block(30, 10)->name('supervisors.reset_password');
    Route::post('/supervisors/{supervisor}/rotate-legacy-credential', [SupervisorDirectoryController::class, 'rotateLegacyCredential'])
        ->middleware('throttle:5,1')->block(30, 10)->name('supervisors.rotate_legacy_credential');
    Route::get('/supervisors/{supervisor}/stages/{stage}/surat-tugas', [SupervisorDirectoryController::class, 'downloadAssignmentLetter'])->middleware('throttle:report-exports')->name('supervisors.assignment_letter');
    Route::delete('/supervisors/{supervisor}', [SupervisorDirectoryController::class, 'destroy'])->name('supervisors.destroy');
});

Route::middleware(['auth', 'role:lpm_admin'])->prefix('lpm/unit-accounts')->name('lpm.unit_accounts.')->group(function () {
    Route::get('/', [UnitAccountController::class, 'index'])->name('index');
    Route::post('/', [UnitAccountController::class, 'store'])->name('store');
    Route::get('/{user}', [UnitAccountController::class, 'show'])->name('show');
    Route::put('/{user}', [UnitAccountController::class, 'update'])->name('update');
    Route::delete('/{user}', [UnitAccountController::class, 'destroy'])->name('destroy');
    Route::post('/{user}/reset-password', [UnitAccountController::class, 'resetPassword'])->name('reset_password');
});

Route::middleware(['auth', 'role:fakultas|prodi'])->prefix('unit')->name('unit.')->group(function () {
    Route::get('/dashboard', [UnitDashboardController::class, 'index'])->name('dashboard');
    Route::get('/lecturers', [LecturerDirectoryController::class, 'index'])->name('lecturers.index');
    Route::get('/lecturers/{lecturer}', [LecturerDirectoryController::class, 'show'])->name('lecturers.show');
    Route::get('/supervisions/{supervision}/pdf', [LecturerDirectoryController::class, 'downloadSupervisionPdf'])
        ->middleware('throttle:report-exports')->name('supervisions.pdf');
    Route::get('/analytics', [UnitAnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/reports/supervision', [ExecutiveReportController::class, 'index'])->name('reports.supervision');
});

Route::middleware(['auth', 'role:superadmin', 'root_superadmin'])->prefix('superadmin')->name('superadmin.')->group(function () {
    Route::get('/duplicates', [SuperadminDuplicateController::class, 'index'])->name('duplicates.index');
    Route::get('/duplicates/{keep}/{remove}', [SuperadminDuplicateController::class, 'preview'])->name('duplicates.preview');
    Route::post('/duplicates/{keep}/{remove}', [SuperadminDuplicateController::class, 'merge'])->name('duplicates.merge');
    Route::get('/deletions', [SuperadminDeletionController::class, 'index'])->name('deletions.index');
    Route::get('/deletions/{type}/bulk', [SuperadminDeletionController::class, 'previewBulk'])->whereIn('type', ['lecturer', 'supervisor'])->name('deletions.bulk.preview');
    Route::delete('/deletions/{type}/bulk', [SuperadminDeletionController::class, 'destroyBulk'])->whereIn('type', ['lecturer', 'supervisor'])->name('deletions.bulk.destroy');
    Route::get('/deletions/{type}/{id}', [SuperadminDeletionController::class, 'preview'])->whereNumber('id')->name('deletions.preview');
    Route::delete('/deletions/{type}/{id}', [SuperadminDeletionController::class, 'destroy'])->whereNumber('id')->name('deletions.destroy');
    Route::get('/users', [SuperadminUserController::class, 'index'])->name('users.index');
    Route::post('/users', [SuperadminUserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [SuperadminUserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/reset-password', [SuperadminUserController::class, 'resetPassword'])->name('users.reset_password');
});

// Supervisor Routes
Route::middleware(['auth', 'role:supervisor'])->prefix('supervisor')->name('supervisor.')->group(function () {
    Route::get('/dashboard', [SupervisorDashboardController::class, 'index'])->name('dashboard');
    Route::get('/surat-tugas', [AssignmentLetterController::class, 'index'])->name('assignment_letters.index');
    Route::get('/surat-tugas/{document}/download', [AssignmentLetterController::class, 'download'])
        ->whereNumber('document')->middleware('throttle:report-exports')->name('assignment_letters.download');

    // Personal Schedule & PDF Download
    Route::get('/schedule', [PersonalScheduleController::class, 'index'])->name('schedule');
    Route::get('/schedule/download-pdf', [PersonalScheduleController::class, 'downloadPdf'])->middleware('throttle:report-exports')->name('schedule.pdf');

    // Supervision Execution
    Route::get('/assignments', [SupervisionExecutionController::class, 'index'])->name('assignments.index');
    Route::get('/assignments/{assignment}/form', [SupervisionExecutionController::class, 'form'])->name('supervisions.form');
    Route::post('/assignments/{assignment}/start', [SupervisionExecutionController::class, 'start'])->name('supervisions.start');
    Route::post('/supervisions/{supervision}/draft', [SupervisionExecutionController::class, 'saveDraft'])->name('supervisions.draft');
    Route::post('/supervisions/{supervision}/review-draft', [SupervisionExecutionController::class, 'reviewDraft'])->name('supervisions.review_draft');
    Route::get('/supervisions/{supervision}/review', [SupervisionExecutionController::class, 'review'])->name('supervisions.review');
    Route::post('/supervisions/{supervision}/evidence', [SupervisionExecutionController::class, 'uploadEvidence'])->middleware('throttle:supervision-uploads')->name('supervisions.upload_evidence');
    Route::delete('/supervisions/evidence/{evidence}', [SupervisionExecutionController::class, 'deleteEvidence'])->name('supervisions.delete_evidence');
    Route::get('/supervisions/evidence/{evidence}/download', [SupervisionExecutionController::class, 'downloadEvidence'])->name('supervisions.download_evidence');
    Route::post('/supervisions/{supervision}/submit', [SupervisionExecutionController::class, 'submit'])->name('supervisions.submit');
    Route::get('/supervisions/{supervision}/pdf', [SupervisionExecutionController::class, 'downloadPdf'])->middleware('throttle:report-exports')->name('supervisions.pdf');
    Route::get('/supervisions/{supervision}', [SupervisionExecutionController::class, 'show'])->name('supervisions.show');
});
