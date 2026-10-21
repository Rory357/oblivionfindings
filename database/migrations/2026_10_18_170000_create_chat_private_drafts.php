<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_private_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('ops_conversations')->cascadeOnDelete();
            $table->char('boundary', 64);
            $table->unsignedBigInteger('version');
            $table->longText('encrypted_payload')->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'conversation_id'], 'chat_private_draft_owner');
        });
        Schema::create('chat_private_draft_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('ops_conversations')->cascadeOnDelete();
            $table->uuid('mutation_id');
            $table->char('payload_hash', 64);
            $table->unsignedBigInteger('applied_version');
            $table->timestamp('created_at');
            $table->unique(['actor_id', 'mutation_id'], 'chat_private_draft_command_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_private_draft_commands');
        Schema::dropIfExists('chat_private_drafts');
    }
};
