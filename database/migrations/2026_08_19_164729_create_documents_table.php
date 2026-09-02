<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('documents')) {
            Schema::create('documents', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('tracking_number')->unique();
                $table->string('description');                    // particular
                $table->string('destination_office')->nullable(); // destination office
                $table->string('document_type')->default('Transmittal');
                $table->string('priority_level')->default('Normal');
                $table->string('status')->default('Pending');     // Pending, Completed, Released, Incoming, etc.
                $table->string('office_assignment')->nullable();
                $table->string('division_assignment')->nullable();
                $table->string('landline')->nullable();
                $table->string('assigned_user')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
