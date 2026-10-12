<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_runs', function (Blueprint $table) {
            $table->id();
            $table->char('request_id', 36)->unique();
            $table->foreignId('supervision_stage_id')->constrained('supervision_stages')->cascadeOnDelete();
            $table->foreignId('scheduling_rule_id')->constrained('scheduling_rules')->restrictOnDelete();
            $table->unsignedBigInteger('input_revision');
            $table->char('input_hash', 64);
            $table->json('input_payload');
            $table->string('status', 40)->default('QUEUED'); // QUEUED, RUNNING, COMPLETED, FAILED, STALE, APPLIED
            $table->string('solver_status', 30)->nullable(); // OPTIMAL, FEASIBLE, INFEASIBLE, UNKNOWN, MODEL_INVALID
            $table->string('coverage_status', 10)->nullable(); // FULL, PARTIAL, NONE
            $table->json('result_payload')->nullable();
            $table->json('diagnostics')->nullable();
            $table->string('solver_version', 40)->nullable();
            $table->integer('seed')->nullable();
            $table->unsignedInteger('time_limit_seconds')->default(60);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['supervision_stage_id', 'status', 'created_at'], 'sr_stage_status_created_idx');
        });

        Schema::create('supervision_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supervision_stage_id')->constrained('supervision_stages')->cascadeOnDelete();
            $table->foreignId('target_lecturer_id')->constrained('lecturers')->restrictOnDelete();
            $table->foreignId('supervisor_lecturer_id')->constrained('lecturers')->restrictOnDelete();
            $table->foreignId('teaching_occurrence_id')->constrained('teaching_occurrences')->restrictOnDelete();
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6);
            $table->string('assignment_type', 10)->default('AUTO'); // AUTO, MANUAL
            $table->boolean('is_locked')->default(false);
            $table->string('status', 40)->default('DRAFT'); // DRAFT, FINAL, CANCELLED
            $table->string('conflict_status', 40)->default('VALID'); // VALID, CONFLICT, NEEDS_RECALCULATION
            $table->json('conflict_codes')->nullable();
            $table->json('conflict_details')->nullable();
            $table->foreignId('scheduling_rule_id')->constrained('scheduling_rules')->restrictOnDelete();
            $table->foreignId('schedule_run_id')->nullable()->constrained('schedule_runs')->nullOnDelete();
            $table->unsignedBigInteger('input_revision');
            $table->json('identity_snapshot')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->text('change_reason')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            // Generated column to guarantee only 1 active (non-cancelled) assignment per target per stage
            $table->unsignedBigInteger('active_target_key')->storedAs("CASE WHEN status <> 'CANCELLED' THEN target_lecturer_id ELSE NULL END")->nullable();

            $table->unique(['supervision_stage_id', 'active_target_key'], 'sa_stage_active_target_unique');
            $table->index(['supervisor_lecturer_id', 'status', 'starts_at', 'ends_at'], 'sa_sup_status_interval_idx');
            $table->index(['supervision_stage_id', 'target_lecturer_id', 'status'], 'sa_stage_target_status_idx');
            $table->index(['teaching_occurrence_id', 'status'], 'sa_occ_status_idx');
            $table->index(['supervision_stage_id', 'conflict_status', 'status'], 'sa_stage_conflict_status_idx');
        });

        Schema::create('assignment_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supervision_assignment_id')->constrained('supervision_assignments')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->text('reason')->nullable();
            $table->char('request_id', 36)->nullable();
            $table->json('old_payload')->nullable();
            $table->json('new_payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['supervision_assignment_id', 'created_at'], 'aa_assignment_created_idx');
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_audits');
        Schema::dropIfExists('supervision_assignments');
        Schema::dropIfExists('schedule_runs');
    }
};
