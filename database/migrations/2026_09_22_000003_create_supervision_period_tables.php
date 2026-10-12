<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_state', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamp('updated_at')->useCurrent();
        });

        // Seed initial singleton row
        DB::table('scheduling_state')->insert([
            'id' => 1,
            'revision' => 1,
            'updated_at' => now(),
        ]);

        Schema::create('supervision_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->string('name', 255);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 40)->default('DRAFT'); // DRAFT, ACTIVE, CLOSED
            $table->boolean('is_demo')->default(false);
            $table->unsignedBigInteger('active_scheduling_rule_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->index(['academic_term_id', 'status']);
        });

        Schema::create('period_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supervision_period_id')->constrained('supervision_periods')->cascadeOnDelete();
            $table->foreignId('lecturer_id')->constrained('lecturers')->restrictOnDelete();
            $table->string('scope_status', 20)->default('INCLUDED'); // INCLUDED, EXCLUDED
            $table->text('reason')->nullable();
            $table->foreignId('selected_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->unique(['supervision_period_id', 'lecturer_id']);
            $table->index(['supervision_period_id', 'scope_status']);
        });

        Schema::create('supervision_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supervision_period_id')->constrained('supervision_periods')->cascadeOnDelete();
            $table->unsignedTinyInteger('stage_no'); // 1 or 2
            $table->string('name', 100);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 40)->default('DRAFT'); // DRAFT, FINALIZED, CLOSED
            $table->unsignedInteger('schedule_revision')->default(0);
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->unique(['supervision_period_id', 'stage_no']);
        });

        Schema::create('period_supervisors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supervision_period_id')->constrained('supervision_periods')->cascadeOnDelete();
            $table->foreignId('lecturer_id')->constrained('lecturers')->restrictOnDelete();
            $table->foreignId('selected_by')->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->unique(['supervision_period_id', 'lecturer_id']);
            $table->index(['supervision_period_id', 'is_active']);
        });

        Schema::create('scheduling_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supervision_period_id')->constrained('supervision_periods')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('schema_version', 20)->default('1.0');
            $table->json('config');
            $table->string('status', 40)->default('DRAFT'); // DRAFT, APPROVED, RETIRED
            $table->boolean('is_demo')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->timestamps();

            $table->unique(['supervision_period_id', 'version']);
        });

        // Add foreign key constraint to active_scheduling_rule_id
        Schema::table('supervision_periods', function (Blueprint $table) {
            $table->foreign('active_scheduling_rule_id')->references('id')->on('scheduling_rules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supervision_periods', function (Blueprint $table) {
            $table->dropForeign(['active_scheduling_rule_id']);
        });
        Schema::dropIfExists('scheduling_rules');
        Schema::dropIfExists('period_supervisors');
        Schema::dropIfExists('supervision_stages');
        Schema::dropIfExists('period_targets');
        Schema::dropIfExists('supervision_periods');
        Schema::dropIfExists('scheduling_state');
    }
};
