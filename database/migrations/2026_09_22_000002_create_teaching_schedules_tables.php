<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_filename', 255);
            $table->string('storage_disk', 60)->default('local');
            $table->text('stored_path');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type', 100);
            $table->string('parser_name', 100)->nullable();
            $table->string('parser_version', 40)->nullable();
            $table->string('contract_version', 20)->default('1.0');
            $table->string('status', 40)->default('UPLOADED'); // UPLOADED, PROCESSING, NEEDS_REVIEW, READY, COMMITTING, COMMITTED, FAILED
            $table->unsignedInteger('row_version')->default(1);
            $table->unsignedInteger('page_count')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('parsed_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->foreignId('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['academic_term_id', 'sha256']);
            $table->index(['academic_term_id', 'status']);
        });

        Schema::create('schedule_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_import_id')->constrained('schedule_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->unsignedInteger('source_page')->nullable();
            $table->json('source_locator')->nullable();
            $table->json('raw_payload');
            $table->json('normalized_payload');
            $table->decimal('parser_confidence', 5, 4)->nullable();
            $table->string('validation_status', 40)->default('VALID'); // VALID, INVALID, AMBIGUOUS, SKIPPED
            $table->json('validation_issues')->nullable();
            $table->foreignId('matched_lecturer_id')->nullable()->constrained('lecturers')->nullOnDelete();
            $table->foreignId('matched_course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->char('row_fingerprint', 64)->nullable();
            $table->boolean('is_approved')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('skip_reason')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->unsignedBigInteger('committed_schedule_id')->nullable();
            $table->timestamps();

            $table->unique(['schedule_import_id', 'row_no']);
            $table->index(['schedule_import_id', 'validation_status', 'is_approved'], 'sir_import_val_appr_idx');
        });

        Schema::create('teaching_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->foreignId('lecturer_id')->constrained('lecturers')->restrictOnDelete();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->foreignId('faculty_id')->nullable()->constrained('faculties')->nullOnDelete();
            $table->foreignId('study_program_id')->nullable()->constrained('study_programs')->nullOnDelete();
            $table->string('class_name', 100);
            $table->string('room_name', 100)->nullable();
            $table->string('delivery_mode', 10); // OFFLINE, ONLINE
            $table->unsignedTinyInteger('day_of_week'); // 1-7 (Senin-Minggu)
            $table->time('starts_local');
            $table->time('ends_local');
            $table->date('effective_from');
            $table->date('effective_to');
            $table->foreignId('source_import_id')->nullable()->constrained('schedule_imports')->nullOnDelete();
            $table->foreignId('source_row_id')->nullable()->constrained('schedule_import_rows')->nullOnDelete();
            $table->json('source_payload')->nullable();
            $table->char('fingerprint', 64);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->unique(['academic_term_id', 'fingerprint']);
            $table->index(['academic_term_id', 'lecturer_id', 'day_of_week', 'starts_local'], 'ts_term_lec_day_start_idx');
            $table->index(['study_program_id', 'is_active']);
        });

        // Add foreign key constraint from schedule_import_rows to teaching_schedules
        Schema::table('schedule_import_rows', function (Blueprint $table) {
            $table->foreign('committed_schedule_id')->references('id')->on('teaching_schedules')->nullOnDelete();
        });

        Schema::create('academic_non_teaching_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->date('date');
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['academic_term_id', 'date']);
        });

        Schema::create('teaching_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teaching_schedule_id')->constrained('teaching_schedules')->restrictOnDelete();
            $table->date('original_date');
            $table->date('occurs_on');
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6);
            $table->string('status', 20)->default('SCHEDULED'); // SCHEDULED, CANCELLED
            $table->boolean('is_exception')->default(false);
            $table->text('exception_reason')->nullable();
            $table->json('identity_snapshot')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->unique(['teaching_schedule_id', 'original_date']);
            $table->index(['occurs_on', 'status', 'starts_at', 'ends_at'], 'to_occurs_status_starts_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_occurrences');
        Schema::dropIfExists('academic_non_teaching_dates');
        Schema::table('schedule_import_rows', function (Blueprint $table) {
            $table->dropForeign(['committed_schedule_id']);
        });
        Schema::dropIfExists('teaching_schedules');
        Schema::dropIfExists('schedule_import_rows');
        Schema::dropIfExists('schedule_imports');
    }
};
