<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\GeneratedDocument;
use App\Models\Lecturer;
use App\Models\PeriodSupervisor;
use App\Models\PeriodTarget;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\TeachingSchedule;
use App\Models\User;
use App\Services\Reporting\SupervisorAssignmentLetterService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SupervisorAssignmentLetterTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected Lecturer $supervisor;

    protected Lecturer $otherSupervisor;

    protected SupervisionPeriod $period;

    protected SupervisionStage $stage;

    protected SupervisionStage $secondStage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Storage::fake('local');
        $this->travelTo(now()->setTimezone('Asia/Jakarta')->setDate(2030, 10, 15)->setTime(23, 30));
        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
        $this->actingAs($this->admin);
        $term = AcademicTerm::create([
            'academic_year' => '2030/2031', 'semester' => 'GANJIL',
            'starts_on' => '2030-09-01', 'ends_on' => '2031-01-31',
        ]);
        $this->period = SupervisionPeriod::create([
            'academic_term_id' => $term->id, 'name' => 'Periode Surat Tugas',
            'starts_on' => '2030-10-16', 'ends_on' => '2030-12-31',
            'status' => 'ACTIVE', 'created_by' => $this->admin->id,
        ]);
        $this->stage = $this->period->stages()->create([
            'stage_no' => 1, 'name' => 'Tahap 1', 'starts_on' => '2030-10-16', 'ends_on' => '2030-10-31', 'status' => 'DRAFT',
        ]);
        $this->secondStage = $this->period->stages()->create([
            'stage_no' => 2, 'name' => 'Tahap 2', 'starts_on' => '2030-11-01', 'ends_on' => '2030-12-31', 'status' => 'DRAFT',
        ]);
        $this->supervisor = Lecturer::create(['name' => 'Dr. Ryan Randy Suryono, M.Kom.', 'external_code' => 'LETTER-SUP-1', 'is_active' => true]);
        $this->otherSupervisor = Lecturer::create(['name' => 'Supervisor Kedua, M.Kom.', 'external_code' => 'LETTER-SUP-2', 'is_active' => true]);
        foreach ([$this->supervisor, $this->otherSupervisor] as $supervisor) {
            PeriodSupervisor::create(['supervision_period_id' => $this->period->id, 'lecturer_id' => $supervisor->id, 'is_active' => true, 'selected_by' => $this->admin->id]);
        }
        foreach ([['Permata, S.Si., M.Si.', 'OFFLINE', $this->supervisor], ['Berlintina Permatasari, S.P., M.M.', 'ONLINE', $this->supervisor], ['Dosen Khusus Supervisor Kedua, M.T.', 'OFFLINE', $this->otherSupervisor]] as [$name, $mode, $supervisor]) {
            $this->createAssignment($name, $mode, $supervisor);
        }
    }

    public function test_finalization_registers_downloadable_letters_with_correct_supervisors_modes_and_deadline(): void
    {
        $this->createAssignment('Dosen Penugasan Dibatalkan', 'OFFLINE', $this->supervisor, 'CANCELLED');
        $completedAssignment = $this->stage->assignments()->where('status', 'DRAFT')->firstOrFail();
        $completedAssignment->update(['status' => 'COMPLETED']);
        $this->post(route('lpm.assignments.finalize_stage', $this->stage))->assertSessionHas('success');
        $this->assertSame('COMPLETED', $completedAssignment->fresh()->status);
        $this->assertSame(2, SupervisionAssignment::whereIn('supervision_stage_id', [$this->stage->id, $this->secondStage->id])->where('status', 'CANCELLED')->count());
        $this->assertSame(['FINAL'], $this->secondStage->assignments()->where('status', '<>', 'CANCELLED')->pluck('status')->unique()->values()->all());
        $letters = $this->letters();
        $this->assertCount(2, $letters);
        $letter = $letters->firstWhere('supervisor_lecturer_id', $this->supervisor->id);
        $this->assertSame('001/LPM-UTI/X/2030', $letter->document_number);
        $this->assertSame('15 Oktober 2030', $letter->filter_snapshot['issued_on']);
        $this->assertSame('31 Oktober 2030', $letter->filter_snapshot['ends_on']);
        $this->assertSame([
            ['label' => 'Offline', 'lecturers' => ['Permata, S.Si., M.Si.']],
            ['label' => 'Online', 'lecturers' => ['Berlintina Permatasari, S.P., M.M.']],
        ], $letter->filter_snapshot['groups']);
        foreach ($letters as $document) {
            $this->assertNull($document->file_path);
            $this->assertNull($document->sha256);
            $this->assertSame('READY', $document->status);
        }
        $this->get(route('lpm.supervisors.index', ['period_id' => $this->period->id]))
            ->assertOk()->assertSee('Surat Tugas T1')->assertSee('Surat Tugas T2');
        $download = $this->get($this->downloadUrl())->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $download->getContent());
        $this->supervisor->update(['name' => 'Nama Diubah Setelah Finalisasi']);
        $this->assertSame('Dr. Ryan Randy Suryono, M.Kom.', $letter->fresh()->filter_snapshot['supervisor_name']);
    }

    public function test_a_draft_stage_and_another_supervisors_letter_cannot_be_downloaded(): void
    {
        $this->get($this->downloadUrl())->assertNotFound();
        $this->post(route('lpm.assignments.finalize_stage', $this->stage));
        $unassigned = Lecturer::create(['name' => 'Tanpa Penugasan', 'external_code' => 'LETTER-UNASSIGNED']);
        $this->get(route('lpm.supervisors.assignment_letter', [$unassigned, $this->stage]))->assertNotFound();
        $this->get(route('lpm.supervisors.assignment_letter', [$this->supervisor, $this->secondStage]))->assertOk();
        $supervisorUser = User::factory()->create(['lecturer_id' => $this->supervisor->id]);
        $supervisorUser->assignRole('supervisor');
        $this->withSession(['password_hash_web' => null])->actingAs($supervisorUser)->get($this->downloadUrl())->assertForbidden();
    }

    public function test_finalization_is_idempotent_and_reopening_invalidates_previous_letters(): void
    {
        $this->post(route('lpm.assignments.finalize_stage', $this->stage));
        $oldLetter = $this->letters()->first();
        $this->post(route('lpm.assignments.finalize_stage', $this->stage));
        $this->assertCount(2, $this->letters());
        $this->post(route('lpm.assignments.reopen_stage', $this->stage))->assertSessionHas('info');
        $this->assertSame('STALE', $oldLetter->fresh()->status);
        $this->get($this->downloadUrl())->assertNotFound();
        $this->get(route('lpm.supervisors.index', ['period_id' => $this->period->id]))->assertDontSee('Surat Tugas T1');
        $this->post(route('lpm.assignments.finalize_stage', $this->stage))->assertSessionHas('success');
        $newLetters = $this->letters()->where('status', 'READY');
        $this->assertCount(2, $newLetters);
        $this->assertSame('001/LPM-UTI/X/2030/T1', $newLetters->first()->document_number);
        $this->get($this->downloadUrl())->assertOk();
    }

    public function test_the_second_stage_has_its_own_letter_and_other_periods_do_not_show_it(): void
    {
        $this->post(route('lpm.assignments.finalize_stage', $this->stage));
        $this->post(route('lpm.assignments.finalize_stage', $this->secondStage))->assertSessionHas('success');
        $letter = GeneratedDocument::where('supervision_stage_id', $this->secondStage->id)->firstOrFail();
        $this->assertSame(2, $letter->filter_snapshot['stage_no']);
        $this->assertSame('31 Desember 2030', $letter->filter_snapshot['ends_on']);
        $this->assertSame('001/LPM-UTI/X/2030/T2', $letter->document_number);
        $anotherPeriod = SupervisionPeriod::create([
            'academic_term_id' => $this->period->academic_term_id, 'name' => 'Periode Lain',
            'starts_on' => $this->period->starts_on, 'ends_on' => $this->period->ends_on,
            'status' => 'CLOSED', 'created_by' => $this->admin->id,
        ]);
        $this->get(route('lpm.supervisors.index', ['period_id' => $anotherPeriod->id]))
            ->assertOk()->assertDontSee('Surat Tugas T1')->assertDontSee('Surat Tugas T2');
    }

    public function test_repeated_finalization_keeps_letter_numbers_unique_and_preserves_history(): void
    {
        for ($iteration = 0; $iteration < 5; $iteration++) {
            if ($iteration > 0) {
                $this->travel(61)->seconds();
            }
            $this->post(route('lpm.assignments.finalize_stage', $this->stage))->assertSessionHas('success');
            $currentLetters = GeneratedDocument::where('supervision_period_id', $this->period->id)
                ->where('status', 'READY')->orderBy('id')->get();
            $this->assertCount(4, $currentLetters);
            foreach ($currentLetters as $letter) {
                $ordinal = $letter->supervisor_lecturer_id === $this->supervisor->id ? '001' : '002';
                $this->assertStringStartsWith($ordinal.'/LPM-UTI/X/2030', $letter->document_number);
                $this->assertSame($letter->document_number, $letter->filter_snapshot['number']);
            }

            $this->post(route('lpm.assignments.finalize_stage', $this->secondStage))->assertSessionHas('success');
            $this->assertSame(($iteration + 1) * 4, GeneratedDocument::where('supervision_period_id', $this->period->id)->count());
            if ($iteration < 4) {
                $this->post(route('lpm.assignments.reopen_stage', $this->stage))->assertSessionHas('info');
                foreach ($currentLetters as $letter) {
                    $archivedLetter = $letter->fresh();
                    $this->assertSame('STALE', $archivedLetter->status);
                    $this->assertSame($letter->document_number, $archivedLetter->document_number);
                    $this->assertSame($letter->filter_snapshot, $archivedLetter->filter_snapshot);
                }
            }
        }

        $numbers = GeneratedDocument::where('supervision_period_id', $this->period->id)->pluck('document_number');
        $this->assertCount(20, $numbers);
        $this->assertCount(20, $numbers->unique());
        $secondStageLetter = GeneratedDocument::where('supervision_stage_id', $this->secondStage->id)
            ->where('supervisor_lecturer_id', $this->supervisor->id)->where('status', 'READY')->firstOrFail();
        $this->assertSame('001/LPM-UTI/X/2030/T2/P'.$this->period->id.'/R4', $secondStageLetter->document_number);

        $this->artisan('supervisors:issue-assignment-letters', ['--stage' => $this->secondStage->id, '--refresh' => true])
            ->expectsOutput('2 surat tugas berhasil diperbarui.')->assertSuccessful();
        $this->assertSame($secondStageLetter->document_number, $secondStageLetter->fresh()->document_number);
        $this->get(route('lpm.supervisors.assignment_letter', [$this->supervisor, $this->secondStage]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_letter_validation_failure_rolls_back_finalization_and_number_allocation(): void
    {
        PeriodSupervisor::where('supervision_period_id', $this->period->id)
            ->where('lecturer_id', $this->otherSupervisor->id)->delete();
        $this->post(route('lpm.assignments.finalize_stage', $this->stage))->assertSessionHas('error');
        $this->assertSame('DRAFT', $this->stage->fresh()->status);
        $this->assertCount(0, $this->letters());
        $this->assertSame(0, DB::table('assignment_letter_sequences')->where('year', 2030)->count());
        $this->assertSame(['DRAFT'], $this->stage->assignments()->pluck('status')->unique()->values()->all());
    }

    public function test_finalization_registers_both_stage_letters_without_rendering_pdfs(): void
    {
        $this->partialMock(SupervisorAssignmentLetterService::class)->shouldNotReceive('render');
        $this->post(route('lpm.assignments.finalize_stage', $this->stage))->assertSessionHas('success');
        $this->assertSame('FINALIZED', $this->stage->fresh()->status);
        $this->assertSame('FINALIZED', $this->secondStage->fresh()->status);
        $this->assertSame(4, GeneratedDocument::where('supervision_period_id', $this->period->id)->where('status', 'READY')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_download_renders_the_registered_contents_with_the_current_jakarta_date(): void
    {
        $this->post(route('lpm.assignments.finalize_stage', $this->stage))->assertSessionHas('success');
        $document = $this->letters()->firstWhere('supervisor_lecturer_id', $this->supervisor->id);
        $snapshot = $document->filter_snapshot;
        $this->travelTo(now()->addDays(3));
        $service = app(SupervisorAssignmentLetterService::class);
        $pdf = $service->render([...$snapshot, 'issued_on' => '18 Oktober 2030']);
        $this->partialMock(SupervisorAssignmentLetterService::class)->shouldReceive('render')->once()
            ->withArgs(fn (array $letter): bool => $letter === [...$snapshot, 'issued_on' => '18 Oktober 2030'])
            ->andReturn($pdf);
        $this->get($this->downloadUrl())->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($snapshot, $document->fresh()->filter_snapshot);
    }

    public function test_letter_numbers_restart_each_year_using_the_jakarta_finalization_date(): void
    {
        $this->post(route('lpm.assignments.finalize_stage', $this->stage))->assertSessionHas('success');
        $this->post(route('lpm.assignments.reopen_stage', $this->stage))->assertSessionHas('info');
        $this->travelTo(now()->setTimezone('UTC')->setDate(2030, 12, 31)->setTime(18, 30));
        $this->post(route('lpm.assignments.finalize_stage', $this->stage))->assertSessionHas('success');
        $letter = $this->letters()->where('status', 'READY')->first();
        $this->assertSame('001/LPM-UTI/I/2031', $letter->document_number);
        $this->assertSame('1 Januari 2031', $letter->filter_snapshot['issued_on']);
    }

    public function test_incomplete_assignments_do_not_issue_letters(): void
    {
        $this->stage->assignments()->firstOrFail()->update(['status' => 'CANCELLED']);
        $this->post(route('lpm.assignments.finalize_stage', $this->stage))->assertSessionHas('error');
        $this->assertCount(0, $this->letters());
        $this->assertSame('DRAFT', $this->stage->fresh()->status);
    }

    public function test_existing_finalized_stages_can_receive_missing_letters_without_duplicate_numbers(): void
    {
        $this->stage->assignments()->update(['status' => 'FINAL']);
        $this->stage->update(['status' => 'FINALIZED', 'finalized_at' => now(), 'finalized_by' => $this->admin->id]);
        $this->travelTo(now()->addDays(3));
        $this->artisan('supervisors:issue-assignment-letters', ['--stage' => $this->stage->id])
            ->expectsOutput('2 surat tugas berhasil dibuat.')->assertSuccessful();
        $this->assertCount(2, $this->letters());
        $this->assertSame('15 Oktober 2030', $this->letters()->first()->filter_snapshot['issued_on']);
        $this->artisan('supervisors:issue-assignment-letters', ['--stage' => $this->stage->id])
            ->expectsOutput('0 surat tugas berhasil dibuat.')->assertSuccessful();
        $this->assertCount(2, $this->letters());
        $this->assertSame(['001/LPM-UTI/X/2030', '002/LPM-UTI/X/2030'], $this->letters()->pluck('document_number')->all());
        $this->get($this->downloadUrl())->assertOk();
    }

    public function test_reference_layout_with_six_lecturers_fits_one_page_and_has_blank_signature_space(): void
    {
        $letter = [
            'number' => '072/LPM-UTI/X/2025', 'supervisor_name' => 'Dr. Ryan Randy Suryono, M.Kom', 'stage_no' => 1,
            'semester' => 'Ganjil', 'academic_year' => '2025/2026', 'starts_on' => '20 Oktober 2025',
            'ends_on' => '13 Desember 2025', 'issued_on' => '15 Oktober 2025', 'chair_name' => 'Dr. Laila Ulsi Qodriani, M.A.',
            'groups' => [['label' => 'Offline', 'lecturers' => ['Rahmat Dedi Gunawan, M.Kom', 'Qadhli Jafar Adrian, Bmm., MIT.', 'Sigit Doni Ramdan, S.T., M.Eng.', 'Dr. Aditya Gumantan', 'Triyanto, M.Pd.', 'Dr. Afrianto, M.Pd.']]],
        ];
        $pdf = app(SupervisorAssignmentLetterService::class)->render($letter);
        $contents = $pdf->output();
        $this->assertSame(1, $pdf->getDomPDF()->getCanvas()->get_page_count());
        $html = view('pdf.supervisor-assignment-letter', ['letter' => $letter, 'letterhead' => 'letterhead.jpeg'])->render();
        $this->assertStringContainsString('paling lambat pada 13 Desember 2025', $html);
        $this->assertStringContainsString('<div class="signature-space"></div>', $html);
        $this->assertSame(1, substr_count($html, '<img '));
        file_put_contents(storage_path('framework/testing/supervisor-letter-preview.pdf'), $contents);
    }

    public function test_supervisor_can_list_and_download_only_their_issued_stage_letters(): void
    {
        $this->post(route('lpm.assignments.finalize_stage', $this->stage))->assertSessionHas('success');
        $ownLetters = GeneratedDocument::where('supervisor_lecturer_id', $this->supervisor->id)->orderBy('id')->get();
        $otherLetter = GeneratedDocument::where('supervisor_lecturer_id', $this->otherSupervisor->id)->firstOrFail();
        $user = $this->assignmentLetterUser();
        $this->withSession(['password_hash_web' => null])->actingAs($user);

        $this->get(route('supervisor.assignment_letters.index'))->assertOk()
            ->assertSee('Surat Tugas Saya')->assertSee('Surat Tugas Tahap 1')->assertSee('Surat Tugas Tahap 2')
            ->assertViewHas('letters', fn ($letters): bool => $letters->total() === 2
                && $letters->pluck('id')->sort()->values()->all() === $ownLetters->modelKeys())
            ->assertDontSee($otherLetter->document_number);
        foreach ($ownLetters as $letter) {
            $response = $this->get(route('supervisor.assignment_letters.download', $letter->id))
                ->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->get(route('supervisor.assignment_letters.download', $otherLetter->id))->assertNotFound();
        $this->get(route('supervisor.assignment_letters.download', 999999999))->assertNotFound();
        $this->get(route('supervisor.assignment_letters.index', ['period_id' => $this->period->id]))
            ->assertOk()->assertViewHas('letters', fn ($letters): bool => $letters->total() === 2);
        $this->get(route('supervisor.assignment_letters.index', ['period_id' => 999999999]))->assertNotFound();
        $this->assertSame(4, GeneratedDocument::count());
    }

    #[TestWith(['revoked_membership'])]
    #[TestWith(['stale_document'])]
    #[TestWith(['draft_stage'])]
    #[TestWith(['cancelled_assignments'])]
    #[TestWith(['wrong_document_type'])]
    #[TestWith(['wrong_document_period'])]
    public function test_supervisor_cannot_access_unavailable_assignment_letters(string $case): void
    {
        $this->post(route('lpm.assignments.finalize_stage', $this->stage))->assertSessionHas('success');
        $letter = $this->letters()->firstWhere('supervisor_lecturer_id', $this->supervisor->id);
        if ($case === 'revoked_membership') {
            PeriodSupervisor::where('supervision_period_id', $this->period->id)
                ->where('lecturer_id', $this->supervisor->id)->update(['is_active' => false]);
        } elseif ($case === 'stale_document') {
            $letter->update(['status' => 'STALE']);
        } elseif ($case === 'draft_stage') {
            $this->stage->refresh()->update(['status' => 'DRAFT']);
        } elseif ($case === 'cancelled_assignments') {
            $this->stage->assignments()->where('supervisor_lecturer_id', $this->supervisor->id)->update(['status' => 'CANCELLED']);
        } elseif ($case === 'wrong_document_type') {
            $letter->update(['document_type' => 'SCHEDULE_PDF']);
        } elseif ($case === 'wrong_document_period') {
            $period = SupervisionPeriod::create([
                'academic_term_id' => $this->period->academic_term_id, 'name' => 'Periode Lain',
                'starts_on' => $this->period->starts_on, 'ends_on' => $this->period->ends_on,
                'status' => 'ACTIVE', 'created_by' => $this->admin->id,
            ]);
            PeriodSupervisor::create(['supervision_period_id' => $period->id, 'lecturer_id' => $this->supervisor->id, 'is_active' => true, 'selected_by' => $this->admin->id]);
            $letter->update(['supervision_period_id' => $period->id]);
        }
        $this->withSession(['password_hash_web' => null])->actingAs($this->assignmentLetterUser());
        $this->get(route('supervisor.assignment_letters.download', $letter->id))->assertNotFound();
        $this->get(route('supervisor.assignment_letters.index'))->assertOk()
            ->assertViewHas('letters', fn ($letters): bool => ! $letters->contains('id', $letter->id));
    }

    public function test_reopening_removes_letters_from_the_supervisor_menu(): void
    {
        $this->post(route('lpm.assignments.finalize_stage', $this->stage))->assertSessionHas('success');
        $letter = $this->letters()->firstWhere('supervisor_lecturer_id', $this->supervisor->id);
        $this->post(route('lpm.assignments.reopen_stage', $this->stage))->assertSessionHas('info');
        $this->withSession(['password_hash_web' => null])->actingAs($this->assignmentLetterUser());
        $this->get(route('supervisor.assignment_letters.index'))->assertOk()
            ->assertSee('Belum ada surat tugas yang dapat diunduh');
        $this->get(route('supervisor.assignment_letters.download', $letter->id))->assertNotFound();
    }

    public function test_assignment_letter_menu_requires_a_supervisor_with_an_active_account_and_lecturer(): void
    {
        $this->get(route('supervisor.assignment_letters.index'))->assertForbidden();
        auth()->logout();
        $this->get(route('supervisor.assignment_letters.index'))->assertRedirect(route('login'));
        $this->get(route('supervisor.assignment_letters.download', 1))->assertRedirect(route('login'));
        $user = $this->assignmentLetterUser();
        $this->supervisor->update(['is_active' => false]);
        $this->withSession(['password_hash_web' => null])->actingAs($user);
        $this->get(route('supervisor.assignment_letters.index'))->assertForbidden();
        $this->get(route('supervisor.assignment_letters.download', 1))->assertForbidden();
        $this->supervisor->update(['is_active' => true]);
        $user->update(['is_active' => false]);
        $this->get(route('supervisor.assignment_letters.index'))->assertRedirect(route('login'));
    }

    private function assignmentLetterUser(): User
    {
        $user = User::factory()->create(['lecturer_id' => $this->supervisor->id, 'is_active' => true, 'must_change_password' => false]);
        $user->assignRole('supervisor');

        return $user;
    }

    protected function createAssignment(string $name, string $mode, Lecturer $supervisor, string $status = 'DRAFT'): void
    {
        $lecturer = Lecturer::create(['name' => $name, 'external_code' => 'LETTER-TARGET-'.str()->uuid(), 'is_active' => true]);
        if ($status !== 'CANCELLED') {
            PeriodTarget::create(['supervision_period_id' => $this->period->id, 'lecturer_id' => $lecturer->id, 'scope_status' => 'INCLUDED', 'selected_by' => $this->admin->id]);
        }
        $course = Course::create(['name' => 'Mata Kuliah Surat Tugas']);
        $schedule = TeachingSchedule::create([
            'academic_term_id' => $this->period->academic_term_id, 'lecturer_id' => $lecturer->id,
            'course_id' => $course->id, 'class_name' => 'IF 30 A', 'delivery_mode' => $mode,
            'day_of_week' => 3, 'starts_local' => '09:00', 'ends_local' => '10:40', 'is_active' => true,
        ]);
        foreach ([[$this->stage, '2030-10-16'], [$this->secondStage, '2030-11-06']] as [$stage, $date]) {
            $occurrence = TeachingOccurrence::create([
                'teaching_schedule_id' => $schedule->id, 'original_date' => $date, 'occurs_on' => $date,
                'starts_at' => $date.' 02:00:00', 'ends_at' => $date.' 03:40:00', 'status' => 'SCHEDULED',
            ]);
            SupervisionAssignment::create([
                'supervision_stage_id' => $stage->id, 'supervisor_lecturer_id' => $supervisor->id,
                'target_lecturer_id' => $lecturer->id, 'teaching_occurrence_id' => $occurrence->id,
                'assignment_type' => 'MANUAL', 'status' => $status,
            ]);
        }
    }

    protected function letters(): Collection
    {
        return GeneratedDocument::where('supervision_stage_id', $this->stage->id)->orderBy('id')->get();
    }

    protected function downloadUrl(): string
    {
        return route('lpm.supervisors.assignment_letter', [$this->supervisor, $this->stage]);
    }
}
