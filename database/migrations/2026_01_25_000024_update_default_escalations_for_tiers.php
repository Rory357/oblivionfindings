<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Make the critical high severity alert require acknowledgement before the user can dismiss the modal,
        // and add a simple escalation tier (after reminder #3, widen to all managers).
        DB::table('notification_escalation_rules')
            ->where('event_key', 'incidents.high_severity_alert')
            ->update([
                'must_ack_before_close' => true,
                'tiers' => json_encode([
                    ['from_reminder' => 3, 'role_groups' => ['managers']],
                ]),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('notification_escalation_rules')
            ->where('event_key', 'incidents.high_severity_alert')
            ->update([
                'must_ack_before_close' => false,
                'tiers' => null,
                'updated_at' => now(),
            ]);
    }
};
