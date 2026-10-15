<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('supervisions', function (Blueprint $table) {
            $table->timestamp('draft_started_at')->nullable()->after('submitted_at');
        });

        DB::table('supervisions')
            ->where(function (QueryBuilder $query): void {
                $query->where('status', 'SUBMITTED')
                    ->orWhereExists(function (QueryBuilder $answers): void {
                        $answers->selectRaw('1')
                            ->from('supervision_answers')
                            ->whereColumn('supervision_answers.supervision_id', 'supervisions.id');
                    });
            })
            ->update(['draft_started_at' => DB::raw('COALESCE(submitted_at, updated_at, created_at)')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supervisions', function (Blueprint $table) {
            $table->dropColumn('draft_started_at');
        });
    }
};
