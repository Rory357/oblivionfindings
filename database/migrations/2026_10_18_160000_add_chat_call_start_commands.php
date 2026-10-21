<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_call_start_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users');
            $table->foreignId('conversation_id')->constrained('ops_conversations');
            $table->uuid('mutation_id');
            $table->uuid('device_id');
            $table->char('payload_hash', 64);
            $table->foreignUuid('call_id')->nullable()->constrained('chat_calls')->nullOnDelete();
            $table->uuid('invitation_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'mutation_id'], 'chat_call_start_command_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_call_start_commands');
    }
};
