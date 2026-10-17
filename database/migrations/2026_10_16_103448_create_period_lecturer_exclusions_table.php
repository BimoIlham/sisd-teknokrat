<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_lecturer_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supervision_period_id')->constrained('supervision_periods')->cascadeOnDelete();
            $table->string('normalized_name');
            $table->string('reason', 80);
            $table->foreignId('excluded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['supervision_period_id', 'normalized_name'], 'period_lecturer_exclusions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_lecturer_exclusions');
    }
};
