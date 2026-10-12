<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lecturer_identity_references', function (Blueprint $table) {
            $table->id();
            $table->string('identity_key')->unique();
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->json('profile');
            $table->timestamps();
        });
        Schema::create('schedule_document_references', function (Blueprint $table) {
            $table->id();
            $table->char('sha256', 64)->unique();
            $table->string('original_filename');
            $table->string('parser_version', 30);
            $table->unsignedInteger('page_count');
            $table->string('stored_path')->nullable();
            $table->json('parsed_rows');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_document_references');
        Schema::dropIfExists('lecturer_identity_references');
    }
};
