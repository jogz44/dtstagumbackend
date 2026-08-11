<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->text('message');
            $table->string('type')->default('comment'); // e.g. 'comment', 'remark', 'note'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_messages');
    }
};
