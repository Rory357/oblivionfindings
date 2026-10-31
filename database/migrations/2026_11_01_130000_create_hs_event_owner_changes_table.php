<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Q2 (Stephan, 31 Oct; G3 boards approved 1 Nov 2026): a safety review's owner can be changed by the people who manage
 * health and safety at its Site, always with a reason. Each change is kept here (append-only: never updated or
 * deleted) with the names as they were, so the history survives a renamed or removed account. Decision 3: the first
 * owner of a review that never had one is recorded here too (no previous owner); only that row may have no reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hs_event_owner_changes')) {
            return;
        }

        Schema::create('hs_event_owner_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hs_event_id')->constrained('hs_events', indexName: 'hs_event_owner_change_event_fk')->restrictOnDelete();
            $table->foreignId('previous_owner_user_id')->nullable()->constrained('users', indexName: 'hs_event_owner_change_prev_fk')->nullOnDelete();
            $table->string('previous_owner_name')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users', indexName: 'hs_event_owner_change_owner_fk')->nullOnDelete();
            $table->string('owner_name');
            $table->foreignId('actor_user_id')->nullable()->constrained('users', indexName: 'hs_event_owner_change_actor_fk')->nullOnDelete();
            $table->string('actor_name');
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
            $table->index(['hs_event_id', 'created_at'], 'hs_event_owner_change_event_idx');
        });
    }

    public function down(): void
    {
        // The owner history is evidence: keep it on an application rollback (as hs_corrective_action_owner_changes does).
    }
};
