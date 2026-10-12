<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupervisionAssignment extends Model
{
    protected $fillable = [
        'supervision_stage_id',
        'target_lecturer_id',
        'supervisor_lecturer_id',
        'teaching_occurrence_id',
        'starts_at',
        'ends_at',
        'assignment_type',
        'is_locked',
        'status',
        'conflict_status',
        'conflict_codes',
        'conflict_details',
        'scheduling_rule_id',
        'schedule_run_id',
        'input_revision',
        'identity_snapshot',
        'assigned_by',
        'assigned_at',
        'change_reason',
        'row_version',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_locked' => 'boolean',
        'conflict_codes' => 'array',
        'conflict_details' => 'array',
        'identity_snapshot' => 'array',
        'input_revision' => 'integer',
        'assigned_at' => 'datetime',
        'row_version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function ($assignment) {
            if ($assignment->teaching_occurrence_id && (! $assignment->starts_at || ! $assignment->ends_at)) {
                $occ = TeachingOccurrence::find($assignment->teaching_occurrence_id);
                if ($occ) {
                    $assignment->starts_at = $occ->starts_at;
                    $assignment->ends_at = $occ->ends_at;
                }
            }
            if (! $assignment->scheduling_rule_id) {
                $assignment->scheduling_rule_id = $assignment->stage?->period?->active_scheduling_rule_id
                    ?? SchedulingRule::where('supervision_period_id', $assignment->stage?->supervision_period_id)->first()?->id
                    ?? SchedulingRule::first()?->id;

                if (! $assignment->scheduling_rule_id) {
                    $period = $assignment->stage?->period ?? SupervisionPeriod::first();
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
                                'created_by' => auth()->id() ?? 1,
                                'approved_by' => auth()->id() ?? 1,
                                'approved_at' => now(),
                                'approval_note' => 'Aturan jadwal default otomatis',
                            ]
                        );
                        $period->update(['active_scheduling_rule_id' => $rule->id]);
                        $assignment->scheduling_rule_id = $rule->id;
                    }
                }
            }
            if (! $assignment->input_revision) {
                $assignment->input_revision = 1;
            }
        });
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(SupervisionStage::class, 'supervision_stage_id');
    }

    public function supervisionStage(): BelongsTo
    {
        return $this->stage();
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Lecturer::class, 'target_lecturer_id');
    }

    public function targetLecturer(): BelongsTo
    {
        return $this->target();
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Lecturer::class, 'supervisor_lecturer_id');
    }

    public function supervisorLecturer(): BelongsTo
    {
        return $this->supervisor();
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(TeachingOccurrence::class, 'teaching_occurrence_id');
    }

    public function teachingOccurrence(): BelongsTo
    {
        return $this->occurrence();
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(SchedulingRule::class, 'scheduling_rule_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ScheduleRun::class, 'schedule_run_id');
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function supervision(): HasOne
    {
        return $this->hasOne(Supervision::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(AssignmentAudit::class);
    }

    public function isFinal(): bool
    {
        return $this->status === 'FINAL';
    }

    public function isManual(): bool
    {
        return $this->assignment_type === 'MANUAL';
    }
}
