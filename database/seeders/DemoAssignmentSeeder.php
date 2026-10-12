<?php

namespace Database\Seeders;

use App\Models\FormTemplate;
use App\Models\Lecturer;
use App\Models\ScoringPolicy;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoAssignmentSeeder extends Seeder
{
    public function run(): void
    {
        $stage1 = SupervisionStage::where('stage_no', 1)->first() ?? SupervisionStage::first();
        if (! $stage1) {
            return;
        }

        $supervisors = Lecturer::whereIn('id', [1, 2, 3])->get();
        $targets = Lecturer::whereIn('id', [4, 5, 6, 7, 8])->get();
        $formTemplate = FormTemplate::first();
        $policy = ScoringPolicy::first();
        $supervisorUser1 = User::where('email', 'supervisor1@teknokrat.ac.id')->first() ?? User::first();

        // 1. Assignment Selesai (Target 4: Rina Wulandari by Dr. Budi Santoso)
        if ($targets->count() > 0 && $supervisors->count() > 0) {
            $occ1 = TeachingOccurrence::whereHas('schedule', fn ($q) => $q->where('lecturer_id', $targets[0]->id))->first() ?? TeachingOccurrence::first();
            $asg1 = SupervisionAssignment::firstOrCreate(
                [
                    'supervision_stage_id' => $stage1->id,
                    'target_lecturer_id' => $targets[0]->id,
                ],
                [
                    'supervisor_lecturer_id' => $supervisors[0]->id,
                    'teaching_occurrence_id' => $occ1?->id,
                    'assignment_type' => 'MANUAL',
                    'status' => 'FINAL',
                    'row_version' => 1,
                ]
            );

            if ($formTemplate && $policy) {
                Supervision::firstOrCreate(
                    ['supervision_assignment_id' => $asg1->id],
                    [
                        'form_template_id' => $formTemplate->id,
                        'scoring_policy_id' => $policy->id,
                        'status' => 'SUBMITTED',
                        'raw_score' => 3.85,
                        'scored_question_count' => 10,
                        'final_score' => 3.85,
                        'submitted_by' => $supervisorUser1?->id,
                        'submitted_at' => now()->subDays(1),
                        'row_version' => 1,
                    ]
                );
            }
        }

        // 2. Assignment Sedang Dinilai / Draft (Target 5: Hendra Gunawan by Fitri Yulianti)
        if ($targets->count() > 1 && $supervisors->count() > 1) {
            $occ2 = TeachingOccurrence::whereHas('schedule', fn ($q) => $q->where('lecturer_id', $targets[1]->id))->first() ?? TeachingOccurrence::skip(1)->first();
            $asg2 = SupervisionAssignment::firstOrCreate(
                [
                    'supervision_stage_id' => $stage1->id,
                    'target_lecturer_id' => $targets[1]->id,
                ],
                [
                    'supervisor_lecturer_id' => $supervisors[1]->id,
                    'teaching_occurrence_id' => $occ2?->id,
                    'assignment_type' => 'MANUAL',
                    'status' => 'FINAL',
                    'row_version' => 1,
                ]
            );

            if ($formTemplate && $policy) {
                Supervision::firstOrCreate(
                    ['supervision_assignment_id' => $asg2->id],
                    [
                        'form_template_id' => $formTemplate->id,
                        'scoring_policy_id' => $policy->id,
                        'status' => 'DRAFT',
                        'raw_score' => 3.20,
                        'scored_question_count' => 5,
                        'final_score' => null,
                        'row_version' => 1,
                    ]
                );
            }
        }

        // 3. Assignment Belum Mulai / Terjadwal (Target 6: Dewi Lestari by Andi Pratama)
        if ($targets->count() > 2 && $supervisors->count() > 2) {
            $occ3 = TeachingOccurrence::whereHas('schedule', fn ($q) => $q->where('lecturer_id', $targets[2]->id))->first() ?? TeachingOccurrence::skip(2)->first();
            SupervisionAssignment::firstOrCreate(
                [
                    'supervision_stage_id' => $stage1->id,
                    'target_lecturer_id' => $targets[2]->id,
                ],
                [
                    'supervisor_lecturer_id' => $supervisors[2]->id,
                    'teaching_occurrence_id' => $occ3?->id,
                    'assignment_type' => 'MANUAL',
                    'status' => 'FINAL',
                    'row_version' => 1,
                ]
            );
        }

        // 4. Assignment Belum Mulai / Terjadwal (Target 7: Ahmad Fauzi by Dr. Budi Santoso)
        if ($targets->count() > 3 && $supervisors->count() > 0) {
            $occ4 = TeachingOccurrence::whereHas('schedule', fn ($q) => $q->where('lecturer_id', $targets[3]->id))->first() ?? TeachingOccurrence::skip(3)->first();
            SupervisionAssignment::firstOrCreate(
                [
                    'supervision_stage_id' => $stage1->id,
                    'target_lecturer_id' => $targets[3]->id,
                ],
                [
                    'supervisor_lecturer_id' => $supervisors[0]->id,
                    'teaching_occurrence_id' => $occ4?->id,
                    'assignment_type' => 'MANUAL',
                    'status' => 'FINAL',
                    'row_version' => 1,
                ]
            );
        }
    }
}
