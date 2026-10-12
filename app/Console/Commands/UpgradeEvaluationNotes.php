<?php

namespace App\Console\Commands;

use App\Models\FormTemplate;
use App\Models\SystemAudit;
use App\Models\User;
use App\Services\InstrumentVersionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

#[Signature('instruments:upgrade-evaluation-notes {--admin= : ID administrator yang menerbitkan versi instrumen baru}')]
#[Description('Terbitkan versi instrumen baru dengan tiga kolom catatan evaluasi tanpa mengubah instrumen historis')]
class UpgradeEvaluationNotes extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $admin = User::find((int) $this->option('admin'));
        if (! $admin || ! $admin->is_active || ! $admin->hasRole('superadmin')) {
            $this->error('Tentukan administrator aktif melalui --admin.');

            return self::FAILURE;
        }

        Auth::setUser($admin);
        $count = DB::transaction(function () use ($admin): int {
            $templates = FormTemplate::where('status', 'PUBLISHED')->orderByDesc('version')->get()->unique('code');
            $count = 0;
            $versions = app(InstrumentVersionService::class);
            foreach ($templates as $template) {
                $questions = $template->questions()->where('question_type', 'TEXTAREA')->get()
                    ->filter(fn ($question): bool => preg_match('/evaluasi|kritik|saran|catatan|kesimpulan/iu', $question->question_text) === 1);
                if ($questions->isEmpty() || $questions->every(fn ($question): bool => $question->usesStructuredEvaluation())) {
                    continue;
                }

                $copy = $versions->duplicate($template);
                $copy = $versions->lockEditable($copy->id);
                foreach ($copy->questions()->whereIn('code', $questions->pluck('code'))->get() as $question) {
                    $question->update(['validation_rules' => array_replace($question->validation_rules ?? [], ['evaluation_notes' => true])]);
                }
                $copy->update([
                    'status' => 'PUBLISHED', 'published_by' => $admin->id,
                    'published_at' => now(), 'row_version' => $copy->row_version + 1,
                ]);
                SystemAudit::create([
                    'user_id' => $admin->id, 'event' => 'INSTRUMENT_PUBLISHED',
                    'entity_type' => FormTemplate::class, 'entity_id' => $copy->id,
                    'new_values' => ['version' => $copy->version, 'evaluation_notes' => true],
                ]);
                $count++;
            }

            return $count;
        }, 3);
        $this->info("{$count} versi instrumen baru diterbitkan.");

        return self::SUCCESS;
    }
}
