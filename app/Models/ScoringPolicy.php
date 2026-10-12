<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScoringPolicy extends Model
{
    protected $fillable = [
        'code',
        'version',
        'strategy',
        'config',
        'status',
        'is_demo',
        'approved_by',
        'approved_at',
        'approval_note',
    ];

    protected $casts = [
        'version' => 'integer',
        'config' => 'array',
        'is_demo' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function stageInstruments(): HasMany
    {
        return $this->hasMany(StageInstrument::class);
    }

    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }

    public function getFormulaCodeAttribute(): string
    {
        return match ($this->strategy) {
            'MEAN_0_4' => 'Rata-rata 0–4',
            'LINEAR_1_4' => 'Linier 1–4',
            'FLOOR_1_4' => 'Floor 1–4',
            default => 'Standar',
        };
    }

    public function getNameAttribute(): string
    {
        return match (true) {
            $this->strategy === 'MEAN_0_4' || str_contains($this->code, 'MEAN') => 'Rata-Rata Standar Mutu (Skala 0–4)',
            $this->strategy === 'LINEAR_1_4' || str_contains($this->code, 'LINEAR') => 'Konversi Linier Terstandarisasi (Skala 1–4)',
            $this->strategy === 'FLOOR_1_4' || str_contains($this->code, 'FLOOR') => 'Ambang Batas Minimum (Skala 1–4)',
            default => (string) str($this->code)->replace(['DEMO_POLICY_', 'POLICY_', '_'], ['', '', ' '])->title(),
        };
    }

    public function getDescriptionAttribute(): string
    {
        return $this->config['description'] ?? $this->strategy;
    }

    public function getMinScoreAttribute(): float
    {
        return (float) ($this->config['min_score'] ?? 0.0);
    }

    public function getMaxScoreAttribute(): float
    {
        return (float) ($this->config['max_score'] ?? 4.0);
    }

    public function getHumanTitleAttribute(): string
    {
        return match (true) {
            $this->strategy === 'MEAN_0_4' || str_contains($this->code, 'MEAN') => 'Rata-Rata Standar Mutu (Mean)',
            $this->strategy === 'LINEAR_1_4' || str_contains($this->code, 'LINEAR') => 'Konversi Linier Terstandarisasi',
            $this->strategy === 'FLOOR_1_4' || str_contains($this->code, 'FLOOR') => 'Ambang Batas Minimum (Floor)',
            default => (string) str($this->code)->replace(['DEMO_POLICY_', 'POLICY_', '_'], ['', '', ' '])->title(),
        };
    }

    public function getHumanDescriptionAttribute(): string
    {
        return match (true) {
            $this->strategy === 'MEAN_0_4' || str_contains($this->code, 'MEAN') => 'Menghitung nilai akhir supervisi mutu perkuliahan berdasarkan akumulasi rata-rata seluruh butir pertanyaan berskor dengan presisi 2 desimal.',
            $this->strategy === 'LINEAR_1_4' || str_contains($this->code, 'LINEAR') => 'Melakukan pemetaan linier proporsional ke rentang 1.00 s/d 4.00 untuk kebutuhan pelaporan akreditasi terstandarisasi.',
            $this->strategy === 'FLOOR_1_4' || str_contains($this->code, 'FLOOR') => 'Menerapkan batas bawah nilai kelayakan minimum bagi standar pengajaran dosen.',
            default => $this->approval_note ?: 'Formula perhitungan evaluasi mutu pengajaran dosen.',
        };
    }

    public function getHumanFormulaAttribute(): string
    {
        return match (true) {
            $this->strategy === 'MEAN_0_4' || str_contains($this->code, 'MEAN') => 'Total Skor Butir ÷ Jumlah Butir',
            $this->strategy === 'LINEAR_1_4' || str_contains($this->code, 'LINEAR') => '1.0 + (Skor Rata-rata × 0.75)',
            $this->strategy === 'FLOOR_1_4' || str_contains($this->code, 'FLOOR') => 'Max(Skor Rata-rata, Batas Bawah 1.0)',
            default => $this->strategy,
        };
    }
}
