<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Following" / watchers for the company-wide /tasks queue.
 *
 * A watcher is a user who wants FYI notifications about a work item they do
 * not own — reassignments and overdue escalations. TaskItems themselves are
 * never persisted (see TaskAggregator), so this table keys on the provider
 * source key + the source record's numeric id, exactly like task_escalations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_watchers', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);            // provider key, e.g. "incident"
            $table->unsignedBigInteger('item_id');   // source record id within that provider
            $table->unsignedBigInteger('user_id');   // the watching user
            $table->timestamps();

            $table->unique(['source', 'item_id', 'user_id'], 'task_watchers_unique');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_watchers');
    }
};
