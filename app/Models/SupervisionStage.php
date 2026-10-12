<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupervisionStage extends Model
{
    protected $fillable = [
        'supervision_period_id',
        'stage_no',
        'name',
        'starts_on',
        'ends_on',
        'status',
        'schedule_revision',
        'finalized_by',
        'finalized_at',
        'row_version',
    ];

    protected $casts = [
        'stage_no' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'schedule_revision' => 'integer',
        'finalized_at' => 'datetime',
        'row_version' => 'integer',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(SupervisionPeriod::class, 'supervision_period_id');
    }

    public function supervisionPeriod(): BelongsTo
    {
        return $this->period();
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SupervisionAssignment::class);
    }

    public function instruments(): HasMany
    {
        return $this->hasMany(StageInstrument::class);
    }

    public function instrument()
    {
        return $this->hasOne(StageInstrument::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ScheduleRun::class);
    }

    public function isFinalized(): bool
    {
        return $this->status === 'FINALIZED';
    }

    public function scopeInputWindowOpen(Builder $query, ?CarbonInterface $date = null): Builder
    {
        $dateString = ($date ?? now('Asia/Jakarta'))->toDateString();

        return $query
            ->whereNotNull('starts_on')
            ->whereNotNull('ends_on')
            ->whereDate('starts_on', '<=', $dateString)
            ->whereDate('ends_on', '>=', $dateString)
            ->whereHas('period', fn (Builder $periodQuery) => $periodQuery->where('status', 'ACTIVE'));
    }

    public function supervisionInputStatus(?CarbonInterface $date = null): string
    {
        $this->loadMissing('period');

        if ($this->period?->status !== 'ACTIVE') {
            return 'period_inactive';
        }

        if (! $this->starts_on || ! $this->ends_on) {
            return 'dates_unconfigured';
        }

        $dateString = ($date ?? now('Asia/Jakarta'))->toDateString();

        if ($dateString < $this->starts_on->toDateString()) {
            return 'not_started';
        }

        if ($dateString > $this->ends_on->toDateString()) {
            return 'ended';
        }

        return 'open';
    }

    public function acceptsSupervisionInput(?CarbonInterface $date = null): bool
    {
        return $this->supervisionInputStatus($date) === 'open';
    }

    public function supervisionInputUnavailableMessage(?CarbonInterface $date = null): ?string
    {
        return match ($this->supervisionInputStatus($date)) {
            'period_inactive' => 'Periode supervisi ini tidak aktif, sehingga input supervisi ditutup.',
            'dates_unconfigured' => 'Tanggal mulai dan akhir input untuk tahap ini belum diatur.',
            'not_started' => 'Input supervisi tahap ini dibuka pada '.$this->starts_on->format('d/m/Y').'.',
            'ended' => 'Masa input supervisi berakhir pada '.$this->ends_on->format('d/m/Y').'.',
            default => null,
        };
    }

    public function getStageOrderAttribute(): int
    {
        return (int) $this->stage_no;
    }

    public function getStartDateAttribute()
    {
        return $this->starts_on;
    }

    public function getEndDateAttribute()
    {
        return $this->ends_on;
    }

    public function getIsFinalizedAttribute(): bool
    {
        return $this->status === 'FINALIZED';
    }

    public function setIsFinalizedAttribute($value): void
    {
        $this->attributes['status'] = $value ? 'FINALIZED' : 'DRAFT';
    }
}
