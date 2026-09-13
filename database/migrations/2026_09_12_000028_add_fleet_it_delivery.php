<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_signal_outbox', function (Blueprint $table): void {
            $table->string('it_status', 24)->nullable();
            $table->unsignedInteger('it_attempts')->default(0);
            $table->unsignedInteger('it_attempt_limit')->default(3);
            $table->foreignId('it_signal_id')->nullable()->constrained('control_room_signals')->restrictOnDelete();
            $table->json('it_scope')->nullable();
            $table->string('it_outcome_code', 64)->nullable();
            $table->json('it_ticket_ids')->nullable();
            $table->timestamp('it_last_attempt_at')->nullable();
            $table->timestamp('it_completed_at')->nullable();
            $table->index(['it_status', 'it_last_attempt_at'], 'fleet_outbox_it_recovery');
        });
    }

    public function down(): void
    {
        if (DB::table('fleet_signal_outbox')->whereNotNull('it_status')->exists()) {
            throw new LogicException('Recorded Fleet IT delivery outcomes must be retained before rollback.');
        }
        Schema::table('fleet_signal_outbox', function (Blueprint $table): void {
            $table->dropForeign(['it_signal_id']);
            $table->dropIndex('fleet_outbox_it_recovery');
            $table->dropColumn(['it_status', 'it_attempts', 'it_attempt_limit', 'it_signal_id', 'it_scope', 'it_outcome_code', 'it_ticket_ids', 'it_last_attempt_at', 'it_completed_at']);
        });
    }
};
