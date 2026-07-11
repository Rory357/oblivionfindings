<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §P-S2 (stretch) — ticket merge. A duplicate SOURCE ticket is folded into a
 * TARGET survivor: merged_into_ticket_id points at the survivor, merged_at
 * stamps when. Both nullable, so nothing changes for un-merged tickets; the
 * fold itself is recorded on it_ticket_events (no new event table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('merged_into_ticket_id')->nullable()->after('provisioning_request_id');
            $table->timestamp('merged_at')->nullable()->after('merged_into_ticket_id');
            $table->foreign('merged_into_ticket_id')->references('id')->on('it_tickets')->nullOnDelete();
            $table->index('merged_into_ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('it_tickets', function (Blueprint $table) {
            $table->dropForeign(['merged_into_ticket_id']);
            $table->dropIndex(['merged_into_ticket_id']);
            $table->dropColumn(['merged_into_ticket_id', 'merged_at']);
        });
    }
};
