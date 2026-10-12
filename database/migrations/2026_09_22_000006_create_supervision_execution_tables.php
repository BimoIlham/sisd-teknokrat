<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supervision_assignment_id')->unique()->constrained('supervision_assignments')->cascadeOnDelete();
            $table->foreignId('stage_instrument_id')->constrained('stage_instruments')->restrictOnDelete();
            $table->foreignId('form_template_id')->constrained('form_templates')->restrictOnDelete();
            $table->foreignId('scoring_policy_id')->constrained('scoring_policies')->restrictOnDelete();
            $table->string('status', 40)->default('ASSIGNED'); // ASSIGNED, DRAFT, SUBMITTED
            $table->date('supervision_date');
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6);
            $table->string('delivery_mode', 10); // OFFLINE, ONLINE
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->string('course_name_override', 255)->nullable();
            $table->text('course_override_reason')->nullable();
            $table->text('material_topic')->nullable();
            $table->json('identity_snapshot')->nullable();
            $table->decimal('raw_score', 10, 4)->nullable();
            $table->unsignedInteger('scored_question_count')->nullable();
            $table->decimal('final_score', 8, 6)->nullable();
            $table->json('submission_snapshot')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->index(['status', 'submitted_at']);
            $table->index('supervision_date');
        });

        Schema::create('supervision_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supervision_id')->constrained('supervisions')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->restrictOnDelete();
            $table->foreignId('question_option_id')->nullable()->constrained('question_options')->nullOnDelete();
            $table->boolean('boolean_value')->nullable();
            $table->longText('text_value')->nullable();
            $table->decimal('numeric_score', 5, 2)->nullable();
            $table->boolean('is_applicable')->default(true);
            $table->json('answer_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['supervision_id', 'question_id']);
        });

        Schema::create('supervision_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supervision_answer_id')->constrained('supervision_answers')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('evidence_type', 10); // IMAGE, FILE, URL
            $table->string('storage_disk', 60)->default('local');
            $table->text('file_path')->nullable();
            $table->text('external_url')->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('status', 20)->default('PENDING'); // PENDING, READY, REJECTED
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();

            $table->index(['supervision_answer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervision_evidence');
        Schema::dropIfExists('supervision_answers');
        Schema::dropIfExists('supervisions');
    }
};
