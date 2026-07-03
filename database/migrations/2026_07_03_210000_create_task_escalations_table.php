<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedupe ledger for the tasks:escalate command (EscalateOverdueTasks).
 *
 * One row per (source, item_id, level) means each aggregated work item gets
 * at most ONE level-1 assignee nudge and ONE level-2 manager escalation for
 * its lifetime — the hourly sweep checks this table before notifying.
 * TaskItems themselves are never persisted (see TaskAggregator), so this
 * table keys on the provider source key + the source record's numeric id.
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
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'item_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_escalations');
    }
};
