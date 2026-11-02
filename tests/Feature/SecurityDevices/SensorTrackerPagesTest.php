<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Iot\Readings\SensorReadingHistory;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkRawFrame;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * S&D rebuild Phase E: the sensor page (pg-sdr-7) and the care tracker page (pg-sdr-8, 8b, 8c).
 * Another Site's device is 404; a tracker's location never reaches the page without active consent;
 * a command is refused without securityDevices.trackers.command. Synthetic Sites and people only.
 */
class SensorTrackerPagesTest extends TestCase
{
    use RefreshDatabase;

    private const string IMEI = '867963000000017';

    private Site $home;

    private Site $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->home = Site::factory()->create(['name' => 'Synthetic House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->other = Site::factory()->create(['name' => 'Synthetic Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    }

    /** @param list<string> $keys */
    private function viewerWith(array $keys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->home->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function sensor(Site $site, array $attributes = []): Device
    {
        $device = Device::factory()->facilities()->create([
            'name' => 'Synthetic room sensor',
            'manufacturer' => 'Synthetic',
            'model' => 'AM-1',
            'provider' => 'mqtt',
            'battery_level' => 64,
            'location_description' => 'Room 3',
            ...$attributes,
        ]);
        $this->onSite($device, $site);

        return $device;
    }

    /** @param array<string, mixed> $attributes */
    private function tracker(Site $site, array $attributes = []): Device
    {
        $device = Device::factory()->tracking()->create([
            'name' => 'Synthetic care tracker',
            'provider' => 'queclink',
            'imei' => self::IMEI,
            'model' => 'GL320MG',
            'battery_level' => 77,
            ...$attributes,
        ]);
        $this->onSite($device, $site);
        QueclinkDevice::query()->create([
            'imei' => self::IMEI,
            'device_id' => $device->id,
            'binding_uuid' => (string) str()->uuid(),
            'model_hint' => 'GL320MG',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'connection_state' => QueclinkDevice::CONN_CONNECTED,
            'first_seen_at' => now()->subDays(5),
            'last_seen_at' => now()->subMinute(),
            'last_frame_at' => now()->subMinute(),
        ]);

        return $device;
    }

    private function onSite(Device $device, Site $site): void
    {
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now()->subDays(3),
        ]);
    }

    public function test_another_sites_sensor_and_tracker_are_404(): void
    {
        $viewer = $this->viewerWith([
            'securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view',
            'securityDevices.integrations.manage', 'securityDevices.commands.operate', 'securityDevices.commands.control',
            'securityDevices.trackers.command',
        ]);
        $sensor = $this->sensor($this->other, ['name' => 'Synthetic Lodge sensor']);
        $tracker = $this->tracker($this->other, ['name' => 'Synthetic Lodge tracker']);

        $this->actingAs($viewer)->get('/security-devices/devices/'.$sensor->id)->assertNotFound();
        $this->actingAs($viewer)->get('/security-devices/devices/'.$sensor->id.'?tab=alerts')->assertNotFound();
        $this->actingAs($viewer)->get('/security-devices/devices/'.$tracker->id)->assertNotFound();
        $this->actingAs($viewer)->get('/security-devices/devices/'.$tracker->id.'?tab=console')->assertNotFound();
        $this->actingAs($viewer)
            ->postJson('/security-devices/devices/'.$tracker->id.'/tracker/commands', ['command' => 'read_config'])
            ->assertNotFound();

        $this->assertSame(0, QueclinkPendingCommand::query()->count());
    }

