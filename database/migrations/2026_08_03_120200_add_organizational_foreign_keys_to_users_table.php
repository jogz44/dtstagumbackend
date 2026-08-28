<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'office_id') || ! Schema::hasColumn('users', 'division_id')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'office_id')) {
                    $table->uuid('office_id')->nullable();
                }

                if (! Schema::hasColumn('users', 'division_id')) {
                    $table->uuid('division_id')->nullable();
                }
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('office_id')->references('id')->on('offices')->nullOnDelete();
            $table->foreign('division_id')->references('id')->on('divisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['office_id']);
            $table->dropForeign(['division_id']);
        });
    }
};
