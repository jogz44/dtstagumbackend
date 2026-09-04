<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Update the permission name in the permissions table
        DB::table('permissions')
            ->where('name', 'can_edit_document')
            ->where('guard_name', 'web')
            ->update(['name' => 'can_add_document']);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('name', 'can_add_document')
            ->where('guard_name', 'web')
            ->update(['name' => 'can_edit_document']);
    }
};
