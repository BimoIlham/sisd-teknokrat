<?php

namespace App\Services;

use App\Models\Lecturer;
use App\Models\ScheduleImport;
use App\Models\SupervisionPeriod;
use App\Models\TeachingSchedule;
use App\Models\User;
use App\Services\ScheduleImport\ScheduleImportReferences;
use App\Services\Scheduling\SchedulingLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class PermanentDeletionService
{
    public function __construct(private readonly SchedulingLockService $locks) {}

    /** @return array{name: string, counts: array<string, int>, token: string} */
    public function preview(string $type, int $id): array
    {
        abort_unless(in_array($type, ['lecturer', 'supervisor', 'schedule', 'import', 'period'], true), 404);
        $resource = match ($type) {
            'lecturer', 'supervisor' => Lecturer::findOrFail($id),
            'schedule' => TeachingSchedule::findOrFail($id),
            'import' => ScheduleImport::findOrFail($id),
            'period' => SupervisionPeriod::findOrFail($id),
        };
        if ($type === 'supervisor') {
            abort_unless(DB::table('period_supervisors')->where('lecturer_id', $id)->exists() || User::where('lecturer_id', $id)->role('supervisor')->exists(), 404);
        }
        $name = match ($type) {
            'lecturer', 'supervisor', 'period' => $resource->name,
            'schedule' => $resource->class_name.' / #'.$id,
            'import' => $resource->original_filename,
        };
        $scheduleIds = $this->scheduleIds($type, $id);
        $occurrenceIds = DB::table('teaching_occurrences')->whereIn('teaching_schedule_id', $scheduleIds)->pluck('id')->all();
        $stageIds = $type === 'period' ? DB::table('supervision_stages')->where('supervision_period_id', $id)->pluck('id')->all() : [];
        $assignmentIds = $this->assignmentIds($type, $id, $occurrenceIds, $stageIds);
        $supervisionIds = DB::table('supervisions')->whereIn('supervision_assignment_id', $assignmentIds)->pluck('id')->all();
        $answerIds = DB::table('supervision_answers')->whereIn('supervision_id', $supervisionIds)->pluck('id')->all();
        $periodIds = $this->affectedPeriodIds($type, $id, $assignmentIds);
        $documentQuery = DB::table('generated_documents')->whereIn('supervision_period_id', $periodIds);
        if (in_array($type, ['lecturer', 'supervisor'], true)) {
            $documentQuery->orWhere('supervisor_lecturer_id', $id);
        }
        $counts = [
            'jadwal' => count($scheduleIds),
            'pertemuan' => count($occurrenceIds),
            'penugasan' => count($assignmentIds),
            'hasil_supervisi' => count($supervisionIds),
            'jawaban' => count($answerIds),
            'bukti' => DB::table('supervision_evidence')->whereIn('supervision_answer_id', $answerIds)->count(),
            'dokumen' => $documentQuery->count(),
            'hasil_solver' => DB::table('schedule_runs')->whereIn('supervision_stage_id', DB::table('supervision_stages')->whereIn('supervision_period_id', $periodIds)->select('id'))->count(),
        ];
        if ($type === 'import') {
            $counts['baris_impor'] = DB::table('schedule_import_rows')->where('schedule_import_id', $id)->count();
        }
        if ($type === 'period') {
            $counts['target'] = DB::table('period_targets')->where('supervision_period_id', $id)->count();
            $counts['supervisor'] = DB::table('period_supervisors')->where('supervision_period_id', $id)->count();
            $counts['instrumen_tahap'] = DB::table('stage_instruments')->whereIn('supervision_stage_id', $stageIds)->count();
        }
        if (in_array($type, ['lecturer', 'supervisor'], true)) {
            $counts['akun_terkait'] = User::where('lecturer_id', $id)->count();
        }
        $fingerprint = [
            $type, $id, $name, (string) $resource->updated_at, $resource->row_version ?? null,
            DB::table('scheduling_state')->where('id', 1)->value('revision'), $counts,
            DB::table('teaching_schedules')->whereIn('id', $scheduleIds)->orderBy('id')->get(['id', 'row_version', 'updated_at'])->all(),
            DB::table('teaching_occurrences')->whereIn('id', $occurrenceIds)->orderBy('id')->get(['id', 'row_version', 'updated_at'])->all(),
            DB::table('supervision_assignments')->whereIn('id', $assignmentIds)->orderBy('id')->get(['id', 'row_version', 'updated_at'])->all(),
            DB::table('supervisions')->whereIn('id', $supervisionIds)->orderBy('id')->get(['id', 'row_version', 'updated_at'])->all(),
            DB::table('supervision_answers')->whereIn('id', $answerIds)->orderBy('id')->get(['id', 'updated_at'])->all(),
            DB::table('supervision_evidence')->whereIn('supervision_answer_id', $answerIds)->orderBy('id')->get(['id', 'updated_at'])->all(),
            (clone $documentQuery)->orderBy('id')->get(['id', 'updated_at'])->all(),
            DB::table('supervision_stages')->whereIn('supervision_period_id', $periodIds)->orderBy('id')->get(['id', 'row_version', 'updated_at'])->all(),
            DB::table('schedule_runs')->whereIn('supervision_stage_id', DB::table('supervision_stages')->whereIn('supervision_period_id', $periodIds)->select('id'))->orderBy('id')->get(['id', 'updated_at'])->all(),
            DB::table('period_targets')->where('lecturer_id', $id)->orderBy('id')->get(['id', 'row_version', 'updated_at'])->all(),
            DB::table('period_supervisors')->where('lecturer_id', $id)->orderBy('id')->get(['id', 'row_version', 'updated_at'])->all(),
            $this->importRowsForToken($type, $id, $scheduleIds),
        ];

        return ['name' => $name, 'counts' => $counts, 'token' => hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR))];
    }

    /** @return array{counts: array<string, int>, failed_files: list<string>} */
    public function delete(string $type, int $id, string $name, string $token, User $actor): array
    {
        [$counts, $files] = DB::transaction(fn (): array => $this->deleteWithinTransaction($type, $id, $name, $token, $actor), 3);

        return ['counts' => $counts, 'failed_files' => $this->retryPendingFiles($files)];
    }

    /** @return array{name: string, counts: array<string, int>, token: string} */
    public function previewPeriodLecturer(int $lecturerId, int $periodId): array
    {
        $lecturer = Lecturer::findOrFail($lecturerId);
        $period = SupervisionPeriod::findOrFail($periodId);
        $scheduleIds = DB::table('teaching_schedules')->where('supervision_period_id', $periodId)
            ->where('lecturer_id', $lecturerId)->pluck('id')->all();
        $occurrenceIds = DB::table('teaching_occurrences')->whereIn('teaching_schedule_id', $scheduleIds)->pluck('id')->all();
        $stageIds = DB::table('supervision_stages')->where('supervision_period_id', $periodId)->pluck('id')->all();
        $assignmentIds = DB::table('supervision_assignments')->whereIn('supervision_stage_id', $stageIds)
            ->where(fn ($query) => $query->where('target_lecturer_id', $lecturerId)
                ->orWhere('supervisor_lecturer_id', $lecturerId)->orWhereIn('teaching_occurrence_id', $occurrenceIds))
            ->pluck('id')->all();
        $supervisionIds = DB::table('supervisions')->whereIn('supervision_assignment_id', $assignmentIds)->pluck('id')->all();
        $answerIds = DB::table('supervision_answers')->whereIn('supervision_id', $supervisionIds)->pluck('id')->all();
        $importIds = DB::table('schedule_imports')->where('supervision_period_id', $periodId)->pluck('id')->all();
        $importRows = DB::table('schedule_import_rows')->whereIn('schedule_import_id', $importIds)
            ->where(fn ($query) => $query->where('matched_lecturer_id', $lecturerId)
                ->orWhereIn('committed_schedule_id', $scheduleIds))->orderBy('id')->get(['id', 'row_version', 'updated_at']);
        $counts = [
            'jadwal' => count($scheduleIds),
            'pertemuan' => count($occurrenceIds),
            'penugasan' => count($assignmentIds),
            'hasil_supervisi' => count($supervisionIds),
            'jawaban' => count($answerIds),
            'bukti' => DB::table('supervision_evidence')->whereIn('supervision_answer_id', $answerIds)->count(),
            'dokumen_dihapus' => DB::table('generated_documents')->where('supervision_period_id', $periodId)->where('supervisor_lecturer_id', $lecturerId)->count(),
            'dokumen_lain_ditandai_usang' => DB::table('generated_documents')->where('supervision_period_id', $periodId)
                ->where(fn ($query) => $query->whereNull('supervisor_lecturer_id')->orWhere('supervisor_lecturer_id', '<>', $lecturerId))->count(),
            'hasil_solver_periode' => DB::table('schedule_runs')->whereIn('supervision_stage_id', $stageIds)->count(),
            'baris_impor' => $importRows->count(),
            'target' => DB::table('period_targets')->where('supervision_period_id', $periodId)->where('lecturer_id', $lecturerId)->where('scope_status', 'INCLUDED')->count(),
            'supervisor' => DB::table('period_supervisors')->where('supervision_period_id', $periodId)->where('lecturer_id', $lecturerId)->count(),
        ];
        $fingerprint = [
            $lecturerId, $periodId, $lecturer->name, $lecturer->row_version, (string) $lecturer->updated_at, (string) $period->updated_at,
            DB::table('scheduling_state')->where('id', 1)->value('revision'), $counts,
            DB::table('teaching_schedules')->whereIn('id', $scheduleIds)->orderBy('id')->get(['id', 'row_version', 'updated_at']),
            DB::table('teaching_occurrences')->whereIn('id', $occurrenceIds)->orderBy('id')->get(['id', 'row_version', 'updated_at']),
            DB::table('supervision_assignments')->whereIn('id', $assignmentIds)->orderBy('id')->get(['id', 'row_version', 'updated_at']),
            DB::table('supervisions')->whereIn('id', $supervisionIds)->orderBy('id')->get(['id', 'row_version', 'updated_at']),
            DB::table('supervision_answers')->whereIn('id', $answerIds)->orderBy('id')->get(['id', 'updated_at']),
            DB::table('supervision_evidence')->whereIn('supervision_answer_id', $answerIds)->orderBy('id')->get(['id', 'updated_at']),
            DB::table('generated_documents')->where('supervision_period_id', $periodId)->orderBy('id')->get(['id', 'updated_at']),
            DB::table('schedule_runs')->whereIn('supervision_stage_id', $stageIds)->orderBy('id')->get(['id', 'updated_at']),
            DB::table('period_targets')->where('supervision_period_id', $periodId)->where('lecturer_id', $lecturerId)->get(['id', 'row_version', 'updated_at']),
            DB::table('period_supervisors')->where('supervision_period_id', $periodId)->where('lecturer_id', $lecturerId)->get(['id', 'row_version', 'updated_at']),
            $importRows,
        ];

        return ['name' => $lecturer->name, 'counts' => $counts, 'token' => hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR))];
    }

    /** @return array{counts: array<string, int>, failed_files: list<string>} */
    public function deletePeriodLecturer(int $lecturerId, int $periodId, string $name, string $token, User $actor): array
    {
        [$counts, $files] = DB::transaction(function () use ($lecturerId, $periodId, $name, $token, $actor): array {
            $state = $this->locks->state();
            $impact = $this->previewPeriodLecturer($lecturerId, $periodId);
            if ($impact['name'] !== $name || ! hash_equals($impact['token'], $token)) {
                throw ValidationException::withMessages(['confirmation_name' => 'Data telah berubah. Muat ulang pratinjau sebelum menghapus.']);
            }
            $scheduleIds = DB::table('teaching_schedules')->where('supervision_period_id', $periodId)->where('lecturer_id', $lecturerId)->pluck('id')->all();
            $occurrenceIds = DB::table('teaching_occurrences')->whereIn('teaching_schedule_id', $scheduleIds)->pluck('id')->all();
            $stageIds = DB::table('supervision_stages')->where('supervision_period_id', $periodId)->pluck('id')->all();
            $assignmentIds = DB::table('supervision_assignments')->whereIn('supervision_stage_id', $stageIds)
                ->where(fn ($query) => $query->where('target_lecturer_id', $lecturerId)
                    ->orWhere('supervisor_lecturer_id', $lecturerId)->orWhereIn('teaching_occurrence_id', $occurrenceIds))->pluck('id')->all();
            $supervisionIds = DB::table('supervisions')->whereIn('supervision_assignment_id', $assignmentIds)->pluck('id')->all();
            $answerIds = DB::table('supervision_answers')->whereIn('supervision_id', $supervisionIds)->pluck('id')->all();
            $files = DB::table('supervision_evidence')->whereIn('supervision_answer_id', $answerIds)
                ->whereNotNull('file_path')->get(['storage_disk', 'file_path'])->all();
            $files = [...$files, ...DB::table('generated_documents')->where('supervision_period_id', $periodId)
                ->where('supervisor_lecturer_id', $lecturerId)
                ->whereNotNull('file_path')->get(['storage_disk', 'file_path'])->all()];

            DB::table('supervision_evidence')->whereIn('supervision_answer_id', $answerIds)->delete();
            DB::table('supervision_answers')->whereIn('supervision_id', $supervisionIds)->delete();
            DB::table('supervisions')->whereIn('id', $supervisionIds)->delete();
            DB::table('assignment_audits')->whereIn('supervision_assignment_id', $assignmentIds)->delete();
            DB::table('supervision_assignments')->whereIn('id', $assignmentIds)->delete();
            DB::table('generated_documents')->where('supervision_period_id', $periodId)
                ->where('supervisor_lecturer_id', $lecturerId)->delete();
            DB::table('generated_documents')->where('supervision_period_id', $periodId)
                ->where('status', '<>', 'STALE')->update(['status' => 'STALE', 'updated_at' => now()]);
            DB::table('schedule_runs')->whereIn('supervision_stage_id', $stageIds)->delete();
            DB::table('supervision_stages')->whereIn('id', $stageIds)->update([
                'status' => 'DRAFT', 'finalized_by' => null, 'finalized_at' => null, 'updated_at' => now(),
            ]);
            DB::table('period_supervisors')->where('supervision_period_id', $periodId)->where('lecturer_id', $lecturerId)->delete();
            DB::table('period_targets')->updateOrInsert(
                ['supervision_period_id' => $periodId, 'lecturer_id' => $lecturerId],
                ['scope_status' => 'EXCLUDED', 'reason' => 'Dihapus dari periode oleh superadmin.', 'selected_by' => $actor->id, 'updated_at' => now()]
            );
            $importIds = DB::table('schedule_imports')->where('supervision_period_id', $periodId)->pluck('id')->all();
            DB::table('schedule_import_rows')->whereIn('schedule_import_id', $importIds)
                ->where(fn ($query) => $query->where('matched_lecturer_id', $lecturerId)->orWhereIn('committed_schedule_id', $scheduleIds))
                ->update(['matched_lecturer_id' => null, 'committed_schedule_id' => null,
                    'validation_status' => 'SKIPPED', 'is_approved' => false,
                    'skip_reason' => 'Dosen dihapus dari periode ini oleh superadmin.', 'updated_at' => now()]);
            DB::table('teaching_occurrences')->whereIn('id', $occurrenceIds)->delete();
            DB::table('teaching_schedules')->whereIn('id', $scheduleIds)->delete();
            $this->locks->bump($state);
            DB::table('system_audits')->insert(['user_id' => $actor->id, 'event' => 'PERMANENT_DELETE_PERIOD_LECTURER',
                'entity_type' => 'lecturer', 'entity_id' => $lecturerId,
                'old_values' => json_encode(['period_id' => $periodId, 'name' => $name, 'counts' => $impact['counts']], JSON_THROW_ON_ERROR),
                'created_at' => now()]);
            foreach ($files as $file) {
                if ($file->file_path && ! $this->fileStillReferenced($file->storage_disk, $file->file_path)) {
                    DB::table('pending_file_deletions')->insertOrIgnore(['storage_disk' => $file->storage_disk,
                        'path_hash' => hash('sha256', $file->file_path), 'file_path' => $file->file_path,
                        'created_at' => now(), 'updated_at' => now()]);
                }
            }

            return [$impact['counts'], $files];
        }, 3);

        return ['counts' => $counts, 'failed_files' => $this->retryPendingFiles($files)];
    }

    /** @return array{type: string, ids: list<int>, phrase: string, counts: array<string, int>, protected_names: list<string>, token: string} */
    public function previewBulk(string $type): array
    {
        $ids = $this->bulkTargetIds($type);
        $phrase = $type === 'lecturer' ? 'HAPUS SEMUA DOSEN' : 'HAPUS SEMUA SUPERVISOR';
        $scheduleIds = $type === 'lecturer' ? DB::table('teaching_schedules')->whereIn('lecturer_id', $ids)->pluck('id')->all() : [];
        $occurrenceIds = DB::table('teaching_occurrences')->whereIn('teaching_schedule_id', $scheduleIds)->pluck('id')->all();
        $assignmentIds = DB::table('supervision_assignments')->where(function ($query) use ($type, $ids, $occurrenceIds): void {
            $query->whereIn('supervisor_lecturer_id', $ids);
            if ($type === 'lecturer') {
                $query->orWhereIn('target_lecturer_id', $ids)->orWhereIn('teaching_occurrence_id', $occurrenceIds);
            }
        })->pluck('id')->all();
        $supervisionIds = DB::table('supervisions')->whereIn('supervision_assignment_id', $assignmentIds)->pluck('id')->all();
        $answerIds = DB::table('supervision_answers')->whereIn('supervision_id', $supervisionIds)->pluck('id')->all();
        $periodIds = DB::table('supervision_stages')->whereIn('id', DB::table('supervision_assignments')->whereIn('id', $assignmentIds)->select('supervision_stage_id'))->pluck('supervision_period_id')
            ->concat(DB::table('period_supervisors')->whereIn('lecturer_id', $ids)->pluck('supervision_period_id'));
        if ($type === 'lecturer') {
            $periodIds = $periodIds->concat(DB::table('period_targets')->whereIn('lecturer_id', $ids)->pluck('supervision_period_id'));
        }
        $periodIds = $periodIds->unique()->values()->all();
        $stageIds = DB::table('supervision_stages')->whereIn('supervision_period_id', $periodIds)->pluck('id')->all();
        $counts = [
            $type === 'lecturer' ? 'dosen' : 'supervisor' => count($ids),
            'jadwal' => count($scheduleIds),
            'pertemuan' => count($occurrenceIds),
            'penugasan' => count($assignmentIds),
            'hasil_supervisi' => count($supervisionIds),
            'jawaban' => count($answerIds),
            'bukti' => DB::table('supervision_evidence')->whereIn('supervision_answer_id', $answerIds)->count(),
            'dokumen' => DB::table('generated_documents')->whereIn('supervision_period_id', $periodIds)->orWhereIn('supervisor_lecturer_id', $ids)->count(),
            'hasil_solver' => DB::table('schedule_runs')->whereIn('supervision_stage_id', $stageIds)->count(),
            'akun_terkait' => User::whereIn('lecturer_id', $ids)->count(),
        ];
        $protectedNames = User::whereIn('lecturer_id', $ids)->get()->filter(fn (User $account): bool => $account->isLpmStaff())
            ->map(fn (User $account): string => $account->lecturer?->name ?? '#'.$account->lecturer_id)->values()->all();
        $itemTokens = [];
        foreach ($ids as $id) {
            $itemTokens[$id] = $this->preview($type, $id)['token'];
        }

        return [
            'type' => $type, 'ids' => $ids, 'phrase' => $phrase, 'counts' => $counts,
            'protected_names' => $protectedNames,
            'token' => hash('sha256', json_encode([$type, $ids, $itemTokens, $counts, $protectedNames], JSON_THROW_ON_ERROR)),
        ];
    }

    /** @return array{counts: array<string, int>, failed_files: list<string>} */
    public function deleteBulk(string $type, string $phrase, string $token, User $actor): array
    {
        [$counts, $files] = DB::transaction(function () use ($type, $phrase, $token, $actor): array {
            $this->locks->state();
            $impact = $this->previewBulk($type);
            if ($impact['ids'] === [] || ! hash_equals($impact['token'], $token) || $impact['phrase'] !== $phrase) {
                throw ValidationException::withMessages(['confirmation_phrase' => 'Data telah berubah atau frasa konfirmasi tidak sesuai. Muat ulang pratinjau.']);
            }
            $protected = User::whereIn('lecturer_id', $impact['ids'])->get()->filter(fn (User $account): bool => $account->isLpmStaff() || $account->id === $actor->id);
            if ($protected->isNotEmpty()) {
                $names = $protected->map(fn (User $account): string => $account->lecturer?->name ?? '#'.$account->lecturer_id)->implode(', ');
                throw ValidationException::withMessages(['confirmation_phrase' => 'Penghapusan dibatalkan. Akun administrator terhubung ke: '.$names.'.']);
            }

            $files = [];
            foreach ($impact['ids'] as $id) {
                $item = $this->preview($type, $id);
                [, $itemFiles] = $this->deleteWithinTransaction($type, $id, $item['name'], $item['token'], $actor);
                array_push($files, ...$itemFiles);
            }
            DB::table('system_audits')->insert([
                'user_id' => $actor->id, 'event' => 'PERMANENT_DELETE_ALL', 'entity_type' => $type,
                'old_values' => json_encode(['counts' => $impact['counts'], 'ids' => $impact['ids']], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return [$impact['counts'], $files];
        }, 3);

        return ['counts' => $counts, 'failed_files' => $this->retryPendingFiles($files)];
    }

    /** @return list<int> */
    private function bulkTargetIds(string $type): array
    {
        abort_unless(in_array($type, ['lecturer', 'supervisor'], true), 404);

        return match ($type) {
            'lecturer' => Lecturer::orderBy('id')->pluck('id')->all(),
            'supervisor' => Lecturer::whereHas('periodSupervisors')->orWhereHas('user', fn ($query) => $query->role('supervisor'))->orderBy('id')->pluck('id')->all(),
        };
    }

    /** @return array{0: array<string, int>, 1: list<object>} */
    private function deleteWithinTransaction(string $type, int $id, string $name, string $token, User $actor): array
    {
        $state = $this->locks->state();
        $impact = $this->preview($type, $id);
        if (! hash_equals($impact['token'], $token) || $impact['name'] !== $name) {
            throw ValidationException::withMessages(['confirmation_name' => 'Data telah berubah. Muat ulang pratinjau sebelum menghapus.']);
        }

        $scheduleIds = $this->scheduleIds($type, $id);
        $occurrenceIds = DB::table('teaching_occurrences')->whereIn('teaching_schedule_id', $scheduleIds)->pluck('id')->all();
        $stageIds = $type === 'period' ? DB::table('supervision_stages')->where('supervision_period_id', $id)->pluck('id')->all() : [];
        $assignmentIds = $this->assignmentIds($type, $id, $occurrenceIds, $stageIds);
        $affectedPeriodIds = $this->affectedPeriodIds($type, $id, $assignmentIds);
        $supervisionIds = DB::table('supervisions')->whereIn('supervision_assignment_id', $assignmentIds)->pluck('id')->all();
        $answerIds = DB::table('supervision_answers')->whereIn('supervision_id', $supervisionIds)->pluck('id')->all();
        $files = DB::table('supervision_evidence')->whereIn('supervision_answer_id', $answerIds)->whereNotNull('file_path')->get(['storage_disk', 'file_path'])->all();
        DB::table('supervision_evidence')->whereIn('supervision_answer_id', $answerIds)->delete();
        DB::table('supervision_answers')->whereIn('supervision_id', $supervisionIds)->delete();
        DB::table('supervisions')->whereIn('id', $supervisionIds)->delete();
        DB::table('assignment_audits')->whereIn('supervision_assignment_id', $assignmentIds)->delete();
        DB::table('supervision_assignments')->whereIn('id', $assignmentIds)->delete();

        $documentQuery = DB::table('generated_documents')->whereIn('supervision_period_id', $affectedPeriodIds);
        if (in_array($type, ['lecturer', 'supervisor'], true)) {
            $documentQuery->orWhere('supervisor_lecturer_id', $id);
        }
        $documentIds = $documentQuery->pluck('id')->all();
        $files = [...$files, ...DB::table('generated_documents')->whereIn('id', $documentIds)->whereNotNull('file_path')->get(['storage_disk', 'file_path'])->all()];
        DB::table('generated_documents')->whereIn('id', $documentIds)->delete();
        DB::table('schedule_runs')->whereIn('supervision_stage_id', DB::table('supervision_stages')->whereIn('supervision_period_id', $affectedPeriodIds)->pluck('id'))->delete();

        if ($type === 'period') {
            DB::table('supervision_periods')->where('id', $id)->update(['active_scheduling_rule_id' => null]);
            DB::table('stage_instruments')->whereIn('supervision_stage_id', $stageIds)->delete();
            DB::table('supervision_stages')->whereIn('id', $stageIds)->delete();
            DB::table('period_supervisors')->where('supervision_period_id', $id)->delete();
            DB::table('period_targets')->where('supervision_period_id', $id)->delete();
            DB::table('scheduling_rules')->where('supervision_period_id', $id)->delete();
            DB::table('supervision_periods')->where('id', $id)->delete();
        } else {
            if ($affectedPeriodIds !== []) {
                DB::table('supervision_stages')->whereIn('supervision_period_id', $affectedPeriodIds)->update(['status' => 'DRAFT', 'finalized_by' => null, 'finalized_at' => null, 'updated_at' => now()]);
            }
            if ($type === 'lecturer') {
                DB::table('period_targets')->where('lecturer_id', $id)->delete();
                DB::table('period_supervisors')->where('lecturer_id', $id)->delete();
            } elseif ($type === 'supervisor') {
                DB::table('period_supervisors')->where('lecturer_id', $id)->delete();
            }
            DB::table('schedule_import_rows')->whereIn('committed_schedule_id', $scheduleIds)->update(['committed_schedule_id' => null]);
            DB::table('teaching_occurrences')->whereIn('id', $occurrenceIds)->delete();
            DB::table('teaching_schedules')->whereIn('id', $scheduleIds)->delete();
            if ($type === 'import') {
                $import = ScheduleImport::findOrFail($id);
                $files[] = (object) ['storage_disk' => $import->storage_disk, 'file_path' => $import->stored_path];
                DB::table('schedule_import_rows')->where('schedule_import_id', $id)->delete();
                DB::table('schedule_imports')->where('id', $id)->delete();
                if (! DB::table('schedule_imports')->where('sha256', $import->sha256)->exists()) {
                    $reference = DB::table('schedule_document_references')->where('sha256', $import->sha256)->first();
                    if ($reference?->stored_path) {
                        $files[] = (object) ['storage_disk' => 'local', 'file_path' => $reference->stored_path];
                    }
                    DB::table('schedule_document_references')->where('sha256', $import->sha256)->delete();
                }
            }
            if ($type === 'lecturer') {
                $lecturer = Lecturer::findOrFail($id);
                DB::table('schedule_import_rows')->where('matched_lecturer_id', $id)->update([
                    'matched_lecturer_id' => null, 'committed_schedule_id' => null,
                    'validation_status' => 'SKIPPED', 'is_approved' => false,
                    'skip_reason' => 'Dosen dihapus permanen oleh superadmin.', 'updated_at' => now(),
                ]);
                $identityKey = $lecturer->external_code
                    ? 'code:'.$lecturer->external_code
                    : 'name:'.hash('sha256', ScheduleImportReferences::normalizeName($lecturer->name));
                DB::table('lecturer_identity_references')->where('identity_key', $identityKey)->delete();
                $this->retireAccount($id, $actor);
                DB::table('lecturers')->where('id', $id)->delete();
            } elseif ($type === 'supervisor') {
                $this->retireAccount($id, $actor);
            }
        }

        $this->locks->bump($state);
        DB::table('system_audits')->insert(['user_id' => $actor->id, 'event' => 'PERMANENT_DELETE', 'entity_type' => $type, 'entity_id' => $id, 'old_values' => json_encode(['name' => $name, 'counts' => $impact['counts']], JSON_THROW_ON_ERROR), 'created_at' => now()]);

        foreach ($files as $file) {
            if ($file->file_path && ! $this->fileStillReferenced($file->storage_disk, $file->file_path)) {
                DB::table('pending_file_deletions')->insertOrIgnore([
                    'storage_disk' => $file->storage_disk,
                    'path_hash' => hash('sha256', $file->file_path),
                    'file_path' => $file->file_path,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        return [$impact['counts'], $files];
    }

    /** @param list<object>|null $files @return list<string> */
    public function retryPendingFiles(?array $files = null): array
    {
        $pending = DB::table('pending_file_deletions')->orderBy('id')->get();
        if ($files !== null) {
            $keys = collect($files)->filter(fn (object $file): bool => (bool) $file->file_path)
                ->map(fn (object $file): string => $file->storage_disk.'|'.hash('sha256', $file->file_path))->all();
            $pending = $pending->filter(fn (object $file): bool => in_array($file->storage_disk.'|'.$file->path_hash, $keys, true));
        }
        $failed = [];
        foreach ($pending as $file) {
            if ($this->fileStillReferenced($file->storage_disk, $file->file_path)) {
                DB::table('pending_file_deletions')->where('id', $file->id)->delete();

                continue;
            }
            try {
                $disk = Storage::disk($file->storage_disk);
                if (! $disk->exists($file->file_path) || $disk->delete($file->file_path)) {
                    DB::table('pending_file_deletions')->where('id', $file->id)->delete();

                    continue;
                }
                DB::table('pending_file_deletions')->where('id', $file->id)->update(['last_error' => 'Storage delete returned false.']);
            } catch (Throwable $exception) {
                Log::error('Permanent deletion file cleanup failed.', ['disk' => $file->storage_disk, 'path' => $file->file_path, 'exception' => $exception]);
                DB::table('pending_file_deletions')->where('id', $file->id)->update(['last_error' => $exception->getMessage()]);
            }
            DB::table('pending_file_deletions')->where('id', $file->id)->increment('attempts', 1, ['updated_at' => now()]);
            if (! in_array($file->file_path, $failed, true)) {
                $failed[] = $file->file_path;
            }
        }

        return $failed;
    }

    /** @return list<int> */
    private function scheduleIds(string $type, int $id): array
    {
        return match ($type) {
            'lecturer' => DB::table('teaching_schedules')->where('lecturer_id', $id)->pluck('id')->all(),
            'schedule' => [$id],
            'import' => DB::table('teaching_schedules')->where('source_import_id', $id)->pluck('id')->all(),
            default => [],
        };
    }

    /** @param list<int> $assignmentIds @return list<int> */
    private function affectedPeriodIds(string $type, int $id, array $assignmentIds): array
    {
        if ($type === 'period') {
            return [$id];
        }
        $ids = DB::table('supervision_stages')->whereIn('id', DB::table('supervision_assignments')->whereIn('id', $assignmentIds)->select('supervision_stage_id'))->pluck('supervision_period_id');
        if ($type === 'lecturer') {
            $ids = $ids->concat(DB::table('period_targets')->where('lecturer_id', $id)->pluck('supervision_period_id'));
        }
        if (in_array($type, ['lecturer', 'supervisor'], true)) {
            $ids = $ids->concat(DB::table('period_supervisors')->where('lecturer_id', $id)->pluck('supervision_period_id'));
        }

        return $ids->unique()->values()->all();
    }

    /** @param list<int> $scheduleIds @return list<object> */
    private function importRowsForToken(string $type, int $id, array $scheduleIds): array
    {
        return DB::table('schedule_import_rows')->where(function ($query) use ($type, $id, $scheduleIds): void {
            if ($type === 'import') {
                $query->where('schedule_import_id', $id);
            } elseif ($type === 'lecturer') {
                $query->where('matched_lecturer_id', $id)->orWhereIn('committed_schedule_id', $scheduleIds);
            } elseif ($type === 'schedule') {
                $query->where('committed_schedule_id', $id);
            } else {
                $query->whereRaw('1 = 0');
            }
        })->orderBy('id')->get(['id', 'row_version', 'updated_at'])->all();
    }

    /** @param list<int> $occurrences @param list<int> $stages @return list<int> */
    private function assignmentIds(string $type, int $id, array $occurrences, array $stages): array
    {
        return DB::table('supervision_assignments')->where(function ($query) use ($type, $id, $occurrences, $stages): void {
            if ($type === 'period') {
                $query->whereIn('supervision_stage_id', $stages);
            } elseif ($type === 'supervisor') {
                $query->where('supervisor_lecturer_id', $id);
            } elseif ($type === 'lecturer') {
                $query->where('target_lecturer_id', $id)->orWhere('supervisor_lecturer_id', $id)->orWhereIn('teaching_occurrence_id', $occurrences);
            } else {
                $query->whereIn('teaching_occurrence_id', $occurrences);
            }
        })->pluck('id')->all();
    }

    private function retireAccount(int $lecturerId, User $actor): void
    {
        $account = User::where('lecturer_id', $lecturerId)->first();
        if (! $account) {
            return;
        }
        if ($account->isLpmStaff() || $account->id === $actor->id) {
            throw ValidationException::withMessages(['confirmation_name' => 'Akun administrator tidak dapat dihapus melalui data dosen atau supervisor.']);
        }
        $retainedBy = [
            ['schedule_imports', 'uploaded_by'],
            ['academic_non_teaching_dates', 'created_by'],
            ['supervision_periods', 'created_by'],
            ['period_targets', 'selected_by'],
            ['period_supervisors', 'selected_by'],
            ['scheduling_rules', 'created_by'],
            ['schedule_runs', 'created_by'],
            ['stage_instruments', 'bound_by'],
            ['generated_documents', 'generated_by'],
            ['supervision_evidence', 'uploaded_by'],
        ];
        foreach ($retainedBy as [$table, $column]) {
            if (DB::table($table)->where($column, $account->id)->exists()) {
                $account->removeRole('supervisor');
                $account->update(['lecturer_id' => null, 'is_active' => false]);
                app(CredentialSessionRevoker::class)->revoke($account);

                return;
            }
        }
        DB::table('sessions')->where('user_id', $account->id)->delete();
        DB::table('password_reset_tokens')->where('email', $account->email)->delete();
        $account->delete();
    }

    private function fileStillReferenced(string $disk, string $path): bool
    {
        return DB::table('schedule_imports')->where('storage_disk', $disk)->where('stored_path', $path)->exists()
            || DB::table('generated_documents')->where('storage_disk', $disk)->where('file_path', $path)->exists()
            || DB::table('supervision_evidence')->where('storage_disk', $disk)->where('file_path', $path)->exists()
            || ($disk === 'local' && DB::table('schedule_document_references')->where('stored_path', $path)->exists());
    }
}
