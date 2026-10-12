<?php

namespace App\Console\Commands;

use App\Models\Faculty;
use App\Models\Lecturer;
use App\Models\ScheduleImportRow;
use App\Models\StudyProgram;
use App\Models\TeachingOccurrence;
use App\Models\TeachingSchedule;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

#[Signature('lecturers:sync-uti-roster {file : JSON hasil pencocokan daftar dosen dan NIDN} {--dry-run : Periksa data tanpa menyimpan perubahan}')]
#[Description('Sinkronkan dosen UTI, NIDN, program studi, dan bersihkan hasil impor jadwal yang bukan nama dosen')]
class SyncUtiLecturers extends Command
{
    /** @var array<string, string> */
    private const PROGRAM_FACULTIES = [
        'MIK' => 'FTIK', 'IF' => 'FTIK', 'SI' => 'FTIK', 'TE' => 'FTIK',
        'TK' => 'FTIK', 'TS' => 'FTIK', 'TI' => 'FTIK',
        'PM' => 'FSIP', 'PO' => 'FSIP', 'PI' => 'FSIP', 'MBI' => 'FSIP', 'SS' => 'FSIP',
        'AK' => 'FEB', 'MN' => 'FEB',
    ];

    /** @var array<string, string> */
    private const ADDITIONAL_PROGRAMS = [
        'MIK' => 'Magister Ilmu Komputer',
    ];

    /** @var list<string> */
    private const INVALID_LECTURER_NAMES = [
        'Dosen Pengampu', 'Senin', '---', '-x-',
        'Jadwal dihasilkan dengan FET 5.35.2 pada 03/10/26 08.06',
        'LAB GAMBAR (50)', 'LAB LAB GAMBAR (50)',
    ];

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("Berkas tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        try {
            $rows = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $this->validateRows($rows);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info(count($rows).' dosen siap disinkronkan; '.collect($rows)->whereNotNull('nidn')->count().' memiliki NIDN dari Excel.');

            return self::SUCCESS;
        }

