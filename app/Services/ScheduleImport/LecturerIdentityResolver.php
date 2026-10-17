<?php

namespace App\Services\ScheduleImport;

use App\Models\Lecturer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LecturerIdentityResolver
{
    public function __construct(public ScheduleImportReferences $references) {}

    public static function signature(string $name): string
    {
        $withoutRole = preg_replace('/\s*\(\s*instruktur\s*\)|\s+instruktur\s*$/iu', '', $name);

        return ScheduleImportReferences::normalizeName($withoutRole ?? $name);
    }

    /** @return array{lecturer: ?Lecturer, profile: ?array<string, mixed>, reason: string, candidates: list<array<string, mixed>>} */
    public function match(string $name, ?string $nidn = null): array
    {
        $signature = self::signature($name);
        if ($signature === '') {
            return ['lecturer' => null, 'profile' => null, 'reason' => 'Nama kosong.', 'candidates' => []];
        }

        $candidates = array_map(fn (array $profile): array => [
            'profile' => $profile,
            'lecturer' => null,
            'name' => $profile['name'],
            'external_code' => $profile['external_code'] ?? null,
            'nidn' => preg_match('/^\d{10}$/', (string) ($profile['external_code'] ?? '')) ? $profile['external_code'] : null,
            'signature' => self::signature($profile['name']),
            'aliases' => array_values(array_unique(array_map(self::signature(...), $profile['aliases'] ?? [$profile['name']]))),
        ], $this->references->profiles());

        foreach (Lecturer::where('is_active', true)->get() as $lecturer) {
            $index = null;
            foreach ($candidates as $position => $candidate) {
                if ($lecturer->nidn && $candidate['nidn'] === $lecturer->nidn) {
                    $index = $position;
                    break;
                }
                if ($candidate['signature'] === self::signature($lecturer->name)
                    && (! $candidate['external_code'] || ! $lecturer->external_code
                        || $candidate['external_code'] === $lecturer->external_code)) {
                    $index = $position;
                    break;
                }
            }
            if ($index === null) {
                $candidates[] = [
                    'profile' => null, 'lecturer' => $lecturer, 'name' => $lecturer->name,
                    'external_code' => $lecturer->external_code,
                    'nidn' => $lecturer->nidn, 'signature' => self::signature($lecturer->name),
                    'aliases' => [self::signature($lecturer->name)],
                ];
            } else {
                $candidates[$index]['lecturer'] = $lecturer;
            }
        }

        $validNidn = $nidn && preg_match('/^\d{10}$/', $nidn) ? $nidn : null;
        $tiers = [];
        if ($validNidn) {
            $tiers['NIDN PDF'] = array_values(array_filter($candidates, fn (array $candidate): bool => $candidate['nidn'] === $validNidn));
            if (count($tiers['NIDN PDF']) === 1) {
                $byName = array_values(array_filter($candidates, fn (array $candidate): bool => in_array($signature, $candidate['aliases'], true)
                    || $candidate['signature'] === $signature));
                $conflictingNames = array_values(array_filter($byName, fn (array $candidate): bool => $candidate !== $tiers['NIDN PDF'][0]));
                if ($conflictingNames) {
                    return ['lecturer' => null, 'profile' => null,
                        'reason' => 'NIDN PDF dan nama menunjuk ke identitas berbeda.',
                        'candidates' => $this->displayCandidates([$tiers['NIDN PDF'][0], ...$conflictingNames])];
                }
            }
        }
        $tiers['alias terverifikasi'] = array_values(array_filter($candidates, fn (array $candidate): bool => in_array($signature, $candidate['aliases'], true)));
        $tiers['nama dan gelar'] = array_values(array_filter($candidates, fn (array $candidate): bool => $candidate['signature'] === $signature));

        foreach ($tiers as $reason => $matches) {
            if (count($matches) > 1) {
                return ['lecturer' => null, 'profile' => null, 'reason' => 'Lebih dari satu kandidat cocok pada '.$reason.'.', 'candidates' => $this->displayCandidates($matches)];
            }
            if (count($matches) === 1) {
                $match = $matches[0];
                if ($validNidn && $match['nidn'] && $match['nidn'] !== $validNidn) {
                    return ['lecturer' => null, 'profile' => null, 'reason' => 'NIDN PDF bertentangan dengan identitas kandidat.', 'candidates' => $this->displayCandidates([$match])];
                }
                if ($match['profile'] && ! ($match['profile']['is_active'] ?? false)) {
                    return ['lecturer' => null, 'profile' => null, 'reason' => 'Referensi dosen tidak aktif.', 'candidates' => $this->displayCandidates([$match])];
                }

                return ['lecturer' => $match['lecturer'], 'profile' => $match['profile'], 'reason' => $reason, 'candidates' => $this->displayCandidates([$match])];
            }
        }

        $base = ScheduleImportReferences::normalizeBaseName($name);
        $suggestions = array_values(array_filter($candidates, fn (array $candidate): bool => ScheduleImportReferences::normalizeBaseName($candidate['name']) === $base));

        return ['lecturer' => null, 'profile' => null, 'reason' => 'Nama atau gelar belum terverifikasi.', 'candidates' => $this->displayCandidates(array_slice($suggestions, 0, 8))];
    }

    public function resolve(string $name, ?string $nidn = null, bool $restore = false): ?Lecturer
    {
        $match = $this->match($name, $nidn);

        return $match['lecturer'] ?? ($restore && $match['profile'] ? $this->references->restoreProfile($match['profile']) : null);
    }

    public function verifyAlias(Lecturer $lecturer, string $alias, int $reviewerId): void
    {
        if (mb_strlen($alias) > 255) {
            throw ValidationException::withMessages(['alias' => 'Nama dari PDF terlalu panjang untuk disimpan sebagai alias.']);
        }
        $normalized = self::signature($alias);
        $key = $this->references->identityKey($lecturer->name, $lecturer->external_code);
        $existingMatch = $this->match($alias);
        if (($existingMatch['lecturer'] && $existingMatch['lecturer']->id !== $lecturer->id)
            || ($existingMatch['profile'] && $existingMatch['profile']['identity_key'] !== $key)
            || (str_starts_with($existingMatch['reason'], 'Lebih dari satu kandidat')
                && collect($existingMatch['candidates'])->contains(fn (array $candidate): bool => $candidate['lecturer_id'] !== $lecturer->id))) {
            throw ValidationException::withMessages(['alias' => 'Nama ini sudah terhubung ke identitas lain. Audit duplikat melalui superadmin terlebih dahulu.']);
        }
        $conflict = DB::table('lecturer_verified_aliases')->where('normalized_alias', $normalized)
            ->where('identity_key', '!=', $key)->exists();
        if ($conflict) {
            throw ValidationException::withMessages(['alias' => 'Alias ini sudah terhubung ke identitas dosen lain.']);
        }
        DB::table('lecturer_verified_aliases')->updateOrInsert(
            ['identity_key' => $key, 'normalized_alias' => $normalized],
            ['canonical_name' => $lecturer->name, 'nidn' => $lecturer->nidn, 'alias' => trim($alias),
                'verified_by' => $reviewerId, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    /** @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function displayCandidates(array $candidates): array
    {
        return array_map(fn (array $candidate): array => [
            'lecturer_id' => $candidate['lecturer']?->id,
            'name' => $candidate['name'],
            'nidn' => $candidate['nidn'],
            'degree' => str_contains($candidate['name'], ',') ? trim(explode(',', $candidate['name'], 2)[1]) : null,
        ], $candidates);
    }
}
