<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
 public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Account Credentials (REQUIRED)
            $table->string('username')->unique();
            $table->string('password');

            // Employee Details (NULLABLE / OPTIONAL FOR ADMIN)
            $table->string('fullname')->nullable();
            $table->string('employee_id')->nullable();
            $table->date('birthdate')->nullable();
            $table->string('office')->nullable();
            $table->string('division')->nullable();
            $table->string('position')->nullable();

            // Default Status & Role
            $table->string('role')->default('Admin');
            $table->string('status')->default('Active');

            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
