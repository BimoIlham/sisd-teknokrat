<?php

namespace Tests\Feature;

use App\Models\Faculty;
use App\Models\Lecturer;
use App\Models\StudyProgram;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LecturerRosterSyncTest extends TestCase
{
    use DatabaseTransactions;

    public function test_roster_sync_populates_identity_and_keeps_existing_lecturer_id(): void
    {
        Storage::fake('local');
        $faculty = Faculty::firstOrCreate(['code' => 'FTIK'], ['name' => 'Fakultas Teknik dan Ilmu Komputer']);
        $program = StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id, 'code' => 'IF'],
            ['name' => 'Informatika']
        );
        $lecturer = Lecturer::create([
            'name' => 'Dosen Uji Sinkronisasi',
            'external_code' => 'DSN-UJI-SINKRON',
            'is_active' => true,
        ]);
        $unmatched = Lecturer::create([
            'name' => 'Dosen Uji Belum Punya NIDN',
            'external_code' => 'DSN-UNMATCHED-TEST',
            'is_active' => true,
        ]);
        $path = tempnam(sys_get_temp_dir(), 'uti-roster-');
        file_put_contents($path, json_encode([
            [
                'pdf_name' => 'Dosen Uji Sinkronisasi, S.Kom., M.Kom.',
                'excel_name' => 'Dosen Uji Sinkronisassi',
                'name' => 'Nama Excel Yang Tidak Boleh Menimpa PDF',
                'program_code' => $program->code,
                'affiliation_source' => ['name_used_for_matching' => 'Dosen Uji Sinkronisasi'],
                'nidn' => '0123456789',
                'existing_id' => $lecturer->id,
            ],
            [
                'pdf_name' => 'Dosen Baru Uji Sinkronisasi',
                'program_code' => 'MIK',
                'affiliation_source' => ['name_used_for_matching' => 'Dosen Baru Uji Sinkronisasi'],
                'nidn' => null,
                'existing_id' => null,
            ],
        ]));

        try {
            $this->artisan('lecturers:sync-uti-roster', ['file' => $path])->assertSuccessful();
            $this->artisan('lecturers:sync-uti-roster', ['file' => $path])->assertSuccessful();

            $lecturer->refresh();
            $this->assertSame('Dosen Uji Sinkronisasi, S.Kom., M.Kom.', $lecturer->name);
            $this->assertSame('dosen uji sinkronisasi, s.kom., m.kom.', $lecturer->normalized_name);
            $this->assertSame('schedule_pdf', $lecturer->identity_sources['name']['source']);
            $this->assertSame('0123456789', $lecturer->nidn);
            $this->assertSame($faculty->id, $lecturer->faculty_id);
            $this->assertSame($program->id, $lecturer->study_program_id);
            $this->assertSame(1, Lecturer::where('name', 'Dosen Baru Uji Sinkronisasi')->count());
            $this->assertSame('MIK', Lecturer::where('name', 'Dosen Baru Uji Sinkronisasi')->firstOrFail()->studyProgram->code);
            $this->assertNull($unmatched->fresh()->external_code);
        } finally {
            unlink($path);
        }
    }

    public function test_roster_affiliation_and_excel_nidn_preserve_pdf_name_and_titles(): void
    {
        Storage::fake('local');
        $lecturer = Lecturer::create([
            'name' => 'Reflan Nuari Uji, S.Kom., M.Kom.',
            'identity_sources' => ['name' => ['source' => 'schedule_pdf', 'value' => 'Reflan Nuari Uji, S.Kom., M.Kom.']],
            'is_active' => true,
        ]);
        $path = tempnam(sys_get_temp_dir(), 'uti-metadata-');
        file_put_contents($path, json_encode([[
            'existing_id' => $lecturer->id,
            'name' => 'Reflan Nauri Uji',
            'excel_name' => 'Reflan Nauri Uji',
            'excel_source' => ['sheet' => 3, 'row' => 7],
            'excel_sources' => [
                ['sheet' => 2, 'row' => 23, 'name' => 'Reflan Nauri Uji', 'nidn' => '0123456798', 'program_code' => 'SI', 'faculty_code' => 'FSIP'],
                ['sheet' => 2, 'row' => 85, 'name' => 'Reflan Nauri Uji', 'nidn' => '0123456798', 'program_code' => 'SIA', 'faculty_code' => 'FTIK'],
            ],
            'nidn' => '0123456798',
            'affiliation_source' => ['name_used_for_matching' => 'Reflan Nauri Uji', 'line' => 18],
            'program_code' => 'MIK',
            'faculty_code' => 'FTIK',
        ]]));

        try {
            $this->artisan('lecturers:sync-uti-roster', ['file' => $path])->assertSuccessful();
            $lecturer->refresh();
            $this->assertSame('Reflan Nuari Uji, S.Kom., M.Kom.', $lecturer->name);
            $this->assertSame('0123456798', $lecturer->nidn);
            $this->assertSame('Magister Ilmu Komputer', $lecturer->studyProgram->name);
            $this->assertSame('FTIK', $lecturer->faculty->code);
            $this->assertSame('schedule_pdf', $lecturer->identity_sources['name']['source']);
            $this->assertSame('excel', $lecturer->identity_sources['nidn']['source']);
            $this->assertSame('0123456798', $lecturer->identity_sources['nidn']['value']);
            $this->assertCount(2, $lecturer->identity_sources['nidn']['matching_rows']);
            $this->assertSame('user_roster', $lecturer->identity_sources['affiliation']['source']);
            $this->assertSame('MIK', $lecturer->identity_sources['affiliation']['program_code']);
            $this->assertArrayNotHasKey('metadata', $lecturer->identity_sources);
        } finally {
            unlink($path);
        }
    }

    public function test_excel_name_cannot_create_a_lecturer_without_a_pdf_name(): void
    {
        Storage::fake('local');
        $path = tempnam(sys_get_temp_dir(), 'uti-excel-only-');
        file_put_contents($path, json_encode([[
            'name' => 'Nama Excel Uji Tanpa PDF',
            'excel_name' => 'Nama Excel Uji Tanpa PDF',
            'nidn' => '0123456797',
            'program_code' => 'IF',
            'affiliation_source' => ['name_used_for_matching' => 'Nama Excel Uji Tanpa PDF'],
        ]]));

        try {
            $this->artisan('lecturers:sync-uti-roster', ['file' => $path])->assertFailed();
            $this->assertFalse(Lecturer::where('external_code', '0123456797')->exists());
        } finally {
            unlink($path);
        }
    }

    public function test_conflicting_nidn_is_rejected_before_any_profile_is_changed(): void
    {
        Storage::fake('local');
        $first = Lecturer::create(['name' => 'Dosen Pertama Uji NIDN', 'external_code' => '0123456796']);
        $second = Lecturer::create(['name' => 'Dosen Kedua Uji NIDN', 'external_code' => '0123456795']);
        $path = tempnam(sys_get_temp_dir(), 'uti-nidn-conflict-');
        file_put_contents($path, json_encode([[
            'existing_id' => $first->id,
            'pdf_name' => 'Dosen Pertama Uji NIDN, M.Kom.',
            'nidn' => $second->external_code,
            'program_code' => 'IF',
            'affiliation_source' => ['name_used_for_matching' => 'Dosen Pertama Uji NIDN'],
        ]]));

        try {
            $this->artisan('lecturers:sync-uti-roster', ['file' => $path])->assertFailed();
            $this->assertSame('Dosen Pertama Uji NIDN', $first->fresh()->name);
            $this->assertSame('0123456796', $first->fresh()->nidn);
            $this->assertSame('0123456795', $second->fresh()->nidn);
        } finally {
            unlink($path);
        }
    }

    public function test_excel_cannot_supply_affiliation_without_roster_source(): void
    {
        Storage::fake('local');
        $lecturer = Lecturer::create(['name' => 'Dosen Uji Sumber Afiliasi, M.Kom.']);
        $path = tempnam(sys_get_temp_dir(), 'uti-source-');
        file_put_contents($path, json_encode([[
            'existing_id' => $lecturer->id,
            'excel_source' => ['sheet' => 2, 'row' => 43],
            'program_code' => 'IF',
            'faculty_code' => 'FTIK',
        ]]));

        try {
            $this->artisan('lecturers:sync-uti-roster', ['file' => $path])->assertFailed();
            $this->assertNull($lecturer->fresh()->study_program_id);
            $this->assertNull($lecturer->fresh()->faculty_id);
        } finally {
            unlink($path);
        }
    }
}
