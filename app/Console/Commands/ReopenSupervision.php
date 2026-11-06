<?php

namespace App\Console\Commands;

use App\Models\Supervision;
use App\Models\SupervisionAnswer;
use App\Models\SupervisionEvidence;
use App\Models\SystemAudit;
use App\Services\Scheduling\SchedulingLockService;
use App\Services\Supervision\SupervisionAccessService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReopenSupervision extends Command
{
    protected $signature = 'supervisions:reopen {supervision : ID penilaian yang akan dibuka kembali} {--reason= : Alasan pembukaan kembali} {--apply : Buka kembali satu penilaian menjadi draf}';

    protected $description = 'Buka kembali satu hasil submit dengan menyimpan riwayat, jawaban, dan bukti';

    /**
     * Recovery is available to server operators through Artisan only.
     * Console actions use a null audit user rather than impersonating the supervisor.
     */
    public function handle(SchedulingLockService $locks, SupervisionAccessService $access): int
    {
        $supervisionId = (string) $this->argument('supervision');
        if (! ctype_digit($supervisionId) || (int) $supervisionId < 1) {
            $this->error('ID penilaian harus berupa angka positif.');

            return self::FAILURE;
        }

        try {
            $reason = trim((string) $this->option('reason'));
            if ($this->option('apply')) {
                Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:1000']], [
                    'reason.required' => 'Isi alasan pembukaan kembali melalui --reason="Salah submit".',
                ])->validate();
            }

            $supervision = Supervision::with(['assignment.targetLecturer', 'assignment.supervisorLecturer'])
                ->withCount(['answers', 'evidence'])->findOrFail((int) $supervisionId);
            $this->line('ID penilaian: '.$supervision->id);
            $this->line('Dosen dinilai: '.($supervision->assignment?->targetLecturer?->name ?? '-'));
            $this->line('Supervisor: '.($supervision->assignment?->supervisorLecturer?->name ?? '-'));
            $this->line('Status saat ini: '.$supervision->status.' | Nilai akhir: '.($supervision->final_score ?? '-'));
            $this->line('Jawaban: '.$supervision->answers_count.' | Bukti: '.$supervision->evidence_count);
            $this->assertReopenable($supervision, $access);

            if (! $this->option('apply')) {
                $this->info('Pratinjau saja. Tambahkan --reason="Salah submit" --apply untuk membuka kembali penilaian ini.');

                return self::SUCCESS;
            }

            $expectedVersion = $supervision->row_version;
            DB::transaction(function () use ($supervisionId, $expectedVersion, $reason, $locks, $access): void {
                $supervision = $locks->supervision((int) $supervisionId);
                if ($supervision->row_version !== $expectedVersion) {
                    throw ValidationException::withMessages(['supervision' => 'Penilaian telah berubah. Jalankan pemeriksaan kembali.']);
                }
                $this->assertReopenable($supervision, $access);
                $supervision->load('answers.evidence');
                $previousValues = $supervision->only([
                    'status', 'raw_score', 'scored_question_count', 'final_score', 'submitted_by', 'submitted_at',
                    'submission_snapshot', 'row_version', 'draft_started_at', 'form_template_id', 'scoring_policy_id',
                ]);
                $previousValues['answers'] = $supervision->answers->map(fn (SupervisionAnswer $answer): array => $answer->only([
                    'id', 'question_id', 'question_option_id', 'numeric_score', 'boolean_value', 'text_value',
                    'notes', 'evaluation_notes', 'is_applicable', 'answer_snapshot',
                ]))->values()->all();
                $previousValues['evidence'] = $supervision->answers->flatMap->evidence
                    ->map(fn (SupervisionEvidence $evidence): array => $evidence->only([
                        'id', 'supervision_answer_id', 'original_filename', 'mime_type', 'size_bytes', 'sha256', 'status',
                    ]))->values()->all();

                $supervision->update([
                    'status' => 'DRAFT', 'raw_score' => null, 'scored_question_count' => null, 'final_score' => null,
                    'submitted_by' => null, 'submitted_at' => null, 'submission_snapshot' => null,
                    'draft_started_at' => $supervision->draft_started_at ?? $supervision->submitted_at ?? now(),
                    'row_version' => $supervision->row_version + 1,
                ]);

                SystemAudit::create([
                    'user_id' => null, 'event' => 'REOPEN_SUPERVISION',
                    'entity_type' => Supervision::class, 'entity_id' => $supervision->id,
                    'reason' => $reason, 'user_agent' => 'Artisan: supervisions:reopen',
                    'old_values' => $previousValues,
                    'new_values' => $supervision->only([
                        'status', 'raw_score', 'scored_question_count', 'final_score', 'submitted_by',
                        'submitted_at', 'submission_snapshot', 'draft_started_at', 'row_version',
                    ]),
                ]);
            }, 3);
        } catch (ModelNotFoundException) {
            $this->error('Penilaian atau penugasannya tidak ditemukan. Tidak ada perubahan.');

            return self::FAILURE;
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->info('Penilaian '.$supervisionId.' berhasil dibuka kembali menjadi DRAFT. Jawaban dan bukti tetap tersimpan.');

        return self::SUCCESS;
    }

    private function assertReopenable(Supervision $supervision, SupervisionAccessService $access): void
    {
        if ($supervision->status !== 'SUBMITTED') {
            throw ValidationException::withMessages(['supervision' => 'Hanya penilaian berstatus SUBMITTED yang dapat dibuka kembali.']);
        }
        $supervision->loadMissing('assignment.supervisionStage.period', 'assignment.supervisorLecturer.user');
        $assignment = $supervision->assignment;
        $stage = $assignment?->supervisionStage;
        if (! $assignment || ! $stage) {
            throw ValidationException::withMessages(['supervision' => 'Penugasan atau tahap penilaian tidak tersedia.']);
        }
        if (! $stage->acceptsSupervisionInput()) {
            throw ValidationException::withMessages(['supervision' => $stage->supervisionInputUnavailableMessage()]);
        }
        $supervisorUser = $assignment->supervisorLecturer?->user;
        if (! $supervisorUser || ! $access->canExecute($supervisorUser, $assignment)) {
            throw ValidationException::withMessages(['supervision' => 'Supervisor belum memiliki akses pengisian pada penugasan ini.']);
        }
        $hasLaterSubmission = Supervision::where('status', 'SUBMITTED')
            ->whereHas('assignment', fn (Builder $query): Builder => $query
                ->where('target_lecturer_id', $assignment->target_lecturer_id)
                ->whereHas('supervisionStage', fn (Builder $stageQuery): Builder => $stageQuery
                    ->where('supervision_period_id', $stage->supervision_period_id)
                    ->where('stage_no', '>', $stage->stage_no)))
            ->exists();
        if ($hasLaterSubmission) {
            throw ValidationException::withMessages(['supervision' => 'Dosen ini sudah memiliki hasil final pada tahap berikutnya. Tinjau hasil terkait sebelum membuka kembali.']);
        }
    }
}
