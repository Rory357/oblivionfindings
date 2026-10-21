<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_send_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('ops_conversations')->cascadeOnDelete();
            $table->uuid('mutation_id');
            $table->string('status', 16);
            $table->char('request_hash', 64)->nullable();
            $table->char('boundary', 64)->nullable();
            $table->longText('encrypted_payload')->nullable();
            $table->foreignId('message_id')->nullable()->constrained('ops_messages')->nullOnDelete();
            $table->timestamps();
            $table->unique(['actor_id', 'mutation_id'], 'chat_send_attempt_actor');
            $table->index(['actor_id', 'conversation_id', 'status'], 'chat_send_attempt_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_send_attempts');
    }
};
