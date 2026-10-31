<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkRawFrame;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class QueclinkUnitConsoleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create(['approved_at' => now()]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    }

    public function test_console_redacts_passwords_imeis_and_unconsented_coordinates_while_preserving_settings(): void
    {
        $site = Site::factory()->create(['name' => 'Harbour House', 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);

        $device = Device::factory()->tracking()->create([
            'name' => 'Care tracker 867963069916998',
            'provider' => 'queclink',
            'imei' => '867963069916998',
            'model' => 'GL320MG',
            'battery_level' => 88,
        ]);

        // Assigned to a resident WITHOUT location consent -> coordinates must be redacted!
        $assignment = DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now(),
            'consent_id' => null,
        ]);

        $tracker = QueclinkDevice::query()->create([
            'imei' => '867963069916998',
            'device_id' => $device->id,
            'binding_uuid' => (string) str()->uuid(),
            'model_hint' => 'GL320MG',
            'protocol_version' => '5E0100',
            'firmware_version' => 'R01A08V06',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'connection_state' => QueclinkDevice::CONN_CONNECTED,
            'first_seen_at' => now()->subDays(5),
            'last_seen_at' => now()->subMinute(),
            'last_frame_at' => now()->subMinute(),
        ]);

        // Outbound command containing plaintext password & inbound GTFRI frame containing IMEI + NZ coordinates
        QueclinkRawFrame::query()->create([
            'queclink_device_id' => $tracker->id,
            'canonical_device_id' => $device->id,
            'device_assignment_id' => $assignment->id,
            'imei' => '867963069916998',
            'direction' => QueclinkRawFrame::DIRECTION_OUTBOUND,
            'frame_type' => QueclinkRawFrame::FRAME_AT,
            'command_word' => 'GTRTO',
            'raw_frame' => '',
            'encrypted_raw_frame' => Crypt::encryptString('AT+GTRTO=SecretPass99,1,,,,,,0005$'),
            'parse_ok' => true,
        ]);

        QueclinkRawFrame::query()->create([
            'queclink_device_id' => $tracker->id,
            'canonical_device_id' => $device->id,
            'device_assignment_id' => $assignment->id,
            'imei' => '867963069916998',
            'direction' => QueclinkRawFrame::DIRECTION_INBOUND,
            'frame_type' => QueclinkRawFrame::FRAME_RESP,
            'command_word' => 'GTFRI',
            'raw_frame' => '',
            'encrypted_raw_frame' => Crypt::encryptString('+RESP:GTFRI,5E0100,867963069916998,,0,0,1,1,0.0,0,12.0,174.763336,-36.848461,20261028010000,,,,,,88,0006$'),
            'encrypted_parsed_payload' => Crypt::encryptString(json_encode([
                'latitude' => -36.848461,
                'longitude' => 174.763336,
                'battery_pct' => 88,
            ])),
            'parse_ok' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/security-devices/devices/{$device->id}/queclink-console")
            ->assertOk();

        $rawBody = $response->getContent();
        $this->assertStringNotContainsString('SecretPass99', $rawBody);
        $this->assertStringNotContainsString('867963069916998', $rawBody);
        $this->assertStringNotContainsString('174.763336', $rawBody);
        $this->assertStringNotContainsString('-36.848461', $rawBody);

        $response->assertJsonPath('unit.imei_masked', '8679•••••••6998');
        $response->assertJsonPath('last_position.state', 'consent_required');
        $response->assertJsonPath('last_position.latitude', null);
        $response->assertJsonPath('last_position.longitude', null);
        $response->assertJsonPath('last_position.battery_pct', 88);

        // Move tracker to Site equipment assignment -> historical unconsented resident frame MUST stay redacted,
        // while a new frame recorded under the Site assignment shows authorised coordinates.
        $assignment->update(['released_at' => now()]);
        $siteAssignment = DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now(),
        ]);

        $beforeSiteFrame = $this->actingAs($this->admin)
            ->getJson("/security-devices/devices/{$device->id}/queclink-console")
            ->assertOk();
        $beforeSiteFrame->assertJsonPath('last_position.state', 'no_fix_yet');
        $this->assertStringNotContainsString('174.763336', $beforeSiteFrame->getContent());

        QueclinkRawFrame::query()->create([
            'queclink_device_id' => $tracker->id,
            'canonical_device_id' => $device->id,
            'device_assignment_id' => $siteAssignment->id,
            'imei' => '867963069916998',
            'direction' => QueclinkRawFrame::DIRECTION_INBOUND,
            'frame_type' => QueclinkRawFrame::FRAME_RESP,
            'command_word' => 'GTFRI',
            'raw_frame' => '',
            'encrypted_raw_frame' => Crypt::encryptString('+RESP:GTFRI,5E0100,867963069916998,,0,0,1,1,0.0,0,12.0,174.776236,-41.286461,20261028020000,,,,,,91,0007$'),
            'encrypted_parsed_payload' => Crypt::encryptString(json_encode([
                'latitude' => -41.286461,
                'longitude' => 174.776236,
                'battery_pct' => 91,
            ])),
            'parse_ok' => true,
        ]);

        $authorisedRes = $this->actingAs($this->admin)
            ->getJson("/security-devices/devices/{$device->id}/queclink-console")
            ->assertOk();

        // Prior resident coordinates remain redacted in raw message history!
        $this->assertStringNotContainsString('174.763336', $authorisedRes->getContent());
        $authorisedRes->assertJsonPath('last_position.state', 'available');
        $authorisedRes->assertJsonPath('last_position.latitude', -41.286461);
        $authorisedRes->assertJsonPath('last_position.longitude', 174.776236);
    }

    public function test_safe_commands_queue_immediately_and_risky_commands_require_confirmation_and_reason(): void
    {
        $site = Site::factory()->create(['name' => 'Harbour House', 'is_active' => true]);
        $device = Device::factory()->tracking()->create([
            'name' => 'Care tracker GL320MG',
            'provider' => 'queclink',
            'imei' => '867963069916998',
            'model' => 'GL320MG',
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now(),
        ]);

        $tracker = QueclinkDevice::query()->create([
            'imei' => '867963069916998',
            'device_id' => $device->id,
            'binding_uuid' => (string) str()->uuid(),
            'model_hint' => 'GL320MG',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'connection_state' => QueclinkDevice::CONN_CONNECTED,
        ]);

        // Safe command: request_location
        $this->actingAs($this->admin)
            ->postJson("/security-devices/devices/{$device->id}/queclink-console/commands", [
                'command' => 'request_location',
            ])
            ->assertOk()
            ->assertJsonPath('command_word', 'GTRTO')
            ->assertJsonPath('status', QueclinkPendingCommand::STATUS_QUEUED);

        // Safe command: read_config (SRI)
        $this->actingAs($this->admin)
            ->postJson("/security-devices/devices/{$device->id}/queclink-console/commands", [
                'command' => 'read_config',
                'parameters' => ['section' => 'SRI'],
            ])
            ->assertOk()
            ->assertJsonPath('command_word', 'GTRTO');

        // Risky command without confirmation -> 422
        $this->actingAs($this->admin)
            ->postJson("/security-devices/devices/{$device->id}/queclink-console/commands", [
                'command' => 'reboot',
                'confirmed' => false,
                'reason' => 'Stuck cellular modem',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmed');

        // Risky command without audit reason -> 422
        $this->actingAs($this->admin)
            ->postJson("/security-devices/devices/{$device->id}/queclink-console/commands", [
                'command' => 'reboot',
                'confirmed' => true,
                'reason' => 'ok',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        // Risky command with confirmation + reason -> queued (GL30M family uses GTCFG)
        $this->actingAs($this->admin)
            ->postJson("/security-devices/devices/{$device->id}/queclink-console/commands", [
                'command' => 'update_reporting_interval',
                'parameters' => ['interval_seconds' => 300],
                'confirmed' => true,
                'reason' => 'Adjusting check-in cadence for shift trial',
            ])
            ->assertOk()
            ->assertJsonPath('command_word', 'GTCFG');

        $this->assertSame(
            3,
            QueclinkPendingCommand::query()->where('queclink_device_id', $tracker->id)->count(),
        );
    }
}
