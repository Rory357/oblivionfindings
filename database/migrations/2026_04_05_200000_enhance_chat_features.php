<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Message reactions (emoji on individual messages)
        Schema::create('ops_message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('ops_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 10);
            $table->timestamps();

            $table->unique(['message_id', 'user_id', 'emoji']);
            $table->index('message_id');
        });

        // Enhance ops_messages with pin, voice, shift context, LLM scaffolding
        Schema::table('ops_messages', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('is_read');
            $table->json('meta')->nullable()->after('attachments'); // LLM scaffolding: { ai_summary, ai_sentiment, ai_translation, ai_language }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_message_reactions');

        Schema::table('ops_messages', function (Blueprint $table) {
            $table->dropColumn(['is_pinned', 'meta']);
        });
    }
};
