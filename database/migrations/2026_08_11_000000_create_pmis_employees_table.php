<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pmis_employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('pmis_no')->unique();
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('designation')->nullable();
            $table->string('designation_code')->nullable();
            $table->string('office')->nullable();
            $table->string('employment_status')->nullable();
            $table->string('telephone')->nullable();
            $table->timestamp('pmis_synced_at')->nullable();
            $table->timestamps();

            $table->index(['office', 'employment_status']);
            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pmis_employees');
    }
};
