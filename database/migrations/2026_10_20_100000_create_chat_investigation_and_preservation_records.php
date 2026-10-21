<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Investigation access to private staff chats and preservation holds.
 *
 * These records are their own audit domain: they are never written to the
 * general audit log that settings administrators read, and they restrict
 * deletion so removing an account cannot silently remove investigation
 * evidence. Reasons are encrypted at rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_investigation_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('investigator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('conversation_id')->constrained('ops_conversations')->restrictOnDelete();
            $table->string('case_reference', 100);
            $table->text('reason');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['investigator_id', 'expires_at']);
            $table->index(['conversation_id', 'expires_at']);
        });

        Schema::create('chat_preservation_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ops_conversations')->restrictOnDelete();
            $table->string('case_reference', 100);
            $table->text('reason');
            $table->foreignId('placed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('placed_at');
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'released_at']);
        });

        Schema::create('chat_investigation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('ops_conversations')->restrictOnDelete();
            $table->foreignId('grant_id')->nullable()->constrained('chat_investigation_grants')->restrictOnDelete();
            $table->foreignId('hold_id')->nullable()->constrained('chat_preservation_holds')->restrictOnDelete();
            $table->string('action', 40);
            $table->string('case_reference', 100)->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at');
            $table->index(['conversation_id', 'id']);
            $table->index(['actor_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_investigation_events');
        Schema::dropIfExists('chat_preservation_holds');
        Schema::dropIfExists('chat_investigation_grants');
    }
};
