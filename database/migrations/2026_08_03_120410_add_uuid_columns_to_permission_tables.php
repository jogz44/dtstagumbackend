<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->uuid('id')->primary()->change();
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary()->change();
        });
    }

    public function down(): void {}
};
