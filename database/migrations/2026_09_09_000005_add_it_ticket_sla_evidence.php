<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_tickets', function (Blueprint $table): void {
            $table->json('sla_original_policy_snapshot')->nullable();
            $table->json('sla_policy_snapshot')->nullable();
            $table->dateTime('first_response_breached_at')->nullable();
            $table->dateTime('resolution_breached_at')->nullable();
            $table->dateTime('sla_checked_at')->nullable();
        });
        // No historical clocks, calendars or compliance are inferred here.
    }

    public function down(): void
    {
        if (DB::table('it_tickets')->whereNotNull('sla_original_policy_snapshot')
            ->orWhereNotNull('sla_policy_snapshot')->orWhereNotNull('first_response_breached_at')
            ->orWhereNotNull('resolution_breached_at')->orWhereNotNull('sla_checked_at')->exists()) {
            throw new RuntimeException('Retain recorded SLA policy and breach evidence. Use a forward repair instead of deleting clock history.');
        }
        Schema::table('it_tickets', fn (Blueprint $table) => $table->dropColumn([
            'sla_original_policy_snapshot', 'sla_policy_snapshot',
            'first_response_breached_at', 'resolution_breached_at', 'sla_checked_at',
        ]));
    }
};
