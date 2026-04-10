<?php

use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use Illuminate\Database\Migrations\Migration;

/**
 * PR1: Seed signal types and a default signal source for integration events.
 *
 * This enables integration-originated events (from Gallagher, Hikvision, UniFi, etc.)
 * to flow through the canonical signal pipeline → ControlRoomAlert, instead of the
 * deprecated ControlRoom\Alert (integration_alerts) model.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Create a generic integration signal source (provider-specific ones
        // are created lazily by AlertRoutingService::resolveSignalSource())
        SignalSource::firstOrCreate(
            ['slug' => 'integrations'],
            [
                'name' => 'External Integrations',
                'vendor' => 'multi-provider',
                'status' => 'active',
                'config' => [],
                'capabilities' => ['webhooks'],
            ]
        );

        // Seed signal types for the important integration event types.
        // These correspond to IntegrationSignalNormaliser::ALWAYS_ALERT_EVENT_TYPES
        // and other common integration events.
        $signalTypes = [
            [
                'code' => 'integration_device_offline',
                'name' => 'Device Offline',
                'category' => SignalType::CATEGORY_SECURITY,
                'default_severity' => 'medium',
                'description' => 'Integration device has gone offline',
            ],
            [
                'code' => 'integration_door_forced',
                'name' => 'Door Forced',
                'category' => SignalType::CATEGORY_SECURITY,
                'default_severity' => 'high',
                'description' => 'Door forced open without authorisation',
            ],
            [
                'code' => 'integration_sos_triggered',
                'name' => 'SOS Triggered',
                'category' => SignalType::CATEGORY_PEOPLE_SAFETY,
                'default_severity' => 'critical',
                'description' => 'SOS/panic button triggered on integration device',
            ],
            [
                'code' => 'integration_tamper_detected',
                'name' => 'Tamper Detected',
                'category' => SignalType::CATEGORY_SECURITY,
                'default_severity' => 'high',
                'description' => 'Device tamper detected',
            ],
            [
                'code' => 'integration_panic_alarm',
                'name' => 'Panic Alarm',
                'category' => SignalType::CATEGORY_PEOPLE_SAFETY,
                'default_severity' => 'critical',
                'description' => 'Panic alarm activated',
            ],
            [
                'code' => 'integration_duress_alarm',
                'name' => 'Duress Alarm',
                'category' => SignalType::CATEGORY_PEOPLE_SAFETY,
                'default_severity' => 'critical',
                'description' => 'Duress alarm activated — potential threat to person',
            ],
            [
                'code' => 'integration_communication_failure',
                'name' => 'Communication Failure',
                'category' => SignalType::CATEGORY_SECURITY,
                'default_severity' => 'medium',
                'description' => 'Communication link failure with integration device',
            ],
            [
                'code' => 'integration_power_failure',
                'name' => 'Power Failure',
                'category' => SignalType::CATEGORY_HOME_FACILITY,
                'default_severity' => 'high',
                'description' => 'Power failure detected at site',
            ],
            // General catch-all for integration events that don't match specific types
            [
                'code' => 'integration_unknown',
                'name' => 'Integration Event',
                'category' => SignalType::CATEGORY_SECURITY,
                'default_severity' => 'low',
                'description' => 'Generic integration event without a specific signal type',
            ],
        ];

        foreach ($signalTypes as $type) {
            SignalType::firstOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['is_active' => true])
            );
        }
    }

    public function down(): void
    {
        SignalType::whereIn('code', [
            'integration_device_offline',
            'integration_door_forced',
            'integration_sos_triggered',
            'integration_tamper_detected',
            'integration_panic_alarm',
            'integration_duress_alarm',
            'integration_communication_failure',
            'integration_power_failure',
            'integration_unknown',
        ])->delete();

        SignalSource::where('slug', 'integrations')->delete();
    }
};
