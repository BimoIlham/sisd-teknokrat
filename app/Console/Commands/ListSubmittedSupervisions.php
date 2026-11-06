<?php

namespace App\Console\Commands;

use App\Models\Supervision;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ListSubmittedSupervisions extends Command
{
    protected $signature = 'supervisions:submitted {--name= : Cari nama dosen yang dinilai atau supervisor} {--limit=20 : Jumlah hasil terbaru, maksimal 100}';

    protected $description = 'Lihat identitas penilaian yang sudah dikirim tanpa mengubah atau mereset data';

    public function handle(): int
    {
        $limit = (string) $this->option('limit');
        if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100) {
            $this->error('Batas hasil harus berupa angka antara 1 dan 100.');

            return self::FAILURE;
        }

        $name = trim((string) $this->option('name'));
        $supervisions = Supervision::query()->where('status', 'SUBMITTED')
            ->when($name !== '', function (Builder $query) use ($name): void {
                $query->where(function (Builder $names) use ($name): void {
                    $names->whereHas('assignment.targetLecturer', fn (Builder $lecturer): Builder => $lecturer->where('name', 'like', '%'.$name.'%'))
                        ->orWhereHas('assignment.supervisorLecturer', fn (Builder $lecturer): Builder => $lecturer->where('name', 'like', '%'.$name.'%'));
                });
            })
            ->with(['assignment.targetLecturer', 'assignment.supervisorLecturer', 'assignment.supervisionStage.period', 'course'])
            ->withCount('answers')
            ->orderByDesc('submitted_at')->orderByDesc('id')->limit((int) $limit)->get();

        if ($supervisions->isEmpty()) {
            $this->info('Tidak ada penilaian yang sudah disubmit sesuai pencarian.');

            return self::SUCCESS;
        }

        foreach ($supervisions as $supervision) {
            $assignment = $supervision->assignment;
            $stage = $assignment?->supervisionStage;
            $score = $supervision->final_score !== null ? number_format((float) $supervision->final_score, 2) : '-';
            $submittedAt = $supervision->submitted_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB';
            $courseName = $supervision->course_name_override ?? $supervision->course?->name ?? '-';

            $this->newLine();
            $this->line('ID penilaian: '.$supervision->id);
            $this->line('Dosen dinilai: '.($assignment?->targetLecturer?->name ?? '-'));
            $this->line('Supervisor: '.($assignment?->supervisorLecturer?->name ?? '-'));
            $this->line('Periode: '.($stage?->period?->name ?? '-').' | Tahap: '.($stage?->stage_no ?? '-'));
            $this->line('Mata kuliah: '.$courseName.' | Mode: '.($supervision->delivery_mode ?? '-'));
            $this->line('Nilai akhir: '.$score.' | Baris jawaban: '.$supervision->answers_count);
            $this->line('Dikirim: '.($supervision->submitted_at ? $submittedAt : '-'));
        }

        $this->newLine();
        $this->info('Menampilkan '.$supervisions->count().' hasil submit terbaru. Pemeriksaan saja; tidak ada data yang diubah.');

        return self::SUCCESS;
    }
}
