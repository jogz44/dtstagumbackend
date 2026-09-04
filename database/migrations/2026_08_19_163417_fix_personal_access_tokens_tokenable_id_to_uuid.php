<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop all existing tokens since they're all invalid (tokenable_id = 0)
        DB::table('personal_access_tokens')->truncate();

        // Change tokenable_id from unsignedBigInteger to string to support UUID
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('tokenable_id', 255)->change();
        });
    }

    public function down(): void
    {
        DB::table('personal_access_tokens')->truncate();

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('tokenable_id')->change();
        });
    }
};
