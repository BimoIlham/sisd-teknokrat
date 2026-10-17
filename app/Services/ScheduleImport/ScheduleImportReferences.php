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
            'name_source' => $lecturer->identity_sources['name']['source'] ?? null,
            'aliases' => [$lecturer->name],
            'aliases_verified' => false,
        ]);
        if ($lecturer->getRawOriginal('id') && $lecturer->isDirty(['name', 'external_code'])
            && Schema::hasTable('lecturer_identity_redirects')) {
            $oldKey = $this->identityKey((string) $lecturer->getRawOriginal('name'), $lecturer->getRawOriginal('external_code'));
            $newKey = $this->identityKey($lecturer->name, $lecturer->external_code);
            if ($oldKey !== $newKey) {
                DB::table('lecturer_identity_redirects')->updateOrInsert(['source_key' => $oldKey], [
                    'target_key' => $newKey, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /** @param array<string, mixed> $profile */
    public function rememberProfile(array $profile): void
    {
        $key = ! empty($profile['external_code']) ? 'code:'.$profile['external_code'] : 'name:'.hash('sha256', self::normalizeName($profile['name']));
        $existing = DB::table('lecturer_identity_references')->where('identity_key', $key)->first();
        $previous = $existing ? json_decode($existing->profile, true, 512, JSON_THROW_ON_ERROR) : [];
        $verified = ($profile['aliases_verified'] ?? true) || ($previous['aliases_verified'] ?? false);
        $profile['aliases'] = array_values(array_unique(array_filter([
            ...(($profile['aliases_verified'] ?? true) ? ($profile['aliases'] ?? []) : []),
            ...(($previous['aliases_verified'] ?? false) ? ($previous['aliases'] ?? []) : []),
            $profile['name'],
        ])));
        $profile['aliases_verified'] = $verified;
        DB::table('lecturer_identity_references')->updateOrInsert(['identity_key' => $key], [
            'name' => $profile['name'], 'normalized_name' => self::normalizeName($profile['name']),
            'profile' => json_encode($profile, JSON_THROW_ON_ERROR),
            'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function profiles(): array
    {
        $profiles = [];
        foreach ($this->catalogProfiles() as $profile) {
            $key = $this->identityKey($profile['name'], $profile['external_code'] ?? null);
            $profiles[$key] = [...$profile, 'identity_key' => $key];
        }
        foreach (DB::table('lecturer_identity_references')->get() as $reference) {
            $profile = json_decode($reference->profile, true, 512, JSON_THROW_ON_ERROR);
            $key = $reference->identity_key;
            $catalog = $profiles[$key] ?? null;
            $canonical = ($profile['name_source'] ?? null) === 'manual' ? $profile['name'] : ($catalog['name'] ?? $profile['name']);
            $profiles[$key] = [
                ...($catalog ?? []), ...$profile, 'name' => $canonical,
                'aliases' => array_values(array_unique([
                    $canonical, ...($catalog['aliases'] ?? []),
                    ...(($profile['aliases_verified'] ?? false) ? ($profile['aliases'] ?? []) : []),
                ])),
                'identity_key' => $key, 'reference_id' => $reference->id,
            ];
        }
        if (Schema::hasTable('lecturer_verified_aliases')) {
            foreach (DB::table('lecturer_verified_aliases')->get() as $verified) {
                $key = $verified->identity_key;
                $profile = $profiles[$key] ?? [
                    'name' => $verified->canonical_name,
                    'external_code' => $verified->nidn,
                    'is_active' => true,
                    'aliases' => [],
                    'identity_key' => $key,
                ];
                $profile['aliases'] = array_values(array_unique([...($profile['aliases'] ?? []), $verified->alias]));
                $profiles[$key] = $profile;
            }
        }
        if (Schema::hasTable('lecturer_identity_redirects')) {
            foreach (DB::table('lecturer_identity_redirects')->get() as $redirect) {
                $source = $profiles[$redirect->source_key] ?? null;
                $target = $profiles[$redirect->target_key] ?? null;
                if (! $source || ! $target) {
                    continue;
                }
                $target['aliases'] = array_values(array_unique([
                    ...($target['aliases'] ?? []), $source['name'], ...($source['aliases'] ?? []),
                ]));
                $profiles[$redirect->target_key] = $target;
                unset($profiles[$redirect->source_key]);
            }
        }

        return array_values($profiles);
    }

    public function identityKey(string $name, ?string $externalCode): string
    {
        return $externalCode
            ? 'code:'.$externalCode
            : 'name:'.hash('sha256', self::normalizeName($name));
    }

    /** @param array<string, mixed> $profile */
    public function restoreProfile(array $profile): ?Lecturer
    {
        if (! ($profile['is_active'] ?? false)) {
            return null;
        }

        return DB::transaction(function () use ($profile): ?Lecturer {
            app(SchedulingLockService::class)->state();
            $existing = ! empty($profile['external_code'])
                ? Lecturer::where('external_code', $profile['external_code'])->first()
                : Lecturer::where('normalized_name', mb_strtolower($profile['name']))->first();
            if ($existing) {
                return $existing->is_active ? $existing : null;
            }
            $blocked = Lecturer::where('is_active', false)->get()->contains(fn (Lecturer $lecturer): bool => LecturerIdentityResolver::signature($lecturer->name) === LecturerIdentityResolver::signature($profile['name']));
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
                'identity_sources' => ['name' => ['source' => 'verified_reference', 'value' => $profile['name'], 'reference_id' => $profile['reference_id'] ?? null]],
            ]);
        }, 3);
    }

    public function restoreLecturer(string $name): ?Lecturer
    {
        return app(LecturerIdentityResolver::class)->resolve($name, null, true);
    }

    /** @return list<array<string, mixed>> */
    private function catalogProfiles(): array
    {
        $path = database_path('seeders/uti_lecturer_catalog.json');
        if (! is_file($path)) {
            return [];
        }

        $catalog = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return array_map(fn (array $profile): array => [...$profile, 'is_active' => true], $catalog['lecturers'] ?? []);
    }

    /** @return list<string> */
    public function verifiedCatalogAliases(string $name): array
    {
        $normalizedName = self::normalizeName($name);
        $profiles = collect($this->profiles());
        $matches = $profiles->filter(fn (array $profile): bool => collect($profile['aliases'] ?? [$profile['name']])
            ->contains(fn (string $alias): bool => LecturerIdentityResolver::signature($alias) === LecturerIdentityResolver::signature($name)));
        if ($matches->isEmpty()) {
            $matches = $profiles->filter(fn (array $profile): bool => collect($profile['aliases'] ?? [$profile['name']])
                ->contains(fn (string $alias): bool => self::normalizeName($alias) === $normalizedName));
        }

        if ($matches->count() !== 1) {
            return [];
        }

        $profile = $matches->first();

        return array_values(array_unique([$profile['name'], ...($profile['aliases'] ?? [])]));
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
        $this->rememberDocument($import->sha256, $import->original_filename, $import->parser_version ?? '1.3.0', $import->page_count ?? 1, $rows, Storage::disk('local')->exists($archivePath) ? $archivePath : null);
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
        $reference = DB::table('schedule_document_references')->where('sha256', $sha256)->where('parser_version', '1.3.0')->first();
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
