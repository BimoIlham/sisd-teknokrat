<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 40); // SUPERVISOR_SCHEDULE, SUPERVISION_REPORT, EXCEL_EXPORT
            $table->foreignId('supervision_period_id')->constrained('supervision_periods')->cascadeOnDelete();
            $table->foreignId('supervision_stage_id')->nullable()->constrained('supervision_stages')->cascadeOnDelete();
            $table->foreignId('supervisor_lecturer_id')->nullable()->constrained('lecturers')->cascadeOnDelete();
            $table->unsignedInteger('source_revision');
            $table->char('source_hash', 64);
            $table->json('filter_snapshot')->nullable();
            $table->string('status', 40)->default('QUEUED'); // QUEUED, PROCESSING, READY, FAILED, STALE
            $table->string('storage_disk', 60)->default('local');
            $table->text('file_path')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['supervision_stage_id', 'supervisor_lecturer_id', 'document_type', 'source_revision'], 'gd_stage_sup_type_rev_idx');
            $table->index(['status', 'created_at']);
        });

        Schema::create('operation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('operation', 60);
            $table->string('idempotency_key', 100);
            $table->char('request_hash', 64);
            $table->string('status', 20)->default('PROCESSING'); // PROCESSING, SUCCEEDED, FAILED
            $table->string('resource_type', 60)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['actor_user_id', 'operation', 'idempotency_key'], 'or_actor_op_key_unique');
        });

        Schema::create('system_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 100);
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->char('request_id', 36)->nullable();
            $table->text('reason')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id', 'created_at'], 'sa_entity_created_idx');
            $table->index(['user_id', 'created_at']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_audits');
        Schema::dropIfExists('operation_requests');
        Schema::dropIfExists('generated_documents');
    }
};
