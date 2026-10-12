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
        Schema::table('supervision_periods', function (Blueprint $table) {
            $table->dropColumn('document_deadline_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supervision_periods', function (Blueprint $table) {
            $table->dateTime('document_deadline_at')->nullable()->after('ends_on');
        });
    }
};
