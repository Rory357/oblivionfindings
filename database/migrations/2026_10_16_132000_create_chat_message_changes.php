<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_message_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ops_conversations')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('ops_messages')->cascadeOnDelete();
            $table->string('kind', 12);
            $table->foreignId('only_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_changes');
    }
};
