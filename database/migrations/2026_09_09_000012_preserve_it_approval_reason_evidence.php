<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_ticket_approvals', function (Blueprint $table): void {
            // No backfill: an old shared reason cannot establish two historical facts.
            $table->text('request_reason')->nullable();
            $table->timestamp('request_reason_recorded_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('decision_reason_recorded_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('it_ticket_approvals')->where(function ($query): void {
            $query->whereNotNull('request_reason')->orWhereNotNull('request_reason_recorded_at')
                ->orWhereNotNull('decision_reason')->orWhereNotNull('decision_reason_recorded_at');
        })->exists()) {
            throw new RuntimeException('Approval reason evidence must be preserved before removing its schema.');
        }
        Schema::table('it_ticket_approvals', function (Blueprint $table): void {
            $table->dropColumn(['request_reason', 'request_reason_recorded_at', 'decision_reason', 'decision_reason_recorded_at']);
        });
    }
};
