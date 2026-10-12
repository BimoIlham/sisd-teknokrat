<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60); // OFFLINE, ONLINE, DEMO_OFFLINE_V1, DEMO_ONLINE_V1
            $table->unsignedInteger('version')->default(1);
            $table->string('name', 255);
            $table->string('delivery_mode', 10); // OFFLINE, ONLINE
            $table->string('status', 40)->default('DRAFT'); // DRAFT, PUBLISHED, RETIRED
            $table->boolean('is_demo')->default(false);
            $table->unsignedInteger('expected_scored_count')->default(0);
            $table->text('source_path')->nullable();
            $table->char('source_sha256', 64)->nullable();
            $table->json('source_metadata')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->unique(['code', 'version']);
            $table->index(['delivery_mode', 'status']);
        });

        Schema::create('form_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_template_id')->constrained('form_templates')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('title', 255);
            $table->text('subtitle')->nullable();
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['form_template_id', 'code']);
            $table->unique(['form_template_id', 'sort_order']);
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_template_id')->constrained('form_templates')->cascadeOnDelete();
            $table->foreignId('form_section_id')->constrained('form_sections')->cascadeOnDelete();
            $table->string('code', 80);
            $table->text('question_text');
            $table->text('help_text')->nullable();
            $table->string('question_type', 20); // SCORE, YES_NO, LINK, FILE, TEXTAREA
            $table->boolean('is_required')->default(true);
            $table->boolean('is_scored')->default(false);
            $table->unsignedInteger('sort_order');
            $table->json('conditional_rule')->nullable();
            $table->json('validation_rules')->nullable();
            $table->json('source_reference')->nullable();
            $table->timestamps();

            $table->unique(['form_template_id', 'code']);
            $table->unique(['form_section_id', 'sort_order']);
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->string('value', 60);
            $table->decimal('numeric_score', 5, 2)->nullable();
            $table->text('label');
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['question_id', 'value']);
            $table->unique(['question_id', 'sort_order']);
        });

        Schema::create('scoring_policies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60);
            $table->unsignedInteger('version')->default(1);
            $table->string('strategy', 30); // MEAN_0_4, LINEAR_1_4, FLOOR_1_4
            $table->json('config');
            $table->string('status', 40)->default('DRAFT'); // DRAFT, APPROVED, RETIRED
            $table->boolean('is_demo')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->timestamps();

            $table->unique(['code', 'version']);
        });

        Schema::create('stage_instruments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supervision_stage_id')->constrained('supervision_stages')->cascadeOnDelete();
            $table->string('delivery_mode', 10); // OFFLINE, ONLINE
            $table->foreignId('form_template_id')->constrained('form_templates')->restrictOnDelete();
            $table->foreignId('scoring_policy_id')->constrained('scoring_policies')->restrictOnDelete();
            $table->foreignId('bound_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['supervision_stage_id', 'delivery_mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_instruments');
        Schema::dropIfExists('scoring_policies');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('form_sections');
        Schema::dropIfExists('form_templates');
    }
};
