<?php

namespace App\Services\ScheduleImport;

use App\Models\Faculty;
use App\Models\Lecturer;
use App\Models\ScheduleImport;
use App\Models\StudyProgram;
use App\Services\Scheduling\SchedulingLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ScheduleImportReferences
{
    public function rememberLecturer(Lecturer $lecturer): void
    {
        if (! Schema::hasTable('lecturer_identity_references')) {
            return;
        }
        $this->rememberProfile([
            'name' => $lecturer->name, 'external_code' => $lecturer->external_code,
            'faculty_code' => $lecturer->faculty_id ? Faculty::find($lecturer->faculty_id)?->code : null,
            'study_program_code' => $lecturer->study_program_id ? StudyProgram::find($lecturer->study_program_id)?->code : null,
            'is_active' => $lecturer->is_active,
            'aliases' => array_filter([$lecturer->name, $lecturer->identity_sources['name']['raw_value'] ?? null, $lecturer->identity_sources['name']['value'] ?? null]),
        ]);
    }

    /** @param array<string, mixed> $profile */
    public function rememberProfile(array $profile): void
    {
        $key = ! empty($profile['external_code']) ? 'code:'.$profile['external_code'] : 'name:'.hash('sha256', self::normalizeName($profile['name']));
        $existing = DB::table('lecturer_identity_references')->where('identity_key', $key)->first();
        $previous = $existing ? json_decode($existing->profile, true, 512, JSON_THROW_ON_ERROR) : [];
        $profile['aliases'] = array_values(array_unique(array_filter([
            ...($previous['aliases'] ?? []), ...($profile['aliases'] ?? []), $profile['name'],
        ])));
        DB::table('lecturer_identity_references')->updateOrInsert(['identity_key' => $key], [
            'name' => $profile['name'], 'normalized_name' => self::normalizeName($profile['name']),
            'profile' => json_encode($profile, JSON_THROW_ON_ERROR),
            'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
        ]);
    }

    public function restoreLecturer(string $name): ?Lecturer
    {
        $references = DB::table('lecturer_identity_references')->get()->map(fn ($reference): array => [
            'id' => $reference->id, 'profile' => json_decode($reference->profile, true, 512, JSON_THROW_ON_ERROR),
        ]);
        $matches = $references->filter(fn (array $reference): bool => collect($reference['profile']['aliases'])
            ->contains(fn (string $alias): bool => self::normalizeName($alias) === self::normalizeName($name)));
        if ($matches->isEmpty()) {
            $matches = $references->filter(fn (array $reference): bool => collect($reference['profile']['aliases'])
                ->contains(fn (string $alias): bool => self::normalizeBaseName($alias) === self::normalizeBaseName($name)));
        }
        if ($matches->count() !== 1 || ! ($matches->first()['profile']['is_active'] ?? false)) {
            return null;
        }
        $reference = $matches->first();

        return DB::transaction(function () use ($reference, $name): ?Lecturer {
            app(SchedulingLockService::class)->state();
            $profile = $reference['profile'];
            $existing = ! empty($profile['external_code'])
                ? Lecturer::where('external_code', $profile['external_code'])->first()
                : Lecturer::where('normalized_name', mb_strtolower($profile['name']))->first();
            if ($existing) {
                return $existing->is_active ? $existing : null;
            }
            $blocked = Lecturer::where('is_active', false)->get()->contains(fn (Lecturer $lecturer): bool => self::normalizeBaseName($lecturer->name) === self::normalizeBaseName($name));
            if ($blocked) {
                return null;
            }
            $faculty = ! empty($profile['faculty_code']) ? Faculty::where('code', $profile['faculty_code'])->where('is_active', true)->first() : null;
            $program = ! empty($profile['study_program_code']) ? StudyProgram::where('code', $profile['study_program_code'])->where('is_active', true)->first() : null;

            return Lecturer::create([
                'name' => $profile['name'], 'normalized_name' => mb_strtolower($profile['name']),
                'external_code' => $profile['external_code'] ?? null,
                'faculty_id' => $faculty?->id, 'study_program_id' => $program?->id,
                'is_active' => true, 'row_version' => 1,
                'identity_sources' => ['name' => ['source' => 'verified_reference', 'value' => $profile['name'], 'reference_id' => $reference['id']]],
            ]);
        }, 3);
    }

    public function rememberImport(ScheduleImport $import): void
    {
        if (! $import->isCommitted()) {
            return;
        }
        $rows = $import->rows()->get()->map(fn ($row): array => [
            'normalized' => $row->normalized_payload, 'raw' => $row->raw_payload,
            'source' => $row->source_locator ?? ['page' => $row->source_page],
            'confidence' => (float) $row->parser_confidence, 'issues' => $row->validation_issues ?? [],
        ])->all();
        $source = Storage::disk($import->storage_disk ?? 'local');
        $archivePath = 'imports/reference-'.$import->sha256.'.pdf';
        if ($import->stored_path && $source->exists($import->stored_path) && ! Storage::disk('local')->exists($archivePath)) {
            Storage::disk('local')->put($archivePath, $source->get($import->stored_path));
        }
        $this->rememberDocument($import->sha256, $import->original_filename, $import->parser_version ?? '1.2.0', $import->page_count ?? 1, $rows, Storage::disk('local')->exists($archivePath) ? $archivePath : null);
    }

    /** @param list<array<string, mixed>> $rows */
    public function rememberDocument(string $sha256, string $filename, string $parserVersion, int $pageCount, array $rows, ?string $storedPath): void
    {
        $existing = DB::table('schedule_document_references')->where('sha256', $sha256)->first();
        DB::table('schedule_document_references')->updateOrInsert(['sha256' => $sha256], [
            'original_filename' => $filename, 'parser_version' => $parserVersion,
            'page_count' => $pageCount, 'stored_path' => $storedPath,
            'parsed_rows' => json_encode($rows, JSON_THROW_ON_ERROR),
            'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{rows: list<array<string, mixed>>, page_count: int}|null */
    public function parsedDocument(string $sha256): ?array
    {
        $reference = DB::table('schedule_document_references')->where('sha256', $sha256)->where('parser_version', '1.2.0')->first();
        if (! $reference || $reference->page_count > 200) {
            return null;
        }
        $rows = json_decode($reference->parsed_rows, true, 512, JSON_THROW_ON_ERROR);
        if (count($rows) > 10000) {
            return null;
        }

        return ['rows' => $rows, 'page_count' => $reference->page_count];
    }

    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/[^\pL\pN]+/u', ' ', $name))));
    }

    public static function normalizeBaseName(string $name): string
    {
        $base = explode(',', $name)[0];
        $base = preg_replace('/^(?:(?:can\s*\(\s*dr\.?\s*\)\.?|(?:prof|drs|dra|dr|ir)\.)\s*)+/iu', '', trim($base));

        return self::normalizeName($base);
    }
}
