<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('divisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('office_id')->constrained('offices')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->string('short_name', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['office_id', 'code']);
            $table->index(['office_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('divisions');
    }
};
