<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Role::findOrCreate('pimpinan', 'web');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Role::where('name', 'pimpinan')->where('guard_name', 'web')->first()?->delete();
    }
};
