<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\ControlRoomAlert;
use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\PlaybookStep;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
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

        $sources = [
            ['name' => 'UniFi Protect', 'slug' => 'unifi_protect', 'vendor' => 'ubiquiti'],
            ['name' => 'UniFi Access', 'slug' => 'unifi_access', 'vendor' => 'ubiquiti'],
            ['name' => 'UniFi Network', 'slug' => 'unifi_network', 'vendor' => 'ubiquiti'],
            ['name' => 'Queclink Fleet', 'slug' => 'queclink_fleet', 'vendor' => 'queclink'],
            ['name' => 'Personal Tracker', 'slug' => 'personal_tracker', 'vendor' => 'generic'],
            ['name' => 'Asset Tracker', 'slug' => 'asset_tracker', 'vendor' => 'generic'],
            ['name' => 'Shift Operations', 'slug' => 'shift_operations', 'vendor' => 'internal'],
            ['name' => 'Manual Entry', 'slug' => 'manual', 'vendor' => 'internal'],
        ];

        foreach ($sources as $source) {
            SignalSource::firstOrCreate(
                ['slug' => $source['slug']],
                array_merge($source, ['status' => 'active'])
            );
        }

        $signalTypes = [
            ['code' => 'panic_sos_person', 'name' => 'Panic/SOS (Person)', 'category' => SignalType::CATEGORY_PEOPLE_SAFETY, 'default_severity' => 'critical'],
            ['code' => 'panic_sos_vehicle', 'name' => 'Panic/SOS (Vehicle)', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'critical'],
            ['code' => 'fall_detected', 'name' => 'Fall Detected', 'category' => SignalType::CATEGORY_PEOPLE_SAFETY, 'default_severity' => 'critical'],
            ['code' => 'wandering_geofence_breach', 'name' => 'Wandering/Geofence Breach', 'category' => SignalType::CATEGORY_PEOPLE_SAFETY, 'default_severity' => 'high'],
            ['code' => 'missed_check_in', 'name' => 'Missed Check-in', 'category' => SignalType::CATEGORY_PEOPLE_SAFETY, 'default_severity' => 'high'],
            ['code' => 'bed_exit_night', 'name' => 'Bed Exit (Night)', 'category' => SignalType::CATEGORY_MEDICAL_WELLBEING, 'default_severity' => 'high'],
            ['code' => 'bed_absence_prolonged', 'name' => 'Prolonged Bed Absence', 'category' => SignalType::CATEGORY_MEDICAL_WELLBEING, 'default_severity' => 'medium'],
            ['code' => 'fire_alarm', 'name' => 'Fire Alarm', 'category' => SignalType::CATEGORY_HOME_FACILITY, 'default_severity' => 'critical'],
            ['code' => 'intrusion_alarm', 'name' => 'Intrusion Alarm', 'category' => SignalType::CATEGORY_HOME_FACILITY, 'default_severity' => 'high'],
            ['code' => 'door_forced_open', 'name' => 'Door Forced/Open', 'category' => SignalType::CATEGORY_HOME_FACILITY, 'default_severity' => 'high'],
            ['code' => 'access_denied_spike', 'name' => 'Access Denied Spike', 'category' => SignalType::CATEGORY_HOME_FACILITY, 'default_severity' => 'medium'],
            ['code' => 'power_outage', 'name' => 'Power Outage', 'category' => SignalType::CATEGORY_HOME_FACILITY, 'default_severity' => 'high'],
            ['code' => 'water_leak', 'name' => 'Water Leak', 'category' => SignalType::CATEGORY_HOME_FACILITY, 'default_severity' => 'high'],
            ['code' => 'temperature_extreme', 'name' => 'Temperature Extreme', 'category' => SignalType::CATEGORY_HOME_FACILITY, 'default_severity' => 'medium'],
            ['code' => 'network_offline', 'name' => 'Network Offline', 'category' => SignalType::CATEGORY_SECURITY, 'default_severity' => 'medium'],
            ['code' => 'camera_offline', 'name' => 'Camera Offline', 'category' => SignalType::CATEGORY_SECURITY, 'default_severity' => 'medium'],
            ['code' => 'camera_motion', 'name' => 'Camera Motion', 'category' => SignalType::CATEGORY_SECURITY, 'default_severity' => 'low'],
            ['code' => 'ai_detection', 'name' => 'AI Detection', 'category' => SignalType::CATEGORY_SECURITY, 'default_severity' => 'medium'],
            ['code' => 'asset_moved', 'name' => 'Asset Moved', 'category' => SignalType::CATEGORY_ASSETS, 'default_severity' => 'medium'],
            ['code' => 'asset_tamper', 'name' => 'Asset Tamper', 'category' => SignalType::CATEGORY_ASSETS, 'default_severity' => 'high'],
            ['code' => 'asset_geofence_breach', 'name' => 'Asset Geofence Breach', 'category' => SignalType::CATEGORY_ASSETS, 'default_severity' => 'high'],
            ['code' => 'fleet_speeding', 'name' => 'Fleet Speeding', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'medium'],
            ['code' => 'fleet_geofence_exit', 'name' => 'Fleet Geofence Exit', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'high'],
            ['code' => 'fleet_geofence_enter', 'name' => 'Fleet Geofence Enter', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'low'],
            ['code' => 'fleet_geofence_breach', 'name' => 'Fleet Geofence Breach', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'high'],
            ['code' => 'fleet_geofence_dwell', 'name' => 'Fleet Geofence Dwell', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'low'],
            ['code' => 'fleet_device_offline', 'name' => 'Fleet Device Offline', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'high'],
            ['code' => 'fleet_vehicle_offline', 'name' => 'Fleet Vehicle Offline', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'medium'],
            ['code' => 'fleet_vehicle_overdue', 'name' => 'Fleet Vehicle Overdue Return', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'high'],
            ['code' => 'fleet_incident_reported', 'name' => 'Fleet Incident Reported', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'high'],
            ['code' => 'fleet_incident_investigating', 'name' => 'Fleet Incident Status Change', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'medium'],
            ['code' => 'fleet_vehicle_sos', 'name' => 'Fleet Vehicle SOS', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'critical'],
            ['code' => 'fleet_device_tamper', 'name' => 'Fleet Device Tamper', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'high'],
            ['code' => 'fleet_wof_expiring', 'name' => 'Fleet WOF Expiring', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'medium'],
            ['code' => 'fleet_wof_expired', 'name' => 'Fleet WOF Expired', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'critical'],
            ['code' => 'fleet_registration_expiring', 'name' => 'Fleet Registration Expiring', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'medium'],
            ['code' => 'fleet_maintenance_overdue', 'name' => 'Fleet Maintenance Overdue', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'high'],
            ['code' => 'fleet_low_battery', 'name' => 'Fleet Low Battery', 'category' => SignalType::CATEGORY_FLEET, 'default_severity' => 'medium'],
        ];

        foreach ($signalTypes as $type) {
            SignalType::firstOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['debounce_seconds' => 60, 'is_active' => true])
            );
        }

        $queues = [
            [
                'name' => 'Tier 1',
                'code' => 'tier_1',
                'tier' => 1,
                'handle_severities' => ['low', 'medium'],
                'handle_sources' => ['manual', 'queclink_fleet', 'personal_tracker', 'asset_tracker', 'unifi_protect', 'unifi_access', 'unifi_network'],
                'assigned_roles' => ['coordinator', 'operator_t1'],
            ],
            [
                'name' => 'Tier 2',
                'code' => 'tier_2',
                'tier' => 2,
                'handle_severities' => ['high'],
                'assigned_roles' => ['supervisor', 'operator_t2'],
            ],
            [
                'name' => 'Emergency',
                'code' => 'emergency',
                'tier' => 3,
                'handle_severities' => ['critical'],
                'assigned_roles' => ['manager', 'operator_t3'],
            ],
        ];

        foreach ($queues as $queue) {
            TriageQueue::firstOrCreate(
                ['code' => $queue['code']],
                array_merge($queue, ['is_active' => true])
            );
        }

        $tier1 = TriageQueue::where('code', 'tier_1')->first();
        $tier2 = TriageQueue::where('code', 'tier_2')->first();
        $tier3 = TriageQueue::where('code', 'emergency')->first();

        if ($tier1 && $tier2) {
            $tier1->update(['escalate_to_queue_id' => $tier2->id, 'auto_escalate_after_minutes' => 20]);
        }
        if ($tier2 && $tier3) {
            $tier2->update(['escalate_to_queue_id' => $tier3->id, 'auto_escalate_after_minutes' => 30]);
        }

        $slaDefinitions = [
            [
                'name' => 'Critical Alerts',
                'code' => 'critical_default',
                'severities' => ['critical'],
                'acknowledge_target_minutes' => 2,
                'response_target_minutes' => 5,
                'resolution_target_minutes' => 30,
                'escalate_on_acknowledge_breach' => true,
                'escalate_on_response_breach' => true,
                'escalate_on_resolution_breach' => true,
            ],
            [
                'name' => 'High Alerts',
                'code' => 'high_default',
                'severities' => ['high'],
                'acknowledge_target_minutes' => 5,
                'response_target_minutes' => 15,
                'resolution_target_minutes' => 60,
                'escalate_on_acknowledge_breach' => true,
                'escalate_on_response_breach' => true,
            ],
            [
                'name' => 'Medium Alerts',
                'code' => 'medium_default',
                'severities' => ['medium'],
                'acknowledge_target_minutes' => 15,
                'response_target_minutes' => 60,
                'resolution_target_minutes' => 240,
                'escalate_on_response_breach' => true,
            ],
            [
                'name' => 'Low Alerts',
                'code' => 'low_default',
                'severities' => ['low'],
                'acknowledge_target_minutes' => 60,
                'response_target_minutes' => 240,
                'resolution_target_minutes' => 1440,
            ],
        ];

        foreach ($slaDefinitions as $sla) {
            SlaDefinition::firstOrCreate(
                ['code' => $sla['code']],
                array_merge($sla, ['is_active' => true])
            );
        }

        $playbooks = [
            ['code' => 'panic_sos_person', 'name' => 'Panic/SOS (Person)', 'category' => Playbook::CATEGORY_EMERGENCY, 'trigger_alert_types' => ['Panic/SOS (Person)'], 'trigger_severities' => ['critical'], 'auto_attach' => true],
            ['code' => 'panic_sos_vehicle', 'name' => 'Panic/SOS (Vehicle)', 'category' => Playbook::CATEGORY_EMERGENCY, 'trigger_alert_types' => ['Panic/SOS (Vehicle)'], 'trigger_severities' => ['critical'], 'auto_attach' => true],
            ['code' => 'fire_alarm', 'name' => 'Fire Alarm', 'category' => Playbook::CATEGORY_EMERGENCY, 'trigger_alert_types' => ['Fire Alarm'], 'trigger_severities' => ['critical'], 'auto_attach' => true],
            ['code' => 'intrusion_alarm', 'name' => 'Intrusion Alarm', 'category' => Playbook::CATEGORY_SAFETY, 'trigger_alert_types' => ['Intrusion Alarm'], 'trigger_severities' => ['high'], 'auto_attach' => true],
            ['code' => 'bed_exit_night', 'name' => 'Bed Exit (Night)', 'category' => Playbook::CATEGORY_SAFETY, 'trigger_alert_types' => ['Bed Exit (Night)'], 'trigger_severities' => ['high'], 'auto_attach' => true],
            ['code' => 'wandering', 'name' => 'Wandering/Geofence Breach', 'category' => Playbook::CATEGORY_SAFETY, 'trigger_alert_types' => ['Wandering/Geofence Breach'], 'trigger_severities' => ['high'], 'auto_attach' => true],
            ['code' => 'missed_check_in', 'name' => 'Missed Check-in', 'category' => Playbook::CATEGORY_SAFETY, 'trigger_alert_types' => ['Missed Check-in'], 'trigger_severities' => ['high'], 'auto_attach' => true],
            ['code' => 'camera_offline', 'name' => 'Camera Offline Investigation', 'category' => Playbook::CATEGORY_MAINTENANCE, 'trigger_alert_types' => ['Camera Offline'], 'trigger_severities' => ['medium'], 'auto_attach' => true],
            ['code' => 'network_offline', 'name' => 'Network Offline', 'category' => Playbook::CATEGORY_MAINTENANCE, 'trigger_alert_types' => ['Network Offline'], 'trigger_severities' => ['medium'], 'auto_attach' => true],
            ['code' => 'power_outage', 'name' => 'Power Outage', 'category' => Playbook::CATEGORY_MAINTENANCE, 'trigger_alert_types' => ['Power Outage'], 'trigger_severities' => ['high'], 'auto_attach' => true],
            ['code' => 'water_leak', 'name' => 'Water Leak', 'category' => Playbook::CATEGORY_MAINTENANCE, 'trigger_alert_types' => ['Water Leak'], 'trigger_severities' => ['high'], 'auto_attach' => true],
            ['code' => 'temperature_extreme', 'name' => 'Temperature Extreme', 'category' => Playbook::CATEGORY_MAINTENANCE, 'trigger_alert_types' => ['Temperature Extreme'], 'trigger_severities' => ['medium'], 'auto_attach' => true],
            ['code' => 'asset_tamper', 'name' => 'Asset Tamper', 'category' => Playbook::CATEGORY_INVESTIGATION, 'trigger_alert_types' => ['Asset Tamper'], 'trigger_severities' => ['high'], 'auto_attach' => true],
            ['code' => 'asset_geofence', 'name' => 'Asset Geofence Breach', 'category' => Playbook::CATEGORY_INVESTIGATION, 'trigger_alert_types' => ['Asset Geofence Breach'], 'trigger_severities' => ['high'], 'auto_attach' => true],
            ['code' => 'vehicle_geofence', 'name' => 'Vehicle Geofence Breach', 'category' => Playbook::CATEGORY_SAFETY, 'trigger_alert_types' => ['Fleet Geofence Exit'], 'trigger_severities' => ['high'], 'auto_attach' => true],
            ['code' => 'vehicle_speeding', 'name' => 'Vehicle Speeding', 'category' => Playbook::CATEGORY_SAFETY, 'trigger_alert_types' => ['Fleet Speeding'], 'trigger_severities' => ['medium'], 'auto_attach' => true],
            ['code' => 'device_offline', 'name' => 'Device Offline', 'category' => Playbook::CATEGORY_MAINTENANCE, 'trigger_alert_types' => ['Fleet Device Offline'], 'trigger_severities' => ['high'], 'auto_attach' => true],
            ['code' => 'false_positive_review', 'name' => 'False Positive Review', 'category' => Playbook::CATEGORY_INVESTIGATION, 'trigger_alert_types' => null, 'trigger_severities' => ['low', 'medium'], 'auto_attach' => false],
        ];

        foreach ($playbooks as $playbookData) {
            $playbook = Playbook::firstOrCreate(
                ['code' => $playbookData['code']],
                array_merge($playbookData, ['version' => 1, 'is_active' => true])
            );

            if ($playbook->steps()->count() === 0) {
                $steps = [
                    ['title' => 'Acknowledge alert', 'instructions' => 'Acknowledge and review the alert context.', 'type' => PlaybookStep::TYPE_TASK, 'is_required' => true],
                    ['title' => 'Assess safety', 'instructions' => 'Confirm client/staff safety and immediate risks.', 'type' => PlaybookStep::TYPE_TASK, 'is_required' => true],
                    ['title' => 'Document outcome', 'instructions' => 'Record actions taken and outcome summary.', 'type' => PlaybookStep::TYPE_EVIDENCE, 'is_required' => true],
                ];

                foreach ($steps as $index => $step) {
                    PlaybookStep::create(array_merge($step, [
                        'playbook_id' => $playbook->id,
                        'order' => $index,
                        'is_blocking' => $index === 0,
                    ]));
                }
            }
        }

        $rules = [
            ['name' => 'Panic/SOS person -> Critical', 'signal_type_code' => 'panic_sos_person', 'output_severity' => 'critical', 'output_tier' => 3],
            ['name' => 'Panic/SOS vehicle -> Critical', 'signal_type_code' => 'panic_sos_vehicle', 'output_severity' => 'critical', 'output_tier' => 3],
            ['name' => 'Bed exit at night -> High', 'signal_type_code' => 'bed_exit_night', 'output_severity' => 'high', 'output_tier' => 2, 'conditions' => ['time_of_day' => 'night']],
            ['name' => 'Fire alarm -> Critical', 'signal_type_code' => 'fire_alarm', 'output_severity' => 'critical', 'output_tier' => 3],
            ['name' => 'Network offline -> Medium', 'signal_type_code' => 'network_offline', 'output_severity' => 'medium', 'output_tier' => 1],
            ['name' => 'Fleet speeding -> Medium', 'signal_type_code' => 'fleet_speeding', 'output_severity' => 'medium', 'output_tier' => 1],
            ['name' => 'Fleet geofence exit -> High', 'signal_type_code' => 'fleet_geofence_exit', 'output_severity' => 'high', 'output_tier' => 2],
            ['name' => 'Fleet geofence breach -> High', 'signal_type_code' => 'fleet_geofence_breach', 'output_severity' => 'high', 'output_tier' => 2],
            ['name' => 'Fleet device offline -> High', 'signal_type_code' => 'fleet_device_offline', 'output_severity' => 'high', 'output_tier' => 2],
            ['name' => 'Fleet vehicle overdue -> High', 'signal_type_code' => 'fleet_vehicle_overdue', 'output_severity' => 'high', 'output_tier' => 2],
            ['name' => 'Fleet incident reported -> High', 'signal_type_code' => 'fleet_incident_reported', 'output_severity' => 'high', 'output_tier' => 2],
            ['name' => 'Fleet vehicle SOS -> Critical', 'signal_type_code' => 'fleet_vehicle_sos', 'output_severity' => 'critical', 'output_tier' => 3],
            ['name' => 'Fleet WOF expired -> Critical', 'signal_type_code' => 'fleet_wof_expired', 'output_severity' => 'critical', 'output_tier' => 2],
            ['name' => 'Fleet maintenance overdue -> High', 'signal_type_code' => 'fleet_maintenance_overdue', 'output_severity' => 'high', 'output_tier' => 1],
        ];

        foreach ($rules as $rule) {
            SignalRule::firstOrCreate(
                ['name' => $rule['name']],
                array_merge($rule, ['priority' => 10, 'is_active' => true])
            );
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
