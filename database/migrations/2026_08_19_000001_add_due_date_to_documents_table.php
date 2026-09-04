<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('documents', 'due_date')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->date('due_date')->nullable()->after('status');
                $table->index(['due_date', 'status']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('documents', 'due_date')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->dropIndex(['documents_due_date_status_index']);
                $table->dropColumn('due_date');
            });
        }
    }
};
