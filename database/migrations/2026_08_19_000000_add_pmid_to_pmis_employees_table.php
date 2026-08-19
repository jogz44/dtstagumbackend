<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pmis_employees', function (Blueprint $table) {
            $table->string('pmid')->nullable()->after('pmis_no')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pmis_employees', function (Blueprint $table) {
            $table->dropIndex(['pmid']);
            $table->dropColumn('pmid');
        });
    }
};
