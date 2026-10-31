<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_room_broadcast_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->char('payload_digest', 64);
            $table->char('selection_target_digest', 64);
            $table->json('captured_intent');
            $table->string('selection_outcome', 32);
            $table->string('selection_reason', 64)->nullable();
            $table->timestamp('selection_checked_at');
            $table->unsignedInteger('selected_people_count');
            $table->unsignedInteger('selected_channel_attempt_count');
            $table->timestamps();
            $table->unique(['actor_id', 'idempotency_key'], 'cr_broadcast_actor_key_unique');
        });
        Schema::table('control_room_communications', function (Blueprint $table): void {
            $table->string('broadcast_delivery_state', 32)->nullable();
            $table->uuid('broadcast_attempt_token')->nullable();
            $table->unsignedSmallInteger('broadcast_attempt_count')->default(0);
            $table->timestamp('broadcast_attempt_started_at')->nullable();
            $table->timestamp('broadcast_outcome_at')->nullable();
            $table->timestamp('broadcast_next_attempt_at')->nullable();
            $table->json('broadcast_outcome')->nullable();
            $table->index(['purpose', 'broadcast_delivery_state', 'broadcast_next_attempt_at', 'id'], 'cr_broadcast_recovery_index');
        });
    }

    /** Delivery evidence must survive rollback. Restore code without dropping durable intent/attempt data. */
    public function down(): void
    {
        throw new LogicException('Broadcast delivery evidence is retained. A separately reviewed archival migration is required.');
    }
};

