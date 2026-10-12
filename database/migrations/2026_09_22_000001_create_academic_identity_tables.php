<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculties', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('study_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->constrained('faculties')->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['faculty_id', 'code']);
            $table->index(['faculty_id', 'is_active']);
        });

        Schema::create('lecturers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->nullable()->constrained('faculties')->nullOnDelete();
            $table->foreignId('study_program_id')->nullable()->constrained('study_programs')->nullOnDelete();
            $table->string('external_code', 100)->nullable()->unique();
            $table->string('name', 255);
            $table->string('normalized_name', 255)->index();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->index(['study_program_id', 'is_active']);
        });

        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            $table->string('semester', 10); // GANJIL, GENAP
            $table->string('academic_year', 9); // e.g. 2026/2027
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->unique(['semester', 'academic_year']);
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->nullable()->unique();
            $table->string('name', 255);
            $table->string('normalized_name', 255)->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Add columns to existing users table
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('lecturer_id')->nullable()->unique()->after('id')->constrained('lecturers')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('password');
            $table->boolean('must_change_password')->default(true)->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['lecturer_id']);
            $table->dropColumn(['lecturer_id', 'is_active', 'must_change_password', 'last_login_at']);
        });

        Schema::dropIfExists('courses');
        Schema::dropIfExists('academic_terms');
        Schema::dropIfExists('lecturers');
        Schema::dropIfExists('study_programs');
        Schema::dropIfExists('faculties');
    }
};
