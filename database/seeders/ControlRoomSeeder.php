<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\ControlRoomAlert;
use App\Models\User;
use Illuminate\Database\Seeder;

class ControlRoomSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::query()->take(5)->get();
        $assets = Asset::query()->take(10)->get();

        if ($users->isEmpty()) {
            return;
        }

        $alertTypes = [
            // Fleet alerts
            'speeding' => ['source' => 'fleet', 'severities' => ['medium', 'high']],
            'geofence_exit' => ['source' => 'fleet', 'severities' => ['high', 'critical']],
            'geofence_enter' => ['source' => 'fleet', 'severities' => ['low', 'medium']],
            'harsh_braking' => ['source' => 'fleet', 'severities' => ['medium', 'high']],
            'harsh_acceleration' => ['source' => 'fleet', 'severities' => ['low', 'medium']],
            'idle_timeout' => ['source' => 'fleet', 'severities' => ['low']],
            'battery_low' => ['source' => 'fleet', 'severities' => ['medium', 'high']],
            'device_offline' => ['source' => 'fleet', 'severities' => ['high', 'critical']],
            // Personal tracker alerts
            'sos_button' => ['source' => 'personal_tracker', 'severities' => ['critical']],
            'fall_detected' => ['source' => 'personal_tracker', 'severities' => ['critical']],
            'low_battery' => ['source' => 'personal_tracker', 'severities' => ['medium']],
            'check_in_missed' => ['source' => 'personal_tracker', 'severities' => ['high', 'critical']],
            // External/manual alerts
            'unauthorized_access' => ['source' => 'external', 'severities' => ['high', 'critical']],
            'maintenance_overdue' => ['source' => 'manual', 'severities' => ['medium', 'high']],
            'inspection_failed' => ['source' => 'manual', 'severities' => ['high']],
            // Compliance alerts
            'safeguarding_concern' => ['source' => 'compliance', 'severities' => ['high', 'critical']],
            'training_expired' => ['source' => 'compliance', 'severities' => ['medium', 'high']],
            'training_expiring_soon' => ['source' => 'compliance', 'severities' => ['low', 'medium']],
            'dbs_check_expired' => ['source' => 'compliance', 'severities' => ['high', 'critical']],
            'consent_expired' => ['source' => 'compliance', 'severities' => ['medium', 'high']],
            'care_plan_review_overdue' => ['source' => 'compliance', 'severities' => ['medium', 'high']],
            'medication_error' => ['source' => 'compliance', 'severities' => ['high', 'critical']],
            'controlled_drug_discrepancy' => ['source' => 'compliance', 'severities' => ['critical']],
            'incident_reported' => ['source' => 'compliance', 'severities' => ['medium', 'high', 'critical']],
            'break_glass_access' => ['source' => 'compliance', 'severities' => ['high']],
        ];

        $statuses = ['open', 'ack', 'triaging', 'resolved', 'closed'];
        $escalationLevels = [0, 0, 0, 1, 2]; // Most alerts not escalated

        // Create a variety of alerts
        foreach (range(1, 50) as $i) {
            $alertTypeKey = array_rand($alertTypes);
            $alertConfig = $alertTypes[$alertTypeKey];
            $severity = $alertConfig['severities'][array_rand($alertConfig['severities'])];
            $status = $statuses[array_rand($statuses)];
            $escalationLevel = $escalationLevels[array_rand($escalationLevels)];

            $triggeredAt = now()->subMinutes(rand(5, 21600)); // Up to 15 days ago
            $acknowledgedAt = null;
            $resolvedAt = null;
            $closedAt = null;
            $acknowledgedBy = null;
            $resolvedBy = null;
            $closedBy = null;
            $assignedTo = null;
            $escalatedAt = null;

            // Set timestamps based on status
            if (in_array($status, ['ack', 'triaging', 'resolved', 'closed'])) {
                $acknowledgedAt = $triggeredAt->copy()->addMinutes(rand(1, 60));
                $acknowledgedBy = $users->random()->id;
            }

            if (in_array($status, ['triaging', 'resolved', 'closed'])) {
                $assignedTo = $users->random()->id;
            }

            if (in_array($status, ['resolved', 'closed'])) {
                $resolvedAt = $acknowledgedAt ? $acknowledgedAt->copy()->addMinutes(rand(10, 180)) : null;
                $resolvedBy = $users->random()->id;
            }

            if ($status === 'closed') {
                $closedAt = $resolvedAt ? $resolvedAt->copy()->addMinutes(rand(5, 60)) : null;
                $closedBy = $users->random()->id;
            }

            if ($escalationLevel > 0) {
                $escalatedAt = $acknowledgedAt ? $acknowledgedAt->copy()->addMinutes(rand(30, 120)) : $triggeredAt->copy()->addMinutes(rand(60, 240));
            }

            $asset = $assets->isNotEmpty() && rand(0, 1) ? $assets->random() : null;

            $notes = null;
            if (rand(0, 1)) {
                $noteOptions = [
                    'Contacted driver - false alarm',
                    'Investigating further',
                    'Escalated to supervisor',
                    'Awaiting response from site',
                    'Client confirmed safe',
                    'Scheduled follow-up call',
                    'Maintenance team notified',
                    'Battery replacement scheduled',
                    'Training renewal booked',
                    'DBS check application submitted',
                    'Consent form resent to family',
                    'Safeguarding lead notified',
                    'Medication audit completed',
                    'Care coordinator contacted',
                    'Compliance team reviewing',
                ];
                $notes = $noteOptions[array_rand($noteOptions)];
            }

            ControlRoomAlert::create([
                'source' => $alertConfig['source'],
                'alert_type' => str_replace('_', ' ', ucwords($alertTypeKey, '_')),
                'severity' => $severity,
                'status' => $status,
                'asset_id' => $asset?->id,
                'triggered_at' => $triggeredAt,
                'acknowledged_at' => $acknowledgedAt,
                'acknowledged_by_user_id' => $acknowledgedBy,
                'resolved_at' => $resolvedAt,
                'resolved_by_user_id' => $resolvedBy,
                'closed_at' => $closedAt,
                'closed_by_user_id' => $closedBy,
                'escalated_at' => $escalatedAt,
                'escalation_level' => $escalationLevel,
                'assigned_to_user_id' => $assignedTo,
                'notes' => $notes,
                'context' => [
                    'seeded' => true,
                    'location' => $asset ? 'Asset location' : 'Unknown',
                ],
            ]);
        }

        // Create some recent critical alerts that are still open (for realistic dashboard)
        foreach (range(1, 5) as $i) {
            $criticalTypes = ['sos_button', 'fall_detected', 'geofence_exit', 'device_offline', 'check_in_missed'];
            $alertTypeKey = $criticalTypes[array_rand($criticalTypes)];
            $alertConfig = $alertTypes[$alertTypeKey];

            ControlRoomAlert::create([
                'source' => $alertConfig['source'],
                'alert_type' => str_replace('_', ' ', ucwords($alertTypeKey, '_')),
                'severity' => 'critical',
                'status' => 'open',
                'asset_id' => $assets->isNotEmpty() ? $assets->random()->id : null,
                'triggered_at' => now()->subMinutes(rand(1, 30)),
                'escalation_level' => rand(0, 1),
                'context' => ['seeded' => true, 'urgent' => true],
            ]);
        }
    }
}
