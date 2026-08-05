<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = collect(Schema::getIndexes('user_permission_overrides'))->pluck('name');
        $foreignKeys = collect(Schema::getForeignKeys('user_permission_overrides'))->pluck('name');

        Schema::table('user_permission_overrides', function (Blueprint $table) use ($indexes, $foreignKeys) {
            $table->unsignedBigInteger('user_id')->change();

            if (! $indexes->contains('user_permission_overrides_user_id_permission_name_unique')) {
                $table->unique(['user_id', 'permission_name']);
            }

            if (! $foreignKeys->contains('user_permission_overrides_user_id_foreign')) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        // This migration only repairs constraints missing from a partial migration run.
    }
};
