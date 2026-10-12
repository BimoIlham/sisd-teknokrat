<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('supervision_answers', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('text_value');
        });

        foreach ([1, 2] as $stageNumber) {
            DB::table('supervision_stages')
                ->where('stage_no', $stageNumber)
                ->update(['name' => 'Tahap '.$stageNumber]);
        }

        foreach (DB::table('supervision_periods')->get() as $period) {
            foreach ([1, 2] as $stageNumber) {
                if (DB::table('supervision_stages')->where('supervision_period_id', $period->id)->where('stage_no', $stageNumber)->exists()) {
                    continue;
                }

                $stageId = DB::table('supervision_stages')->insertGetId([
                    'supervision_period_id' => $period->id,
                    'stage_no' => $stageNumber,
                    'name' => 'Tahap '.$stageNumber,
                    'starts_on' => $period->starts_on,
                    'ends_on' => $period->ends_on,
                    'status' => 'DRAFT',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $existingStageId = DB::table('supervision_stages')->where('supervision_period_id', $period->id)->where('id', '<>', $stageId)->value('id');
                foreach (DB::table('stage_instruments')->where('supervision_stage_id', $existingStageId)->get() as $instrument) {
                    DB::table('stage_instruments')->insert([
                        'supervision_stage_id' => $stageId,
                        'delivery_mode' => $instrument->delivery_mode,
                        'form_template_id' => $instrument->form_template_id,
                        'scoring_policy_id' => $instrument->scoring_policy_id,
                        'bound_by' => $instrument->bound_by,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supervision_answers', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
