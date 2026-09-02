<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')
            ->where('name', 'can_register_documents')
            ->where('guard_name', 'web')
            ->update([
                'name' => 'can_add_documents',
                'description' => 'Add a document',
            ]);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('name', 'can_add_documents')
            ->where('guard_name', 'web')
            ->update([
                'name' => 'can_register_documents',
                'description' => 'Register a document',
            ]);
    }
};
