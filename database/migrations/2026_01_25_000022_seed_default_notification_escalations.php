<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Safe defaults: only enable escalation for critical high-severity alerts.
        DB::table('notification_escalation_rules')->updateOrInsert(
            ['event_key' => 'incidents.high_severity_alert'],
            [
                'enabled' => true,
                'require_ack' => true,
                'force_delivery' => true,
                'remind_after_minutes' => 30,
                'repeat_every_minutes' => 60,
                'max_reminders' => 8,
                'escalate_to_role_groups' => json_encode(['managers_core', 'coordinators']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('notification_escalation_rules')->where('event_key', 'incidents.high_severity_alert')->delete();
    }
};