        $backupPath = 'lecturer-sync-backups/'.now()->format('Ymd_His_u').'_lecturers.json';
        $saved = Storage::disk('local')->put($backupPath, json_encode([
            'lecturers' => Lecturer::all()->toArray(),
            'teaching_schedules' => TeachingSchedule::all()->toArray(),
            'occurrences' => TeachingOccurrence::all()->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (! $saved) {
            $this->error('Cadangan tidak berhasil disimpan; sinkronisasi dibatalkan.');

            return self::FAILURE;
        }

        $updated = 0;
        $created = 0;
        DB::transaction(function () use ($rows, &$updated, &$created): void {
            $faculty = Faculty::updateOrCreate(['code' => 'FTIK'], [
                'name' => 'Fakultas Teknik dan Ilmu Komputer',
                'is_active' => true,
            ]);
            foreach (self::ADDITIONAL_PROGRAMS as $code => $name) {
                if (collect($rows)->contains('program_code', $code)) {
                    StudyProgram::updateOrCreate(['faculty_id' => $faculty->id, 'code' => $code], [
                        'name' => $name,
                        'is_active' => true,
                    ]);
                }
            }
            $programs = StudyProgram::query()
                ->where('is_active', true)
                ->whereHas('faculty', fn ($query) => $query->where('is_active', true))
                ->with('faculty')
                ->get()
                ->keyBy('code');

            foreach ($rows as $row) {
                $lecturer = $this->resolveLecturer($row);
                $attributes = [];
                $identitySources = $lecturer?->identity_sources ?? [];
                if (isset($row['pdf_name']) && ($identitySources['name']['source'] ?? null) !== 'manual') {
                    $attributes['name'] = trim($row['pdf_name']);
                    $attributes['normalized_name'] = mb_strtolower($attributes['name']);
                    $identitySources['name'] = [
                        'source' => 'schedule_pdf',
                        'value' => $attributes['name'],
                        'raw_value' => $row['pdf_source']['name'] ?? $row['pdf_name'],
                        'import_id' => $row['pdf_source']['import_id'] ?? null,
                        'page' => $row['pdf_source']['page'] ?? null,
                    ];
                }
                if ((isset($row['excel_source']) || isset($row['excel_sources']))
                    && ($identitySources['nidn']['source'] ?? null) !== 'manual') {
                    unset($identitySources['metadata']);
                    $identitySources['nidn'] = array_replace($row['excel_source'] ?? [], [
                        'source' => 'excel',
                        'value' => array_key_exists('nidn', $row) ? $row['nidn'] : $lecturer?->nidn,
                        'name_used_for_matching' => $row['excel_name'] ?? null,
                        'matching_rows' => collect($row['excel_sources'] ?? [])->map(fn (array $source): array => [
                            'sheet' => $source['sheet'] ?? null,
                            'row' => $source['row'] ?? null,
                            'name' => $source['name'] ?? null,
                            'nidn' => $source['nidn'] ?? null,
                        ])->all(),
                    ]);
                }
                if (isset($row['affiliation_source'])
                    && ($identitySources['affiliation']['source'] ?? null) !== 'manual') {
                    unset($identitySources['metadata']);
                    $identitySources['affiliation'] = array_replace($row['affiliation_source'], [
                        'source' => $row['affiliation_source']['source'] ?? 'user_roster',
                        'program_code' => $row['program_code'] ?? null,
                        'faculty_code' => $row['faculty_code'] ?? null,
                    ]);
                }
                $attributes['identity_sources'] = $identitySources;
                if (array_key_exists('program_code', $row) && ($identitySources['affiliation']['source'] ?? null) !== 'manual') {
                    $program = $row['program_code'] ? $programs->get($row['program_code']) : null;
                    if ($row['program_code'] && (! $program || $program->faculty?->code !== self::PROGRAM_FACULTIES[$row['program_code']])) {
                        throw new InvalidArgumentException('Program studi tidak ditemukan atau salah fakultas: '.$row['program_code']);
                    }
                    $attributes['faculty_id'] = $program?->faculty_id;
                    $attributes['study_program_id'] = $program?->id;
                }
                if (array_key_exists('nidn', $row) && ($identitySources['nidn']['source'] ?? null) !== 'manual') {
                    $attributes['external_code'] = $row['nidn'];
                }
                if (! $lecturer) {
                    Lecturer::create($attributes + ['is_active' => true]);
                    $created++;

                    continue;
                }

                $lecturer->update($attributes);
                if (isset($row['pdf_name'])) {
                    TeachingOccurrence::whereHas('schedule', fn ($query) => $query->where('lecturer_id', $lecturer->id))
                        ->whereDoesntHave('assignments')
                        ->update(['identity_snapshot->lecturer_name' => $lecturer->name]);
                }
                $updated++;
            }

            $invalidIds = Lecturer::whereIn('name', self::INVALID_LECTURER_NAMES)->pluck('id');
            if ($invalidIds->isNotEmpty()) {
                Lecturer::whereIn('id', $invalidIds)->update(['is_active' => false]);
                TeachingSchedule::whereIn('lecturer_id', $invalidIds)->update(['is_active' => false]);
                ScheduleImportRow::whereIn('matched_lecturer_id', $invalidIds)->where('validation_status', '<>', 'SKIPPED')->update([
                    'validation_status' => 'INVALID',
                    'is_approved' => false,
                ]);
            }

            Lecturer::where('external_code', 'like', 'DSN-%')->update(['external_code' => null]);
        });

        $this->info("{$created} dosen ditambahkan, {$updated} dosen diperbarui.");
        $this->info('Cadangan sebelum sinkronisasi: storage/app/private/'.$backupPath);

        return self::SUCCESS;
    }

    private function validateRows(mixed $rows): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new InvalidArgumentException('Berkas JSON harus berisi daftar dosen.');
        }

        $nidns = [];
        $ids = [];
        foreach ($rows as $row) {
            if (! is_array($row) || (isset($row['program_code']) && ! isset(self::PROGRAM_FACULTIES[$row['program_code']]))) {
                throw new InvalidArgumentException('Ada data dosen atau program studi yang tidak valid.');
            }
            if (isset($row['pdf_name']) && (! is_string($row['pdf_name']) || trim($row['pdf_name']) === '')) {
                throw new InvalidArgumentException('Nama dan gelar dosen dari PDF tidak valid.');
            }
            if (array_key_exists('program_code', $row) && ! is_array($row['affiliation_source'] ?? null)) {
                throw new InvalidArgumentException('Program studi dan fakultas harus disertai sumber dari daftar dosen; Excel hanya untuk NIDN.');
            }
            if (isset($row['faculty_code'], $row['program_code']) && $row['faculty_code'] !== self::PROGRAM_FACULTIES[$row['program_code']]) {
                throw new InvalidArgumentException('Fakultas daftar dosen bertentangan dengan program studi: '.$row['program_code']);
            }
            if (isset($row['nidn']) && ! preg_match('/^\d{10}$/', (string) $row['nidn'])) {
                throw new InvalidArgumentException('NIDN harus terdiri dari 10 angka: '.($row['pdf_name'] ?? $row['excel_name'] ?? $row['existing_id'] ?? ''));
            }
            if (isset($row['nidn'])) {
                if (isset($nidns[$row['nidn']])) {
                    throw new InvalidArgumentException('NIDN ganda: '.$row['nidn']);
                }
                $nidns[$row['nidn']] = true;
            }
            if (isset($row['existing_id'])) {
                if (isset($ids[$row['existing_id']])) {
                    throw new InvalidArgumentException('ID dosen ganda: '.$row['existing_id']);
                }
                $ids[$row['existing_id']] = true;
            }
            $lecturer = $this->resolveLecturer($row);
            if (! $lecturer && ! isset($row['pdf_name'])) {
                throw new InvalidArgumentException('Dosen baru memerlukan nama dan gelar dari PDF; nama Excel hanya dipakai untuk pencocokan identitas.');
            }
            if ($lecturer && isset($row['nidn'])) {
                if (($lecturer->nidn && $lecturer->nidn !== $row['nidn'])
                    || Lecturer::where('external_code', $row['nidn'])->where('id', '<>', $lecturer->id)->exists()) {
                    throw new InvalidArgumentException('NIDN bertentangan dengan identitas dosen terdaftar: '.$row['nidn']);
                }
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function resolveLecturer(array $row): ?Lecturer
    {
        if (isset($row['existing_id'])) {
            return Lecturer::findOrFail($row['existing_id']);
        }
        if (isset($row['nidn'])) {
            $lecturer = Lecturer::where('external_code', $row['nidn'])->first();
            if ($lecturer) {
                return $lecturer;
            }
        }

        return isset($row['pdf_name']) ? Lecturer::where('name', trim($row['pdf_name']))->first() : null;
    }
}
