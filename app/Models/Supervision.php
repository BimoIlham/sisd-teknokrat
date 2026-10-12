<?php

namespace App\Models;

use App\Services\Scheduling\SchedulingLockService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Supervision extends Model
{
    protected $fillable = [
        'supervision_assignment_id',
        'stage_instrument_id',
        'form_template_id',
        'scoring_policy_id',
        'status',
        'supervision_date',
        'starts_at',
        'ends_at',
        'delivery_mode',
        'course_id',
        'course_name_override',
        'course_override_reason',
        'material_topic',
        'identity_snapshot',
        'raw_score',
        'scored_question_count',
        'final_score',
        'submission_snapshot',
        'submitted_by',
        'submitted_at',
        'row_version',
    ];

    protected $casts = [
        'supervision_date' => 'date',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'identity_snapshot' => 'array',
        'raw_score' => 'decimal:4',
        'scored_question_count' => 'integer',
        'final_score' => 'decimal:6',
        'submission_snapshot' => 'array',
        'submitted_at' => 'datetime',
        'row_version' => 'integer',
    ];

    /** Keep the template lock held until the new supervision has been inserted. */
    public function save(array $options = []): bool
    {
        if (! $this->exists) {
            return DB::transaction(function () use ($options): bool {
                app(SchedulingLockService::class)->state();

                return parent::save($options);
            });
        }

        if ($this->isDirty('form_template_id')) {
            throw ValidationException::withMessages(['instrument' => 'Instrumen penilaian yang sudah dibuat tidak dapat diganti.']);
        }

        return parent::save($options);
    }

    protected static function booted(): void
    {
        static::creating(function ($supervision) {
            if ($supervision->supervision_assignment_id) {
                $assignment = SupervisionAssignment::with(['occurrence.schedule.course', 'stage.instruments'])->find($supervision->supervision_assignment_id);
                if ($assignment) {
                    $occ = $assignment->occurrence;
                    if ($occ && ! $supervision->supervision_date) {
                        $supervision->supervision_date = $occ->occurs_on;
                        $supervision->starts_at = $occ->starts_at;
                        $supervision->ends_at = $occ->ends_at;
                        $supervision->delivery_mode = $occ->schedule->delivery_mode ?? 'OFFLINE';
                        $supervision->course_id = $supervision->course_id ?: ($occ->schedule->course_id ?? Course::first()?->id);
                    }
                    if (! $supervision->stage_instrument_id) {
                        $stageInst = StageInstrument::where('supervision_stage_id', $assignment->supervision_stage_id)
                            ->where('delivery_mode', $supervision->delivery_mode ?? 'OFFLINE')
                            ->first() ?? StageInstrument::where('supervision_stage_id', $assignment->supervision_stage_id)->first();
                        if ($stageInst) {
                            $supervision->stage_instrument_id = $stageInst->id;
                            $supervision->form_template_id = $supervision->form_template_id ?: $stageInst->form_template_id;
                            $supervision->scoring_policy_id = $supervision->scoring_policy_id ?: $stageInst->scoring_policy_id;
                        }
                    }
                }
            }
            if (! $supervision->supervision_date) {
                $supervision->supervision_date = now()->toDateString();
                $supervision->starts_at = now();
                $supervision->ends_at = now()->addMinutes(100);
                $supervision->delivery_mode = 'OFFLINE';
            }
            if (! $supervision->course_id) {
                $supervision->course_id = Course::first()?->id;
            }
            if (! $supervision->stage_instrument_id) {
                $supervision->stage_instrument_id = StageInstrument::first()?->id;
            }
            FormTemplate::query()->lockForUpdate()->findOrFail($supervision->form_template_id);
        });
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SupervisionAssignment::class, 'supervision_assignment_id');
    }

    public function stageInstrument(): BelongsTo
    {
        return $this->belongsTo(StageInstrument::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_template_id');
    }

    public function formTemplate(): BelongsTo
    {
        return $this->template();
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ScoringPolicy::class, 'scoring_policy_id');
    }

    public function scoringPolicy(): BelongsTo
    {
        return $this->policy();
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SupervisionAnswer::class);
    }

    public function evidence(): HasManyThrough
    {
        return $this->hasManyThrough(SupervisionEvidence::class, SupervisionAnswer::class);
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'SUBMITTED';
    }

    public function getSnapshotJsonAttribute()
    {
        return $this->submission_snapshot;
    }

    public function setSnapshotJsonAttribute($val): void
    {
        $this->submission_snapshot = $val;
    }
}
