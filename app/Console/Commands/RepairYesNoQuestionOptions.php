<?php

namespace App\Console\Commands;

use App\Models\FormTemplate;
use App\Models\Question;
use App\Services\InstrumentEditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RepairYesNoQuestionOptions extends Command
{
    protected $signature = 'instruments:repair-yes-no {--question= : ID database pertanyaan tertentu} {--apply : Terapkan perbaikan pada pertanyaan tersebut}';

    protected $description = 'Periksa pilihan Ya/Tidak dan perbaiki instrumen yang belum digunakan untuk penilaian';

    public function handle(InstrumentEditService $instruments): int
    {
        $questionId = $this->option('question');
        if (($questionId !== null && (! ctype_digit((string) $questionId) || (int) $questionId < 1))
            || ($this->option('apply') && $questionId === null)) {
            $this->error('Gunakan --question=ID dengan ID database pertanyaan yang akan diperbaiki.');

            return self::FAILURE;
        }

        $questions = Question::with(['options', 'template'])
            ->where('question_type', 'YES_NO')
            ->when($questionId !== null, fn ($query) => $query->whereKey((int) $questionId))
            ->orderBy('id')->get();
        if ($questionId !== null && $questions->isEmpty()) {
            $this->error('Pertanyaan Ya/Tidak dengan ID tersebut tidak ditemukan.');

            return self::FAILURE;
        }

        $invalidQuestions = $questions->reject(fn (Question $question): bool => $question->hasValidYesNoOptions());
        if ($invalidQuestions->isEmpty()) {
            $this->info('Tidak ada pilihan Ya/Tidak yang perlu diperbaiki.');

            return self::SUCCESS;
        }

        $this->table(['ID pertanyaan', 'ID instrumen', 'Kode', 'Pertanyaan', 'Pilihan saat ini', 'Status'],
            $invalidQuestions->map(function (Question $question) use ($instruments): array {
                $currentTemplateId = FormTemplate::where('delivery_mode', $question->template->delivery_mode)
                    ->where('status', 'PUBLISHED')->orderByDesc('created_at')->orderByDesc('id')->value('id');
                $status = $question->template->id !== $currentTemplateId ? 'Instrumen lama' : 'Dapat diperbaiki';
                if ($instruments->hasStartedSupervision($question->form_template_id) || $question->answers()->exists()) {
                    $status = 'Sudah digunakan; perlu peninjauan';
                }

                return [$question->id, $question->form_template_id, $question->code, $question->question_text,
                    $question->options->pluck('label')->implode(' / '), $status];
            })->all());

        if (! $this->option('apply')) {
            $this->info('Pratinjau saja. Gunakan --question=ID --apply untuk memperbaiki satu pertanyaan.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($questionId, $instruments): void {
                $question = Question::findOrFail((int) $questionId);
                $instruments->lockEditable($question->form_template_id);
                $question->refresh();
                if ($question->question_type !== 'YES_NO' || $question->answers()->exists()) {
                    throw ValidationException::withMessages(['instrument' => 'Pertanyaan berubah atau sudah memiliki jawaban; perbaikan dibatalkan.']);
                }
                if ($question->hasValidYesNoOptions()) {
                    return;
                }
                $question->options()->delete();
                $question->options()->createMany(Question::defaultYesNoOptions());
            }, 3);
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->info('Pilihan pertanyaan '.$questionId.' berhasil diperbaiki menjadi Ya/Tidak.');

        return self::SUCCESS;
    }
}
