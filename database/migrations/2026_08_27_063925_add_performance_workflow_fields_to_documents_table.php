<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignUuid('assigned_user_id')->nullable()->after('assigned_user')->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable()->after('assigned_user_id');
            $table->timestamp('due_at')->nullable()->after('received_at');
            $table->timestamp('completed_at')->nullable()->after('due_at');
            $table->foreignUuid('completed_by_user_id')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();

            $table->index('assigned_user_id');
            $table->index('completed_by_user_id');
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
