<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('middle_name')->nullable()->after('first_name');
            $table->foreignId('barangay_id')->nullable()->after('division_id')->constrained('barangays')->nullOnDelete();
            $table->string('creation_source')->default('PMIS')->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['barangay_id']);
            $table->dropColumn(['middle_name', 'barangay_id', 'creation_source']);
        });
    }
};
