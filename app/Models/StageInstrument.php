<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StageInstrument extends Model
{
    protected $fillable = [
        'supervision_stage_id',
        'delivery_mode',
        'form_template_id',
        'scoring_policy_id',
        'bound_by',
    ];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(SupervisionStage::class, 'supervision_stage_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_template_id');
    }

    public function formTemplate(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_template_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ScoringPolicy::class, 'scoring_policy_id');
    }

    public function scoringPolicy(): BelongsTo
    {
        return $this->belongsTo(ScoringPolicy::class, 'scoring_policy_id');
    }

    public function binder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bound_by');
    }

    public function supervisions(): HasMany
    {
        return $this->hasMany(Supervision::class);
    }
}
