<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_conversations', function (Blueprint $table): void {
            // Existing family threads remain member-only until cover is explicitly disclosed.
            $table->boolean('care_team_cover')->default(false);
            $table->string('queue_status', 30)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('chat_version')->default(1);
        });
        Schema::table('ops_messages', function (Blueprint $table): void {
            $table->uuid('mutation_id')->nullable();
            $table->foreignId('reply_to_id')->nullable()->constrained('ops_messages')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->unique(['sender_id', 'mutation_id'], 'chat_sender_mutation_unique');
        });
        Schema::table('ops_conversation_participants', function (Blueprint $table): void {
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->boolean('is_favourite')->default(false);
        });
        Schema::create('chat_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('ops_messages')->cascadeOnDelete();
            $table->string('storage_path');
            $table->string('name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_attachments');
        Schema::table('ops_conversation_participants', function (Blueprint $table): void {
            $table->dropColumn(['last_read_message_id', 'is_favourite']);
        });
        Schema::table('ops_messages', function (Blueprint $table): void {
            $table->dropUnique('chat_sender_mutation_unique');
            $table->dropConstrainedForeignId('reply_to_id');
            $table->dropColumn(['mutation_id', 'edited_at']);
        });
        Schema::table('ops_conversations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn(['care_team_cover', 'queue_status', 'chat_version']);
        });
    }
};
