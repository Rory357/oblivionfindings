<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PKG-02B obligation reminders: one reminder per due point of a service
 * schedule or compliance record, with its in-app delivery history and any
 * acknowledgement. A reminder never completes the obligation it points at.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_obligation_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            // The due point this reminder is for ("date:2026-10-08" or "km:85000").
            $table->string('cycle_key', 64);
            $table->date('due_on')->nullable();
            $table->decimal('due_km', 10, 1)->nullable();
            $table->string('state', 24)->default('scheduled');
            $table->foreignId('owner_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_obligation_rem_owner_fk')->nullOnDelete();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->foreignId('acknowledged_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_obligation_rem_ack_fk')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledgement_note', 2000)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['asset_id', 'source_type', 'source_id', 'cycle_key'], 'fleet_obligation_rem_cycle_uq');
            $table->index(['state', 'last_attempt_at'], 'fleet_obligation_rem_state_idx');
        });

        Schema::create('fleet_obligation_reminder_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reminder_id')
                ->constrained('fleet_obligation_reminders', 'id', 'fleet_obligation_rem_event_fk')->restrictOnDelete();
            // sent | failed | acknowledged
            $table->string('action', 24);
            $table->string('channel', 24)->nullable();
            $table->foreignId('recipient_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_obligation_rem_recipient_fk')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_obligation_rem_actor_fk')->nullOnDelete();
            $table->string('note', 2000)->nullable();
            $table->string('request_key', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['reminder_id', 'request_key'], 'fleet_obligation_rem_event_request_uq');
            $table->index(['reminder_id', 'id'], 'fleet_obligation_rem_event_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('fleet_obligation_reminders')->exists()) {
            throw new RuntimeException('PKG-02B obligation reminders exist; preserve their delivery history instead of rolling back.');
        }
        Schema::dropIfExists('fleet_obligation_reminder_events');
        Schema::dropIfExists('fleet_obligation_reminders');
    }
};
