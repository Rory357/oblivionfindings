<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_conversation_participants', function (Blueprint $table) {
            // Null preserves the existing audience. Zero means no earlier messages existed.
            $table->unsignedBigInteger('history_after_id')->nullable();
            $table->timestamp('history_joined_at')->nullable();
        });
        Schema::create('chat_history_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('ops_conversation_participants')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('ops_messages')->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->unique(['participant_id', 'message_id']);
        });
        Schema::create('chat_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('conversation_id')->constrained('ops_conversations')->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users');
            $table->foreignId('user_id')->constrained('users');
            $table->uuid('mutation_id');
            $table->string('request_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->string('history_policy', 20);
            $table->json('message_ids');
            // A relative joins another relative's private conversation only after
            // a current family member of that conversation agrees (approved 2026-10-20).
            $table->boolean('consent_required')->default(false);
            $table->foreignId('consented_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('consented_at')->nullable();
            $table->timestamps();
            $table->unique(['invited_by', 'mutation_id', 'user_id'], 'chat_invitation_retry');
            $table->index(['user_id', 'status']);
        });
        Schema::create('chat_history_call_exclusions', function (Blueprint $table) {
            $table->foreignId('participant_id')->constrained('ops_conversation_participants')->cascadeOnDelete();
            $table->foreignUuid('call_id')->constrained('chat_calls')->cascadeOnDelete();
            $table->primary(['participant_id', 'call_id'], 'chat_history_call_boundary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_history_call_exclusions');
        Schema::dropIfExists('chat_invitations');
        Schema::dropIfExists('chat_history_grants');
        Schema::table('ops_conversation_participants', fn (Blueprint $table) => $table->dropColumn(['history_after_id', 'history_joined_at']));
    }
};
