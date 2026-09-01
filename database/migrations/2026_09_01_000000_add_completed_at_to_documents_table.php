<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('documents', 'completed_at')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->timestamp('completed_at')->nullable()->after('status');
            });
        }

        // Backfill completed_at for already completed documents
        DB::table('documents')
            ->where('status', 'completed')
            ->whereNull('completed_at')
            ->update([
                'completed_at' => DB::raw('updated_at'),
            ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('documents', 'completed_at')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->dropColumn('completed_at');
            });
        }
    }
};
