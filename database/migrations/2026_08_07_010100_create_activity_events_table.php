<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('activity_type', 80);
            $table->string('module', 80);
            $table->string('resource_type', 80)->nullable();
            $table->string('resource_id')->nullable();
            $table->string('description');
            $table->string('outcome', 32)->default('success');
            $table->foreignUuid('office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->foreignUuid('division_id')->nullable()->constrained('divisions')->nullOnDelete();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 120)->nullable();
            $table->string('session_id', 120)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['activity_type', 'occurred_at']);
            $table->index(['module', 'occurred_at']);
            $table->index(['resource_type', 'resource_id']);
            $table->index(['office_id', 'division_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
    }
};
