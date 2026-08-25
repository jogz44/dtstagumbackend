<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_sessions', function (Blueprint $table) {
            $table->uuid('token')->primary();
            $table->string('status')->default('pending')->index(); // pending, connected, completed
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('scan_pages', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_token')->index();
            $table->string('file_path');
            $table->integer('page_number');
            $table->timestamp('created_at')->nullable();

            $table->foreign('session_token')
                  ->references('token')
                  ->on('scan_sessions')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_pages');
        Schema::dropIfExists('scan_sessions');
    }
};
