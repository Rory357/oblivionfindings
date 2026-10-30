<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_audience_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ops_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('audience_token', 64);
            $table->timestamp('reviewed_at');
            $table->unique(['conversation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_audience_reviews');
    }
};
