<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (! Schema::hasColumn('documents', 'received_at')) {
                $table->timestamp('received_at')->nullable()->after('due_date');
                $table->index('received_at');
            }

            if (! Schema::hasColumn('documents', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('received_at');
                $table->index('completed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'completed_at')) {
                $table->dropIndex(['completed_at']);
                $table->dropColumn('completed_at');
            }

            if (Schema::hasColumn('documents', 'received_at')) {
                $table->dropIndex(['received_at']);
                $table->dropColumn('received_at');
            }
        });
    }
};
