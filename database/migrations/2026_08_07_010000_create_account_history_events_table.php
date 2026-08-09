<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_history_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('subject_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 32)->default('user');
            $table->string('event_type', 80);
            $table->string('summary');
            $table->json('changes')->nullable();
            $table->foreignUuid('office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->foreignUuid('division_id')->nullable()->constrained('divisions')->nullOnDelete();
            $table->string('request_id', 120)->nullable();
            $table->string('source_ip', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['subject_user_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
            $table->index(['office_id', 'division_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_history_events');
    }
};
