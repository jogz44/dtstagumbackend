<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_histories', function (Blueprint $table) {
            $table->string('tracking_number')->nullable()->after('document_id');
            $table->foreignUuid('from_user_id')->nullable()->after('performed_by')->constrained('users')->nullOnDelete();
            $table->foreignUuid('to_user_id')->nullable()->after('from_user_id')->constrained('users')->nullOnDelete();
            $table->foreignUuid('from_division_id')->nullable()->after('to_user_id')->constrained('divisions')->nullOnDelete();
            $table->foreignUuid('to_division_id')->nullable()->after('from_division_id')->constrained('divisions')->nullOnDelete();
            $table->string('status_before')->nullable()->after('action');
            $table->string('status_after')->nullable()->after('status_before');

            $table->index(['tracking_number']);
            $table->index(['performed_by', 'created_at']);
            $table->index(['from_user_id', 'created_at']);
            $table->index(['to_user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        DB::table('document_histories')->update([
            'action' => DB::raw('UPPER(action)'),
        ]);

        DB::statement('
            UPDATE document_histories
            SET tracking_number = (
                SELECT documents.tracking_number
                FROM documents
                WHERE documents.id = document_histories.document_id
            )
            WHERE tracking_number IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('document_histories', function (Blueprint $table) {
            $table->dropIndex(['tracking_number']);
            $table->dropIndex(['performed_by', 'created_at']);
            $table->dropIndex(['from_user_id', 'created_at']);
            $table->dropIndex(['to_user_id', 'created_at']);
            $table->dropIndex(['action', 'created_at']);

            $table->dropForeign(['from_user_id']);
            $table->dropForeign(['to_user_id']);
            $table->dropForeign(['from_division_id']);
            $table->dropForeign(['to_division_id']);

            $table->dropColumn([
                'tracking_number',
                'from_user_id',
                'to_user_id',
                'from_division_id',
                'to_division_id',
                'status_before',
                'status_after',
            ]);
        });
    }
};
