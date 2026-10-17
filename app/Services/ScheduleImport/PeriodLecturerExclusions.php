<?php

namespace App\Services\ScheduleImport;

use Illuminate\Support\Facades\DB;

class PeriodLecturerExclusions
{
    public const SKIP_REASON = 'Dosen sengaja dihapus dari periode ini.';

    public const DEFERRED_REASON = 'Nama dosen ditunda oleh LPM.';

    /** @return list<string> */
    public static function intentionalReasons(): array
    {
        return [
            self::SKIP_REASON,
            'Dosen dihapus manual dari Direktori Dosen.',
            'Dosen dihapus dari periode ini oleh superadmin.',
        ];
    }

    public function contains(int $periodId, string $lecturerName): bool
    {
        if (! DB::table('period_lecturer_exclusions')->where('supervision_period_id', $periodId)->exists()) {
            return false;
        }
        $names = [$lecturerName, ...app(ScheduleImportReferences::class)->verifiedCatalogAliases($lecturerName)];

        return DB::table('period_lecturer_exclusions')
            ->where('supervision_period_id', $periodId)
            ->whereIn('normalized_name', array_map(ScheduleImportReferences::normalizeName(...), $names))
            ->exists();
    }

    /** @param list<string> $lecturerNames */
    public function exclude(int $periodId, array $lecturerNames, ?int $actorId): void
    {
        $normalizedNames = collect($lecturerNames)
            ->map(fn (string $name): string => ScheduleImportReferences::normalizeName($name))
            ->filter()->unique()->values();
        if ($normalizedNames->isEmpty()) {
            return;
        }

        $now = now();
        DB::table('period_lecturer_exclusions')->upsert(
            $normalizedNames->map(fn (string $name): array => [
                'supervision_period_id' => $periodId,
                'normalized_name' => $name,
                'reason' => self::SKIP_REASON,
                'excluded_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['supervision_period_id', 'normalized_name'],
            ['reason', 'excluded_by', 'updated_at']
        );
    }
}
