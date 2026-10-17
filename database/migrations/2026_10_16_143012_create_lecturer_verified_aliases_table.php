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
        Schema::create('lecturer_verified_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('identity_key');
            $table->string('canonical_name');
            $table->string('nidn', 10)->nullable();
            $table->string('alias');
            $table->string('normalized_alias');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['identity_key', 'normalized_alias']);
            $table->index('normalized_alias');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lecturer_verified_aliases');
    }
};
