<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (! Schema::hasColumn('documents', 'assigned_user_id')) {
                $table->foreignUuid('assigned_user_id')->nullable()->after('assigned_user')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('documents', 'received_at')) {
                $table->timestamp('received_at')->nullable();
            }
            if (! Schema::hasColumn('documents', 'due_at')) {
                $table->timestamp('due_at')->nullable();
            }
            if (! Schema::hasColumn('documents', 'completed_at')) {
                $table->timestamp('completed_at')->nullable();
            }
            if (! Schema::hasColumn('documents', 'completed_by_user_id')) {
                $table->foreignUuid('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['assigned_user_id']);
            $table->dropForeign(['completed_by_user_id']);
            $table->dropIndex(['assigned_user_id']);
            $table->dropIndex(['completed_by_user_id']);
            $table->dropColumn([
                'assigned_user_id',
                'received_at',
                'due_at',
                'completed_at',
                'completed_by_user_id',
            ]);
        });
    }
};
