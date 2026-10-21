<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_call_notice_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->unique();
            $table->foreignId('participant_id')->constrained('chat_call_participants')->cascadeOnDelete();
            $table->unsignedInteger('invitation_version');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('available_at');
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 100)->nullable();
            $table->timestamps();
            $table->unique(['participant_id', 'invitation_version'], 'chat_call_notice_attempt_unique');
            $table->index(['status', 'available_at']);
            // Notification visibility reads a person's delivered rows.
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_call_notice_deliveries');
    }
};
