<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stephan, 7 Nov 2026: "Attach on critical too". The Intrusion Alarm and Water Leak procedures
 * started on their own only for "high" alerts. Since the safety fix round, an intruder alarm or a
 * leak that a device reports as critical stays critical in Control Room, so the procedure didn't
 * start. "critical" is added to their severities.
 *
 * Add-only: only these two playbooks, only when their list has "high" and not yet "critical".
 * An empty list (start by hand only) or NULL (any severity) is left as it is, as is every other
 * playbook. The version goes up by one, as an edit in Control Room › Playbooks does. Safe to re-run.
 */
return new class extends Migration
{
    public const CODES = ['intrusion_alarm', 'water_leak'];

    public function up(): void
    {
        $rows = DB::table('control_room_playbooks')->whereIn('code', self::CODES)->get(['id', 'trigger_severities', 'version']);
        foreach ($rows as $row) {
            $severities = json_decode((string) $row->trigger_severities, true);
            if (! is_array($severities) || ! in_array('high', $severities, true) || in_array('critical', $severities, true)) {
                continue;
            }
            DB::table('control_room_playbooks')->where('id', $row->id)->update([
                'trigger_severities' => json_encode([...array_values($severities), 'critical']),
                'version' => (int) $row->version + 1,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Deliberately non-destructive: whether a safety procedure starts on its own is a Control Room decision.
    }
};
