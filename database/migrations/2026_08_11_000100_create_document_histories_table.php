<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('performed_by')->constrained('users')->restrictOnDelete();
            $table->string('action'); // e.g. 'created', 'received', 'shared', 'transferred', 'released', 'completed', 'returned'
            $table->string('from_office')->nullable();
            $table->string('to_office')->nullable();
            $table->text('remarks')->nullable();
            $table->json('metadata')->nullable(); // For storing any dynamic meta details about the action
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_histories');
    }
};
