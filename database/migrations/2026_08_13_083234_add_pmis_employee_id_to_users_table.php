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
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('pmis_employee_id')
                ->nullable()
                ->unique()
                ->after('employee_id');

            $table->foreign('pmis_employee_id')
                ->references('id')
                ->on('pmis_employees')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['pmis_employee_id']);
            $table->dropColumn('pmis_employee_id');
        });
    }
};
