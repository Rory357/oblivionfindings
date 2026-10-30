<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job 3: exposures reach the H&S register automatically. Each delivery keeps its retry state so a failed attempt is
 * retried (30 s doubling to 15 min) and shown to exposure managers instead of disappearing with its rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chemical_exposure_source_deliveries', function (Blueprint $t) {
            $t->unsignedInteger('failed_attempts')->default(0)->after('attempts');
            $t->string('failure_code', 40)->nullable()->after('failed_attempts');
            // Integrity refusals stop automatic retries; only a manager's retry can deliver them.
            $t->boolean('needs_manager')->default(false)->after('failure_code');
            $t->timestamp('last_attempted_at', 6)->nullable()->after('needs_manager');
            $t->timestamp('next_attempt_at', 6)->nullable()->after('last_attempted_at');
            $t->index(['state', 'kind', 'next_attempt_at'], 'ce_delivery_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('chemical_exposure_source_deliveries', function (Blueprint $t) {
            $t->dropIndex('ce_delivery_due_idx');
            $t->dropColumn(['failed_attempts', 'failure_code', 'needs_manager', 'last_attempted_at', 'next_attempt_at']);
        });
    }
};
