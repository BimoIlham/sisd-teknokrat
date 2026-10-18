<?php

namespace Tests\Feature;

use App\Models\ScheduleImport;
use App\Services\ScheduleImport\ScheduleImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class ImportResourceProtectionTest extends TestCase
{
    use CreatesSchedulingFixtures, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchedulingFixture();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private function file(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('synthetic.pdf', '%PDF-1.7 resource fixture');
    }

    public function test_service_enforces_import_size_before_writing_files(): void
    {
        try {
            app(ScheduleImportService::class)->import(UploadedFile::fake()->create('large.pdf', 15361, 'application/pdf'), $this->period->academicTerm, $this->admin);
            $this->fail('Oversized PDF must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('schedule_pdf', $exception->errors());
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        Http::assertNothingSent();
    }

    public function test_committed_duplicate_does_not_write_an_unreferenced_file(): void
    {
        $file = $this->file();
        $existing = $this->createSchedulingImport();
        $existing->update(['sha256' => hash_file('sha256', $file->getRealPath()), 'status' => 'COMMITTED']);
        Storage::disk('local')->put($existing->stored_path, 'original valid import');
        $before = Storage::disk('local')->allFiles();
        $result = app(ScheduleImportService::class)->import($file, $this->period->academicTerm, $this->admin);
        $this->assertSame($existing->id, $result->id);
        $this->assertSame($before, Storage::disk('local')->allFiles());
        Http::assertNothingSent();
    }

    public function test_file_is_removed_when_import_registration_rolls_back(): void
    {
        Event::listen('eloquent.creating: '.ScheduleImport::class, fn () => throw new RuntimeException('Simulated database failure'));
        try {
            app(ScheduleImportService::class)->import($this->file(), $this->period->academicTerm, $this->admin);
            $this->fail('Registration should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated database failure', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ScheduleImport::class);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, ScheduleImport::count());
    }

    #[TestWith([413])]
    #[TestWith([422])]
    #[TestWith([503])]
    public function test_resource_rejection_does_not_run_cli_fallback(int $status): void
    {
        Http::fake(['*' => Http::response(['detail' => ['code' => 'RESOURCE_BUSY']], $status)]);
        try {
            app(ScheduleImportService::class)->import($this->file(), $this->period->academicTerm, $this->admin);
            $this->fail('Resource rejection must be propagated.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('schedule_pdf', $exception->errors());
        }
        $import = ScheduleImport::sole();
        $this->assertSame('FAILED', $import->status);
        Storage::disk('local')->assertExists($import->stored_path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        Http::assertSentCount(1);
    }

    public function test_empty_pdf_parse_fails_with_a_clear_message_instead_of_becoming_an_empty_review(): void
    {
        config(['services.python.binary' => base_path('python-service/missing-test-python')]);
        $message = 'PDF berhasil diterima, tetapi tidak ada baris jadwal yang terbaca. Pastikan berkas berisi tabel jadwal dengan teks yang dapat dibaca.';
        Http::fake(['*' => Http::response([
            'parser' => ['version' => '1.3.0'],
            'rows' => [],
            'page_count' => 1,
        ])]);

        try {
            app(ScheduleImportService::class)->import($this->file(), $this->period->academicTerm, $this->admin);
            $this->fail('A PDF with no parsed rows should produce an actionable error.');
        } catch (ValidationException $exception) {
            $this->assertSame($message, $exception->errors()['schedule_pdf'][0]);
        }

        $import = ScheduleImport::sole();
        $this->assertSame('FAILED', $import->status);
        $this->assertSame(0, $import->rows()->count());
        $this->assertSame($message, $import->error_message);
    }

    public function test_unavailable_parser_is_reported_instead_of_creating_an_empty_review(): void
    {
        config(['services.python.binary' => base_path('python-service/missing-test-python')]);
        Http::fake(['*' => fn () => throw new ConnectionException('Parser service unavailable')]);
        $message = 'Layanan pembaca PDF tidak dapat dihubungi dan parser lokal belum tersedia. Pastikan layanan Python aktif di server CyberPanel.';

        try {
            app(ScheduleImportService::class)->import($this->file(), $this->period->academicTerm, $this->admin);
            $this->fail('An unavailable parser should produce an actionable error.');
        } catch (ValidationException $exception) {
            $this->assertSame($message, $exception->errors()['schedule_pdf'][0]);
        }

        $import = ScheduleImport::sole();
        $this->assertSame('FAILED', $import->status);
        $this->assertSame($message, $import->error_message);
        $this->assertSame(0, $import->rows()->count());
    }
}
