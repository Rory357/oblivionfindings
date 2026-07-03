<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedupe ledger for the tasks:escalate command (EscalateOverdueTasks).
 *
 * Level-1 nudges key on (source, item, level, assignee) so an overdue item
 * that is REASSIGNED nudges its new owner too; level-2 manager escalations
 * use assignee_id = 0 and fire once per item lifetime. TaskItems themselves
 * are never persisted (see TaskAggregator), so this table keys on the
 * provider source key + the source record's numeric id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_escalations', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);            // provider key, e.g. "followup"
            $table->unsignedBigInteger('item_id');   // source record id within that provider
            $table->unsignedTinyInteger('level');    // 1 = assignee nudge, 2 = manager escalation
            $table->unsignedBigInteger('assignee_id')->default(0); // level 1: nudged assignee; level 2: 0
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'item_id', 'level', 'assignee_id'], 'task_escalations_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_escalations');
    }
};