    public function test_sensor_page_shows_readings_limits_battery_and_alerts(): void
    {
        $viewer = $this->viewerWith(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view']);
        $at = CarbonImmutable::now()->subMinutes(2);
        $sensor = $this->sensor($this->home, [
            'meta' => ['observed' => [
                'last_telemetry_at' => $at->toIso8601String(),
                'last_telemetry_protocol' => 'mqtt',
                'sensor_readings' => [
                    'temperature' => ['label' => 'Temperature', 'value' => 21.4, 'unit' => 'C', 'state' => 'healthy', 'observed_at' => $at->toIso8601String()],
                    'co2' => ['label' => 'CO2', 'value' => 1210, 'unit' => 'ppm', 'state' => 'warning', 'observed_at' => $at->toIso8601String()],
                    'rssi' => ['label' => 'Radio signal (RSSI)', 'value' => -87, 'unit' => 'dBm', 'state' => 'healthy', 'observed_at' => $at->toIso8601String()],
                ],
            ]],
        ]);
        $history = app(SensorReadingHistory::class);
        foreach ([180, 120, 60] as $minutes) {
            $history->record((int) $sensor->id, ['temperature' => 21.0, 'co2' => 900], CarbonImmutable::now()->subMinutes($minutes));
        }
        $alert = DeviceAlert::query()->create([
            'device_id' => $sensor->id,
            'site_id' => $this->home->id,
            'kind' => 'reading_co2',
            'severity' => 'warning',
            'title' => 'Synthetic room sensor: co2 1210 ppm',
            'state' => DeviceAlert::STATE_OPEN,
            'opened_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($viewer)
            ->get('/security-devices/devices/'.$sensor->id)
            ->assertOk()
            ->assertInertia(function ($page): void {
                $page->component('security-devices/devices/sensor')
                    ->where('device.kind', 'sensor')
                    ->where('tab', 'readings')
                    ->where('tabs', [['key' => 'readings', 'label' => 'Readings'], ['key' => 'alerts', 'label' => 'Alerts']])
                    ->where('meters.0.label', 'Temperature')
                    ->where('meters.0.value', '21.4 °C')
                    ->where('meters.1.label', 'CO₂')
                    ->where('meters.1.tone', 'warning')
                    ->where('meters.2.label', 'Battery')
                    ->where('meters.2.value', '64%')
                    ->where('readings.series.0.key', 'temperature')
                    ->where('readings.series.0.limits', [
                        ['value' => 12, 'label' => 'Alert below 12 °C'],
                        ['value' => 32, 'label' => 'Alert above 32 °C'],
                    ])
                    ->where('readings.series.1.limits.0.label', 'Alert at 1,000 ppm')
                    ->where('alerts', null);
                $props = $page->toArray()['props'];
                $this->assertCount(96, $props['readings']['series'][0]['points']);
                $this->assertNotEmpty(array_filter($props['readings']['series'][0]['points'], fn ($value) => $value !== null));
                // Signal is "where it is", never a reading of its own.
                $this->assertSame(['temperature', 'co2'], array_column($props['readings']['series'], 'key'));
                $this->assertContains(['label' => 'Signal', 'text' => '−87 dBm', 'at' => null], $props['readings']['where']['rows']);
            });

        $this->actingAs($viewer)
            ->get('/security-devices/devices/'.$sensor->id.'?tab=alerts&range=7d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('security-devices/devices/sensor')
                ->where('tab', 'alerts')
                ->where('range', '7d')
                ->where('readings', null)
                ->where('alerts.0.href', '/security-devices/alerts?alert='.$alert->id)
                ->where('alerts.0.sub', 'Still open'));
    }

    public function test_a_viewer_without_events_permission_gets_no_alerts_tab(): void
    {
        $viewer = $this->viewerWith(['securityDevices.viewAny', 'securityDevices.devices.view']);
        $sensor = $this->sensor($this->home);

        $this->actingAs($viewer)
            ->get('/security-devices/devices/'.$sensor->id.'?tab=alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('security-devices/devices/sensor')
                ->where('tab', 'readings')
                ->where('tabs', [['key' => 'readings', 'label' => 'Readings']])
                ->where('alerts', null));
    }

    public function test_tracker_location_is_hidden_without_active_consent(): void
    {
        $admin = User::factory()->create(['approved_at' => now()]);
        $admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $client = Client::factory()->create(['site_id' => $this->home->id, 'first_name' => 'Synthetic', 'preferred_name' => null]);
        $device = Device::factory()->tracking()->create([
            'name' => 'Synthetic care tracker',
            'provider' => 'queclink',
            'imei' => self::IMEI,
            'model' => 'GL320MG',
            'battery_level' => 77,
        ]);
        // Worn by a client WITHOUT a location consent.
        $assignment = DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now(),
            'consent_id' => null,
        ]);
        $unit = QueclinkDevice::query()->create([
            'imei' => self::IMEI,
            'device_id' => $device->id,
            'binding_uuid' => (string) str()->uuid(),
            'model_hint' => 'GL320MG',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'connection_state' => QueclinkDevice::CONN_CONNECTED,
            'last_seen_at' => now()->subMinute(),
            'last_frame_at' => now()->subMinute(),
        ]);
        QueclinkRawFrame::query()->create([
            'queclink_device_id' => $unit->id,
            'canonical_device_id' => $device->id,
            'device_assignment_id' => $assignment->id,
            'imei' => self::IMEI,
            'direction' => QueclinkRawFrame::DIRECTION_INBOUND,
            'frame_type' => QueclinkRawFrame::FRAME_RESP,
            'command_word' => 'GTFRI',
            'raw_frame' => '',
            'encrypted_raw_frame' => Crypt::encryptString('+RESP:GTFRI,5E0100,'.self::IMEI.',,0,0,1,1,0.0,0,12.0,174.763336,-36.848461,20261028010000,,,,,,77,0006$'),
            'encrypted_parsed_payload' => Crypt::encryptString(json_encode(['latitude' => -36.848461, 'longitude' => 174.763336, 'battery_pct' => 77])),
            'parse_ok' => true,
        ]);

        foreach (['', '?tab=console'] as $query) {
            $response = $this->actingAs($admin)->get('/security-devices/devices/'.$device->id.$query)->assertOk();
            $body = (string) $response->getContent();
            $this->assertStringNotContainsString('174.763336', $body);
            $this->assertStringNotContainsString('-36.848461', $body);
            $this->assertStringNotContainsString(self::IMEI, $body);
        }

        $this->actingAs($admin)
            ->get('/security-devices/devices/'.$device->id)
            ->assertInertia(function ($page): void {
                $page->component('security-devices/devices/tracker')
                    ->where('device.kind', 'care_tracker')
                    ->where('privacy.shown', false)
                    ->where('privacy.state', 'consent_required')
                    ->where('privacy.person.consent.active', false)
                    ->where('location.position', null)
                    ->where('location.fix.note', 'Location is hidden because Synthetic’s location consent is not active.')
                    ->where('meters.0.label', 'Last position')
                    ->where('meters.0.value', 'Hidden')
                    ->where('can.locate', false);
                $options = collect($page->toArray()['props']['commands']['options'])->pluck('key')->all();
                // Asking for a position is held back while the location can't be shown.
                $this->assertNotContains('request_location', $options);
                $this->assertContains('read_config', $options);
            });

        $this->actingAs($admin)
            ->postJson('/security-devices/devices/'.$device->id.'/tracker/commands', ['command' => 'request_location'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('command');
        $this->assertSame(0, QueclinkPendingCommand::query()->count());
    }

    public function test_a_tracker_command_is_refused_without_the_tracker_command_permission(): void
    {
        $base = [
            'securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.integrations.manage',
            'securityDevices.commands.operate', 'securityDevices.commands.control',
        ];
        $without = $this->viewerWith($base);
        $with = $this->viewerWith([...$base, 'securityDevices.trackers.command']);
        $device = $this->tracker($this->home);

        $this->actingAs($without)
            ->postJson('/security-devices/devices/'.$device->id.'/tracker/commands', ['command' => 'read_config'])
            ->assertForbidden();
        $this->actingAs($without)
            ->get('/security-devices/devices/'.$device->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('security-devices/devices/tracker')
                ->where('can.command', false)
                ->where('commands.options', []));
        $this->assertSame(0, QueclinkPendingCommand::query()->count());

        // A risky command needs a reason, even with the permission.
        $this->actingAs($with)
            ->postJson('/security-devices/devices/'.$device->id.'/tracker/commands', ['command' => 'reboot'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->actingAs($with)
            ->postJson('/security-devices/devices/'.$device->id.'/tracker/commands', ['command' => 'read_config'])
            ->assertOk()
            ->assertJsonPath('status', QueclinkPendingCommand::STATUS_QUEUED);
        $this->assertSame(1, QueclinkPendingCommand::query()->count());
        $this->assertSame('GTRTO', QueclinkPendingCommand::query()->sole()->command_word);
    }
}
