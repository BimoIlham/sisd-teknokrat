<?php

namespace App\Services;

use App\Models\Lecturer;
use App\Models\User;
use App\Services\ScheduleImport\LecturerIdentityResolver;
use App\Services\ScheduleImport\ScheduleImportReferences;
use App\Services\Scheduling\SchedulingLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LecturerDuplicateMergeService
{
    public function __construct(public ScheduleImportReferences $references) {}

    /** @return list<array{first: Lecturer, second: Lecturer, reason: string}> */
    public function candidates(): array
    {
        $lecturers = Lecturer::orderBy('name')->get();
        $aliases = [];
        foreach ($this->references->profiles() as $profile) {
            foreach ($profile['aliases'] ?? [$profile['name']] as $alias) {
                $aliases[LecturerIdentityResolver::signature($alias)][] = $profile['identity_key'];
            }
        }
        $pairs = [];
        foreach ($lecturers as $index => $first) {
            foreach ($lecturers->slice($index + 1) as $second) {
                $firstSignature = LecturerIdentityResolver::signature($first->name);
                $secondSignature = LecturerIdentityResolver::signature($second->name);
                $sameReference = array_intersect($aliases[$firstSignature] ?? [], $aliases[$secondSignature] ?? []);
                $sameName = $firstSignature === $secondSignature;
                $sameBase = ScheduleImportReferences::normalizeBaseName($first->name) === ScheduleImportReferences::normalizeBaseName($second->name);
                if (! $sameReference && ! $sameName && ! $sameBase) {
                    continue;
                }
                $pairs[] = ['first' => $first, 'second' => $second,
                    'reason' => $sameReference ? 'Alias pada referensi yang sama' : ($sameName ? 'Nama inti dan gelar sama' : 'Nama inti sama, gelar berbeda')];
            }
        }

        return $pairs;
    }

    /** @return array{fingerprint: string, counts: array<string, int>, conflicts: list<string>, periods: list<array<string, mixed>>} */
    public function preview(Lecturer $keep, Lecturer $remove): array
    {
        if ($keep->id === $remove->id) {
            throw ValidationException::withMessages(['lecturer' => 'Pilih dua identitas dosen yang berbeda.']);
        }
        $tables = [
            'users' => 'lecturer_id', 'period_targets' => 'lecturer_id',
            'period_supervisors' => 'lecturer_id', 'teaching_schedules' => 'lecturer_id',
            'schedule_import_rows' => 'matched_lecturer_id',
            'generated_documents' => 'supervisor_lecturer_id',
            'supervision_assignments_target' => 'target_lecturer_id',
            'supervision_assignments_supervisor' => 'supervisor_lecturer_id',
        ];
        $snapshot = ['lecturers' => [$keep->fresh()?->toArray(), $remove->fresh()?->toArray()]];
        $counts = [];
        foreach ($tables as $name => $column) {
            $table = str_starts_with($name, 'supervision_assignments_') ? 'supervision_assignments' : $name;
            $rows = DB::table($table)->whereIn($column, [$keep->id, $remove->id])->orderBy('id')->get()->all();
            $snapshot[$name] = $rows;
            $counts[$name] = count($rows);
        }
        $identityKeys = [$this->references->identityKey($keep->name, $keep->external_code), $this->references->identityKey($remove->name, $remove->external_code)];
        $snapshot['verified_aliases'] = DB::table('lecturer_verified_aliases')->whereIn('identity_key', $identityKeys)->orderBy('id')->get()->all();
        $snapshot['identity_references'] = DB::table('lecturer_identity_references')->whereIn('identity_key', $identityKeys)->orderBy('id')->get()->all();
        $conflicts = [];
        if ($keep->nidn && $remove->nidn && $keep->nidn !== $remove->nidn) {
            $conflicts[] = 'Kedua identitas memiliki NIDN berbeda.';
        }
        if ($keep->external_code && ! $keep->nidn && $remove->nidn) {
            $conflicts[] = 'Identitas utama memiliki kode lama yang perlu diperiksa sebelum menerima NIDN.';
        }
        if ($counts['users'] > 1) {
            $conflicts[] = 'Kedua dosen sudah memiliki akun pengguna.';
        }
        if (User::whereIn('lecturer_id', [$keep->id, $remove->id])->get()
            ->contains(fn (User $user): bool => $user->hasAnyRole(['superadmin', 'lpm_admin']))) {
            $conflicts[] = 'Identitas terhubung ke akun administrator.';
        }
        foreach (['period_targets' => 'scope_status', 'period_supervisors' => 'is_active'] as $table => $field) {
            $grouped = collect($snapshot[$table])->groupBy('supervision_period_id');
            foreach ($grouped as $periodId => $entries) {
                if ($entries->count() > 1 && $entries->pluck($field)->unique()->count() > 1) {
                    $conflicts[] = "Keikutsertaan {$table} pada periode {$periodId} bertentangan.";
                }
            }
        }
        $assignments = collect($snapshot['supervision_assignments_target']);
        foreach ($assignments->groupBy('supervision_stage_id') as $stageId => $entries) {
            if ($entries->pluck('target_lecturer_id')->unique()->count() > 1
                && $entries->where('status', 'FINAL')->count() > 0) {
                $conflicts[] = "Riwayat final dua target pada tahap {$stageId} bertentangan.";
            }
            if ($entries->where('status', '!=', 'CANCELLED')->pluck('target_lecturer_id')->unique()->count() > 1) {
                $conflicts[] = "Dua penugasan aktif pada tahap {$stageId} akan bertabrakan.";
            }
        }
        $allAssignments = collect($snapshot['supervision_assignments_target'])
            ->merge($snapshot['supervision_assignments_supervisor'])->unique('id');
        if ($allAssignments->contains(fn (object $assignment): bool => in_array($assignment->target_lecturer_id, [$keep->id, $remove->id], true)
            && in_array($assignment->supervisor_lecturer_id, [$keep->id, $remove->id], true))) {
            $conflicts[] = 'Penggabungan akan membuat dosen mensupervisi dirinya sendiri.';
        }
        $scheduleGroups = collect($snapshot['teaching_schedules'])->where('is_active', true)
            ->groupBy(fn (object $schedule): string => implode('|', [
                $schedule->supervision_period_id, $schedule->course_id, $schedule->class_name,
                $schedule->day_of_week, $schedule->starts_local, $schedule->ends_local,
                $schedule->room_name, $schedule->delivery_mode,
            ]));
        if ($scheduleGroups->contains(fn ($schedules): bool => $schedules->pluck('lecturer_id')->unique()->count() > 1)) {
            $conflicts[] = 'Kedua identitas memiliki jadwal aktif yang sama; periksa duplikat jadwal terlebih dahulu.';
        }

        $periodIds = collect($snapshot['period_targets'])->pluck('supervision_period_id')
            ->merge(collect($snapshot['period_supervisors'])->pluck('supervision_period_id'))
            ->merge(collect($snapshot['teaching_schedules'])->pluck('supervision_period_id'))
            ->filter()->unique()->values();
        $periodRecords = DB::table('supervision_periods')->whereIn('id', $periodIds)->orderBy('id')->get();
        $snapshot['supervision_periods'] = $periodRecords->all();
        $periods = $periodRecords
            ->map(fn (object $period): array => [
                'id' => $period->id,
                'name' => $period->name,
                'targets' => collect($snapshot['period_targets'])->where('supervision_period_id', $period->id)->count(),
                'supervisors' => collect($snapshot['period_supervisors'])->where('supervision_period_id', $period->id)->count(),
                'schedules' => collect($snapshot['teaching_schedules'])->where('supervision_period_id', $period->id)->count(),
            ])->all();

        return ['fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'counts' => $counts, 'conflicts' => array_values(array_unique($conflicts)), 'periods' => $periods];
    }

    public function merge(Lecturer $keep, Lecturer $remove, string $fingerprint, User $actor): void
    {
        DB::transaction(function () use ($keep, $remove, $fingerprint, $actor): void {
            app(SchedulingLockService::class)->state();
            $keep = Lecturer::query()->lockForUpdate()->findOrFail($keep->id);
            $remove = Lecturer::query()->lockForUpdate()->findOrFail($remove->id);
            $impact = $this->preview($keep, $remove);
            if (! hash_equals($impact['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['lecturer' => 'Data berubah sejak pratinjau. Muat ulang pratinjau sebelum menggabungkan.']);
            }
            if ($impact['conflicts']) {
                throw ValidationException::withMessages(['lecturer' => implode(' ', $impact['conflicts'])]);
            }
            foreach (['period_targets' => 'scope_status', 'period_supervisors' => 'is_active'] as $table => $field) {
                $keepRows = DB::table($table)->where('lecturer_id', $keep->id)->pluck($field, 'supervision_period_id');
                $removeRows = DB::table($table)->where('lecturer_id', $remove->id)->get();
                foreach ($removeRows as $row) {
                    if ($keepRows->has($row->supervision_period_id)) {
                        DB::table($table)->where('id', $row->id)->delete();
                    } else {
                        DB::table($table)->where('id', $row->id)->update(['lecturer_id' => $keep->id]);
                    }
                }
            }
            foreach (['users' => 'lecturer_id', 'teaching_schedules' => 'lecturer_id',
                'schedule_import_rows' => 'matched_lecturer_id', 'generated_documents' => 'supervisor_lecturer_id',
                'supervision_assignments' => 'target_lecturer_id'] as $table => $column) {
                DB::table($table)->where($column, $remove->id)->update([$column => $keep->id]);
            }
            DB::table('supervision_assignments')->where('supervisor_lecturer_id', $remove->id)
                ->update(['supervisor_lecturer_id' => $keep->id]);

            $oldKeepKey = $this->references->identityKey($keep->name, $keep->external_code);
            $removeKey = $this->references->identityKey($remove->name, $remove->external_code);
            if (! $keep->external_code && $remove->nidn) {
                DB::table('lecturers')->where('id', $remove->id)->update(['external_code' => null]);
                $keep->external_code = $remove->nidn;
                $keep->save();
            }
            $targetKey = $this->references->identityKey($keep->name, $keep->external_code);
            foreach (array_unique([$oldKeepKey, $removeKey]) as $sourceKey) {
                if ($sourceKey === $targetKey) {
                    continue;
                }
                DB::table('lecturer_identity_redirects')->updateOrInsert(['source_key' => $sourceKey],
                    ['target_key' => $targetKey, 'merged_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('system_audits')->insert(['user_id' => $actor->id, 'event' => 'LECTURER_IDENTITY_MERGE',
                'entity_type' => 'lecturer', 'entity_id' => $keep->id,
                'old_values' => json_encode(['removed_id' => $remove->id, 'removed_name' => $remove->name,
                    'impact' => $impact['counts']], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            DB::table('lecturers')->where('id', $remove->id)->delete();
        }, 3);
    }
}
