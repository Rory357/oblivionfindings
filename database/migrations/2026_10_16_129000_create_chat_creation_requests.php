<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_creation_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('mutation_id');
            $table->char('request_hash', 64);
            // Keep the request after deletion so a late retry cannot recreate a removed chat.
            $table->foreignId('conversation_id')->nullable()->constrained('ops_conversations')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'mutation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_creation_requests');
    }
};
