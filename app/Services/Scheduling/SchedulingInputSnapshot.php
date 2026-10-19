<?php

namespace App\Services\Scheduling;

use App\Models\SupervisionStage;
use Illuminate\Support\Facades\DB;

class SchedulingInputSnapshot
{
    /**
     * Capture source data, including availability that can invalidate previously valid candidates.
     * Timestamps, random fingerprints, request IDs, and credentials are deliberately excluded.
     *
     * @return array<string, mixed>
     */
    public function capture(SupervisionStage $stage): array
    {
        $period = $stage->supervisionPeriod()->with('academicTerm', 'stages')->firstOrFail();
        $targets = DB::table('period_targets')->where('supervision_period_id', $period->id)
            ->orderBy('lecturer_id')->get(['lecturer_id', 'scope_status']);
        $supervisors = DB::table('period_supervisors')->where('supervision_period_id', $period->id)
            ->orderBy('lecturer_id')->get(['lecturer_id', 'is_active']);
        $lecturerIds = $targets->pluck('lecturer_id')->merge($supervisors->pluck('lecturer_id'))->unique();
        $schedules = DB::table('teaching_schedules')
            ->where('supervision_period_id', $period->id)
            ->orderBy('id')->get(['id', 'academic_term_id', 'lecturer_id', 'course_id', 'faculty_id',
                'study_program_id', 'class_name', 'room_name', 'delivery_mode', 'day_of_week',
                'starts_local', 'ends_local', 'effective_from', 'effective_to', 'is_active']);
        $rule = DB::table('scheduling_rules')->where('id', $period->active_scheduling_rule_id)
            ->first(['id', 'version', 'schema_version', 'config', 'status']);
        if ($rule) {
            $rule->config = json_decode($rule->config, true, 512, JSON_THROW_ON_ERROR);
        }

        return [
            'version' => 2,
            'selection_policy' => 'offline_first_v1',
            'period' => $period->only(['id', 'academic_term_id', 'status', 'starts_on', 'ends_on', 'active_scheduling_rule_id']),
            'term' => $period->academicTerm->only(['id', 'starts_on', 'ends_on', 'timezone']),
            'stage_id' => $stage->id,
            'stages' => $period->stages->sortBy('id')->map(fn (SupervisionStage $item): array => $item->only(['id', 'stage_no', 'starts_on', 'ends_on', 'status']))->values()->all(),
            'rule' => $rule,
            'targets' => $targets->all(),
            'supervisors' => $supervisors->all(),
            'lecturers' => DB::table('lecturers')->whereIn('id', $lecturerIds)->orderBy('id')
                ->get(['id', 'name', 'external_code', 'faculty_id', 'study_program_id', 'is_active'])->all(),
            'schedules' => $schedules->all(),
            'occurrences' => DB::table('teaching_occurrences')->whereIn('teaching_schedule_id', $schedules->pluck('id'))
                ->orderBy('id')->get(['id', 'teaching_schedule_id', 'occurs_on', 'starts_at', 'ends_at', 'status'])->all(),
            'assignments' => DB::table('supervision_assignments')->where('status', '<>', 'CANCELLED')
                ->where(fn ($query) => $query->whereIn('supervision_stage_id', $period->stages->modelKeys())
                    ->orWhereIn('supervisor_lecturer_id', $supervisors->pluck('lecturer_id')))
                ->orderBy('id')->get(['id', 'supervision_stage_id', 'target_lecturer_id', 'supervisor_lecturer_id',
                    'teaching_occurrence_id', 'starts_at', 'ends_at', 'assignment_type', 'is_locked', 'status'])->all(),
        ];
    }

    /** @param array<string, mixed> $input */
    public function hash(array $input): string
    {
        $context = hash_init('sha256');
        $this->updateCanonicalHash($context, $input);

        return hash_final($context);
    }

    /** Write canonical JSON directly to the hash without duplicating the complete snapshot. */
    private function updateCanonicalHash(\HashContext $context, mixed $value, int $depth = 0): void
    {
        if ($value instanceof \JsonSerializable) {
            $this->updateCanonicalHash($context, $value->jsonSerialize(), $depth);

            return;
        }
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d\TH:i:s.uP');
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            hash_update($context, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

            return;
        }
        if ($depth >= 512) {
            throw new \JsonException('Maximum stack depth exceeded', JSON_ERROR_DEPTH);
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        $isList = array_is_list($value);
        hash_update($context, $isList ? '[' : '{');
        $isFirst = true;
        foreach ($value as $key => $item) {
            if (! $isFirst) {
                hash_update($context, ',');
            }
            $isFirst = false;
            if (! $isList) {
                hash_update($context, json_encode((string) $key, JSON_THROW_ON_ERROR).':');
            }
            $this->updateCanonicalHash($context, $item, $depth + 1);
        }
        hash_update($context, $isList ? ']' : '}');
    }
}
