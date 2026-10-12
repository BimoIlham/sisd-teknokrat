<?php

namespace App\Models;

use App\Services\Scheduling\SchedulingInputSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ScheduleRun extends Model
{
    protected $fillable = [
        'request_id',
        'supervision_stage_id',
        'scheduling_rule_id',
        'input_revision',
        'input_hash',
        'input_payload',
        'status',
        'solver_status',
        'coverage_status',
        'result_payload',
        'diagnostics',
        'solver_version',
        'seed',
        'time_limit_seconds',
        'started_at',
        'finished_at',
        'applied_at',
        'created_by',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'input_revision' => 'integer',
        'input_payload' => 'array',
        'result_payload' => 'array',
        'diagnostics' => 'array',
        'seed' => 'integer',
        'time_limit_seconds' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($run) {
            if (! $run->request_id) {
                $run->request_id = (string) Str::uuid();
            }
            $run->prepareSchedulingRule();
            if (! $run->input_revision) {
                $run->input_revision = SchedulingState::query()->find(1)?->revision ?? 1;
            }
            if (! $run->input_hash) {
                $run->input_hash = app(SchedulingInputSnapshot::class)->hash($run->input_payload ?? []);
            }
            if (! $run->input_payload) {
                $run->input_payload = [];
            }
            if (! $run->created_by) {
                $run->created_by = auth()->id() ?? User::first()?->id ?? 1;
            }
        });
    }

    /**
     * Prepare the existing default rule before capturing solver inputs.
     * The solver calls this under the state/period locks; creating retains legacy model compatibility.
     */
    public function prepareSchedulingRule(): void
    {
        if (! $this->scheduling_rule_id) {
            $this->scheduling_rule_id = $this->stage?->period?->active_scheduling_rule_id
                ?? SchedulingRule::where('supervision_period_id', $this->stage?->supervision_period_id)->first()?->id
                ?? SchedulingRule::first()?->id;

            if (! $this->scheduling_rule_id) {
                $period = $this->stage?->period ?? SupervisionPeriod::first();
                if ($period) {
                    $rule = SchedulingRule::firstOrCreate(
                        ['supervision_period_id' => $period->id, 'version' => 1],
                        [
                            'schema_version' => '1.0',
                            'config' => [
                                'schema_version' => '1.0',
                                'block_supervision_when_teaching_count_gte' => 3,
                                'buffer_minutes' => 0,
                                'max_supervisions_per_day' => null,
                                'max_supervisions_per_week' => null,
                                'max_daily_span_minutes' => null,
                                'cross_unit_policy' => 'ANY',
                                'stage_supervisor_policy' => 'ANY',
                                'min_stage_gap_days' => 0,
                                'one_observation_per_target_per_stage' => true,
                                'require_t2_after_t1' => true,
                            ],
                            'status' => 'APPROVED',
                            'is_demo' => false,
                            'created_by' => $this->created_by ?? auth()->id() ?? 1,
                            'approved_by' => $this->created_by ?? auth()->id() ?? 1,
                            'approved_at' => now(),
                            'approval_note' => 'Aturan jadwal default otomatis',
                        ]
                    );
                    $period->update(['active_scheduling_rule_id' => $rule->id]);
                    $this->scheduling_rule_id = $rule->id;
                }
            }
        }
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(SupervisionStage::class, 'supervision_stage_id');
    }

    public function supervisionStage(): BelongsTo
    {
        return $this->stage();
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(SchedulingRule::class, 'scheduling_rule_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SupervisionAssignment::class);
    }

    public function getProposedAssignmentsAttribute(): array
    {
        return $this->result_payload['assignments'] ?? [];
    }

    public function setProposedAssignmentsAttribute($val): void
    {
        $payload = $this->result_payload ?? [];
        $payload['assignments'] = $val;
        $this->result_payload = $payload;
    }

    public function getDiagnosticsJsonAttribute(): array
    {
        return $this->diagnostics ?? [];
    }

    public function setDiagnosticsJsonAttribute($val): void
    {
        $this->diagnostics = $val;
    }

    public function getTargetCoverageRateAttribute(): float
    {
        return (float) ($this->result_payload['coverage_rate'] ?? 0.0);
    }

    public function setTargetCoverageRateAttribute($val): void
    {
        $payload = $this->result_payload ?? [];
        $payload['coverage_rate'] = $val;
        $this->result_payload = $payload;
    }

    public function getExecutionDurationMsAttribute(): int
    {
        return (int) ($this->result_payload['duration_ms'] ?? 0);
    }

    public function setExecutionDurationMsAttribute($val): void
    {
        $payload = $this->result_payload ?? [];
        $payload['duration_ms'] = (int) $val;
        $this->result_payload = $payload;
    }

    /**
     * run_type is not a database column — silently absorb writes.
     */
    public function setRunTypeAttribute($val): void
    {
        // Not persisted; kept for API compatibility with SolverClientService.
    }

    /**
     * created_by_user_id alias for created_by.
     */
    public function setCreatedByUserIdAttribute($val): void
    {
        $this->attributes['created_by'] = $val;
    }

    public function getCompletedAtAttribute()
    {
        return $this->finished_at;
    }

    public function setCompletedAtAttribute($val): void
    {
        $this->finished_at = $val;
    }
}
