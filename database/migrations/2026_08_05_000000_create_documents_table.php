<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tracking_number')->unique();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('origin_sender');
            $table->string('sender_name');
            $table->string('document_type');
            $table->string('priority_level');
            $table->string('description', 200);
            $table->text('remarks')->nullable();
            $table->boolean('is_confidential')->default(false);
            $table->boolean('self_assign')->default(false);
            $table->boolean('send_to_all')->default(false);
            $table->boolean('is_internal')->default(false);
            $table->string('office_assignment')->nullable();
            $table->string('division_assignment')->nullable();
            $table->string('assigned_user')->nullable();
            $table->boolean('is_read_only')->default(false);
            $table->string('status')->default('outgoing');
            $table->timestamps();

            $table->index(['created_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
