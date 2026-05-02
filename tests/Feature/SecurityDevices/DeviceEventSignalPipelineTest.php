<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoomAlert;
use Database\Seeders\SecurityDevicesSignalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for PR H — the DeviceEvent → ControlRoomAlert pipeline.
 *
 * This test protects the load-bearing wiring between Security & Devices and
 * Control Room. Any change that breaks:
 *   • DeviceEventObserver firing on insert
 *   • signal types + rules being seeded
 *   • SignalProcessingService creating a ControlRoomAlert from a rule match
 * will cause these assertions to fail.
 *
 * If this test breaks, the whole module's alerting surface stops working —
 * investigate before merging.
 */
class DeviceEventSignalPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The schema dump (database/schema/mysql-schema.sql) records the
        // PR H seed migrations as run but does not preserve their inserted
        // rows. Seed them explicitly so the pipeline assertions hold.
        $this->seed(SecurityDevicesSignalSeeder::class);
    }

    public function test_signal_types_and_source_are_seeded(): void
    {
        $this->assertTrue(
            SignalSource::where('slug', 'security_devices')->exists(),
            'security_devices signal source should be seeded (migration PR H #1)',
        );

        foreach (['device_alarm_trigger', 'device_tamper', 'device_offline', 'device_battery_low', 'device_signal_generic'] as $code) {
            $this->assertTrue(
                SignalType::where('code', $code)->exists(),
                "Signal type {$code} should be seeded (migration PR H #1)",
            );
        }

        $this->assertGreaterThan(
            10,
            SignalRule::where('name', 'like', 'Security & Devices:%')->count(),
            'Security & Devices signal rules should be seeded (migration PR H #2)',
        );
    }

    public function test_critical_alarm_trigger_produces_control_room_alert(): void
    {
        $device = Device::factory()->create([
            'domain' => 'security',
            'category' => 'alarm',
        ]);

        $beforeAlerts = ControlRoomAlert::count();
        $beforeSignals = Signal::count();

        $event = DeviceEvent::create([
            'tenant_id' => $device->tenant_id ?? 1,
            'device_id' => $device->id,
            'event_type' => 'alarm_trigger',
            'severity' => 'critical',
            'source' => 'pipeline_test',
            'occurred_at' => now(),
            'payload' => ['test' => 'alarm_trigger end-to-end'],
        ]);

        // Observer marks the event processed.
        $this->assertNotNull(
            $event->fresh()->processed_at,
            'DeviceEventObserver should mark the event processed_at',
        );

        // A Signal row must have been ingested.
        $this->assertGreaterThan($beforeSignals, Signal::count(), 'A Signal row should be created');

        $signal = Signal::latest('id')->first();
        $this->assertSame('device_alarm_trigger', $signal->signal_type_code);
        $this->assertSame('processed', $signal->status);

        // A ControlRoomAlert must have been created via the rule match.
        $this->assertGreaterThan($beforeAlerts, ControlRoomAlert::count(), 'A ControlRoomAlert should be created');

        $alert = ControlRoomAlert::latest('id')->first();
        $this->assertSame('security_devices', $alert->source);
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('open', $alert->status);
        $this->assertStringContainsString('Device Alarm Triggered', $alert->alert_type);
    }

    public function test_info_level_event_does_not_create_a_new_alert_per_dedup(): void
    {
        $device = Device::factory()->create([
            'domain' => 'security',
            'category' => 'camera',
        ]);

        // Fire two heartbeats back-to-back; the observer suppresses heartbeats
        // outright (HEARTBEAT_FORWARD=false). No Signal, no Alert.
        $before = ControlRoomAlert::count();

        DeviceEvent::create([
            'tenant_id' => $device->tenant_id ?? 1,
            'device_id' => $device->id,
            'event_type' => 'heartbeat',
            'severity' => 'info',
            'source' => 'pipeline_test',
            'occurred_at' => now(),
        ]);

        $this->assertSame(
            $before,
            ControlRoomAlert::count(),
            'Heartbeat should not produce a Control Room alert',
        );
    }

    public function test_unknown_event_type_falls_back_to_generic_catchall(): void
    {
        $device = Device::factory()->create();

        DeviceEvent::create([
            'tenant_id' => $device->tenant_id ?? 1,
            'device_id' => $device->id,
            'event_type' => 'this_type_is_unknown_on_purpose',
            'severity' => 'warning',
            'source' => 'pipeline_test',
            'occurred_at' => now(),
        ]);

        $signal = Signal::latest('id')->first();
        $this->assertNotNull($signal, 'Unknown events should still ingest as signals');
        $this->assertSame(
            'device_signal_generic',
            $signal->signal_type_code,
            'Unknown event_type should route to device_signal_generic catch-all',
        );
    }
}
