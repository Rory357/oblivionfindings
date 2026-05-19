<?php

namespace Tests\Feature\Queclink;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AppSetting;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\Queclink\QueclinkAuditEvent;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkRawFrame;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueclinkHubControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    }

    public function test_hub_page_renders_for_authorised_admin()
    {
        $response = $this->actingAs($this->admin)
            ->get('/security-devices/integrations/queclink');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('security-devices/integrations/queclink-hub')
            ->has('listener')
            ->has('devices.paired')
            ->has('devices.pending')
            ->has('devices.rejected')
            ->has('targets.vehicles')
            ->has('targets.staff')
            ->has('targets.clients')
        );
    }

    public function test_hub_page_exposes_latest_device_configuration_snapshot()
    {
        $device = QueclinkDevice::create([
            'imei' => '867963069916998',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);

        QueclinkRawFrame::create([
            'queclink_device_id' => $device->id,
            'imei' => $device->imei,
            'direction' => 'inbound',
            'frame_type' => 'RESP',
            'command_word' => 'GTALM',
            'raw_frame' => '+RESP:GTALM,970204,867963069916998,GL30MEU,1,1,SRI,3,0,1,oblivionfindings.com,8090,oblivionfindings.com,8090,,5,1,0,30,0,,CFG,,GL30MEU,150,08E3,006F,1,30,,0,1200,,1,,,,1,1,0000,,,10,1,,1,2,1,0,20260518031500,0A10$',
            'parsed_payload' => [
                'event_type' => 'configuration_report',
                'config_total_packets' => 1,
                'config_current_packet' => 1,
                'config_text' => 'SRI,3,0,1,oblivionfindings.com,8090,oblivionfindings.com,8090,,5,1,0,30,0,,CFG,,GL30MEU,150,08E3,006F,1,30,,0,1200,,1,,,,1,1,0000,,,10,1,,1,2,1,0',
                'send_time' => '2026-05-18T03:15:00Z',
            ],
            'parse_ok' => true,
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/queclink')
            ->assertInertia(fn ($page) => $page
                ->where('devices.paired.0.configuration.available', true)
                ->where('devices.paired.0.configuration.summary.server.main_host', 'oblivionfindings.com')
                ->where('devices.paired.0.configuration.summary.global.continuous_send_interval_seconds', '30')
            );
    }

    public function test_save_settings_persists_port_and_hostname()
    {
        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/queclink/settings', [
                'port' => 9091,
                'public_hostname' => 'tracking.example.co.nz',
            ])
            ->assertRedirect();

        $this->assertSame('9091', (string) AppSetting::query()->where('key', 'queclink.listener.port')->value('value'));
        $this->assertSame('tracking.example.co.nz', AppSetting::query()->where('key', 'queclink.public_hostname')->value('value'));
    }

    public function test_save_settings_rejects_out_of_range_port()
    {
        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/queclink/settings', [
                'port' => 80,  // privileged port, should be rejected
                'public_hostname' => 'oblivion.example.com',
            ])
            ->assertSessionHasErrors('port');
    }

    public function test_claim_pending_device_as_vehicle_creates_assignment_and_asset_tracker()
    {
        $asset = Asset::factory()->create();
        $device = QueclinkDevice::create([
            'imei' => '864696060004173',
            'status' => QueclinkDevice::STATUS_PENDING,
            'model_hint' => 'GV500CG',
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                'pairing_type' => 'vehicle',
                'target_id' => $asset->id,
            ])
            ->assertRedirect();

        $device->refresh();
        $this->assertSame(QueclinkDevice::STATUS_PAIRED, $device->status);

        $this->assertDatabaseHas('asset_trackers', [
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => '864696060004173',
            'status' => 'paired',
        ]);

        $this->assertDatabaseHas('device_assignments', [
            'device_id' => $device->fresh()->device_id,
            'assignable_type' => 'vehicle',
            'assignable_id' => $asset->id,
            'released_at' => null,
        ]);
    }

    public function test_claim_as_staff_auto_creates_personal_tracker_asset()
    {
        $staff = User::factory()->create();
        $device = QueclinkDevice::create([
            'imei' => '864696060004173',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                'pairing_type' => 'staff',
                'target_id' => $staff->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('assets', [
            'category' => 'personal_tracker',
            'primary_driver_user_id' => $staff->id,
        ]);
    }

    public function test_claim_as_client_links_existing_valid_tracking_consent_when_not_supplied()
    {
        $client = Client::create(['first_name' => 'Amelia', 'last_name' => 'Wilson']);
        $consentType = ConsentType::create([
            'name' => 'Personal Tracker (Wandering Risk)',
            'category' => 'safety',
            'description' => 'Personal tracker consent',
            'purpose' => 'Resident safety tracking',
            'legal_basis' => 'Consent',
            'version' => 1,
            'active' => true,
        ]);
        $consentVersion = ConsentTypeVersion::create([
            'consent_type_id' => $consentType->id,
            'version' => 1,
            'description' => 'Personal tracker consent v1',
            'purpose' => 'Resident safety tracking',
            'legal_basis' => 'Consent',
            'effective_from' => now()->subDay(),
        ]);
        $consent = ClientConsent::create([
            'client_id' => $client->id,
            'consent_type_id' => $consentType->id,
            'consent_type_version_id' => $consentVersion->id,
            'status' => 'given',
            'given_at' => now()->subDay(),
            'expires_at' => now()->addDays(30),
        ]);
        $device = QueclinkDevice::create([
            'imei' => '867963069916998',
            'status' => QueclinkDevice::STATUS_PENDING,
            'model_hint' => 'GL30MEU',
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                'pairing_type' => 'client',
                'target_id' => $client->id,
            ])
            ->assertRedirect();

        $this->assertSame($consent->id, DeviceAssignment::query()
            ->where('device_id', $device->fresh()->device_id)
            ->where('assignable_type', 'client')
            ->value('consent_id'));

        $this->assertSame($consent->id, AssetTracker::query()
            ->where('vendor', 'queclink')
            ->where('device_uid', $device->imei)
            ->value('consent_id'));
    }

    public function test_reject_pending_device_marks_status_rejected()
    {
        $device = QueclinkDevice::create([
            'imei' => '864696060004173',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/reject")
            ->assertRedirect();

        $this->assertSame(QueclinkDevice::STATUS_REJECTED, $device->fresh()->status);
    }

    public function test_release_paired_device_returns_it_to_pending_tray()
    {
        $asset = Asset::factory()->create();
        $device = QueclinkDevice::create([
            'imei' => '864696060004173',
            'status' => QueclinkDevice::STATUS_PAIRED,
        ]);

        // First pair it properly so a tracker + assignment exist
        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                'pairing_type' => 'vehicle',
                'target_id' => $asset->id,
            ]);

        // Then release
        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/release")
            ->assertRedirect();

        $this->assertSame(QueclinkDevice::STATUS_PENDING, $device->fresh()->status);
    }

    public function test_send_command_queues_a_pending_command()
    {
        $asset = Asset::factory()->create();
        $device = QueclinkDevice::create([
            'imei' => '864696060004173',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GV500CG',
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/command", [
                'mode' => 'preset',
                'preset' => 'request_location',
            ])
            ->assertRedirect();

        $this->assertSame(1, QueclinkPendingCommand::query()->where('queclink_device_id', $device->id)->count());
        $cmd = QueclinkPendingCommand::first();
        $this->assertSame('GTRTO', $cmd->command_word);
        $this->assertStringStartsWith('AT+GTRTO=gv500cg,1,', $cmd->raw_command);
        $this->assertStringEndsWith('$', $cmd->raw_command);
    }

    public function test_read_device_configuration_queues_gl30_read_all_command()
    {
        $device = QueclinkDevice::create([
            'imei' => '867963069916998',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/configuration/read", [
                'section' => 'all',
            ])
            ->assertRedirect();

        $cmd = QueclinkPendingCommand::first();
        $this->assertSame('GTRTO', $cmd->command_word);
        $this->assertMatchesRegularExpression('/^AT\+GTRTO=gl30,2,,,,,,[0-9A-F]{4}\$$/', $cmd->raw_command);
    }

    public function test_per_section_read_queues_only_that_gl30_section()
    {
        $device = QueclinkDevice::create([
            'imei' => '867963069916998',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/configuration/server/read")
            ->assertRedirect();

        $cmd = QueclinkPendingCommand::first();
        $this->assertSame('GTRTO', $cmd->command_word);
        $this->assertMatchesRegularExpression('/^AT\+GTRTO=gl30,2,SRI,,,,,[0-9A-F]{4}\$$/', $cmd->raw_command);
    }

    public function test_generic_section_update_can_queue_watchdog_and_records_audit_event()
    {
        $device = QueclinkDevice::create([
            'imei' => '867963069916998',
            'tenant_id' => 1,
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/configuration/server", [
                'command' => 'dog',
                'mode' => 1,
                'reboot_interval' => 1,
                'reboot_time' => '0130',
                'report_before_reboot' => 1,
                'unit' => 0,
                'send_failure_timeout' => 60,
            ])
            ->assertRedirect();

        $cmd = QueclinkPendingCommand::first();
        $this->assertSame('GTDOG', $cmd->command_word);
        $this->assertStringContainsString('AT+GTDOG=gl30,1,,1,0130,,1,,0,,,60,', $cmd->raw_command);
        $this->assertDatabaseHas('queclink_audit_events', [
            'queclink_device_id' => $device->id,
            'event_type' => 'config_write',
            'section' => 'server',
            'raw_command' => $cmd->raw_command,
        ]);
    }

    public function test_command_queue_cancel_and_retry_are_audited()
    {
        $device = QueclinkDevice::create([
            'imei' => '867963069916998',
            'tenant_id' => 1,
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);
        $command = QueclinkPendingCommand::create([
            'queclink_device_id' => $device->id,
            'imei' => $device->imei,
            'tenant_id' => 1,
            'command_word' => 'GTRTO',
            'raw_command' => 'AT+GTRTO=gl30,1,,,,,,0001$',
            'serial_number' => '0001',
            'status' => QueclinkPendingCommand::STATUS_QUEUED,
            'created_by_user_id' => $this->admin->id,
            'expires_at' => now()->addMinute(),
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/commands/{$command->id}/cancel")
            ->assertRedirect();

        $this->assertSame(QueclinkPendingCommand::STATUS_CANCELLED, $command->fresh()->status);
        $this->assertNotNull($command->fresh()->cancelled_at);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/commands/{$command->id}/retry")
            ->assertRedirect();

        $this->assertSame(2, QueclinkPendingCommand::query()->count());
        $retry = QueclinkPendingCommand::query()->latest('id')->first();
        $this->assertSame(QueclinkPendingCommand::STATUS_QUEUED, $retry->status);
        $this->assertSame($command->raw_command, $retry->raw_command);
        $this->assertDatabaseHas('queclink_audit_events', [
            'queclink_device_id' => $device->id,
            'event_type' => 'cancel',
        ]);
        $this->assertDatabaseHas('queclink_audit_events', [
            'queclink_device_id' => $device->id,
            'event_type' => 'retry',
        ]);
    }

    public function test_bulk_action_queues_commands_for_selected_paired_devices_and_audits_each()
    {
        $devices = collect(range(1, 5))->map(fn (int $index) => QueclinkDevice::create([
            'imei' => '86796306991699'.$index,
            'tenant_id' => 1,
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]));

        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/queclink/bulk', [
                'device_ids' => $devices->pluck('id')->all(),
                'action' => 'resident_safety_profile',
            ])
            ->assertRedirect();

        $this->assertSame(5, QueclinkPendingCommand::query()->count());
        $this->assertSame(5, QueclinkPendingCommand::query()
            ->where('command_word', 'GTCFG')
            ->where('status', QueclinkPendingCommand::STATUS_QUEUED)
            ->count());
        $this->assertSame(5, QueclinkAuditEvent::query()
            ->where('event_type', 'bulk_apply')
            ->count());
    }

    public function test_update_server_registration_queues_gl30_sri_command()
    {
        $device = QueclinkDevice::create([
            'imei' => '867963069916998',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/configuration/server", [
                'report_mode' => 3,
                'manual_netreg' => 0,
                'buffer_mode' => 1,
                'main_host' => 'oblivionfindings.com',
                'main_port' => 8090,
                'backup_host' => 'oblivionfindings.com',
                'backup_port' => 8090,
                'heartbeat_interval_minutes' => 5,
                'sack_enable' => 1,
                'sms_ack_enable' => 0,
                'psm_network_hold_time_seconds' => 30,
                'protocol_format' => 0,
            ])
            ->assertRedirect();

        $cmd = QueclinkPendingCommand::first();
        $this->assertSame('GTSRI', $cmd->command_word);
        $this->assertStringContainsString('AT+GTSRI=gl30,3,0,1,oblivionfindings.com,8090,oblivionfindings.com,8090,,5,1,0,30,0,0,', $cmd->raw_command);
    }

    public function test_update_global_configuration_queues_gl30_cfg_command()
    {
        $device = QueclinkDevice::create([
            'imei' => '867963069916998',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/configuration/global", [
                'device_name' => 'GL30MEU',
                'gnss_timeout_seconds' => 150,
                'event_mask' => '08E3',
                'report_item_mask' => '006F',
                'mode_selection' => 1,
                'continuous_send_interval_seconds' => 30,
                'start_mode' => 0,
                'specified_time_of_day' => '1200',
                'wakeup_interval_hours' => 1,
                'gnss_enable' => 1,
                'agps_mode' => 1,
                'gsm_report' => '0000',
                'battery_low_percentage' => 10,
                'function_button_mode' => 1,
                'sos_report_mode' => 1,
                'wifi_report' => 2,
                'led_on' => 1,
                'charge_standby_mode' => 0,
            ])
            ->assertRedirect();

        $cmd = QueclinkPendingCommand::first();
        $this->assertSame('GTCFG', $cmd->command_word);
        $this->assertStringContainsString('AT+GTCFG=gl30,,GL30MEU,150,08E3,006F,1,30,,0,1200,,1,,,,1,1,0000,,,10,1,,1,2,1,0,', $cmd->raw_command);
    }

    public function test_resident_safety_profile_queues_gl30_cfg_command()
    {
        $device = QueclinkDevice::create([
            'imei' => '867963069916998',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/configuration/resident-safety-profile")
            ->assertRedirect();

        $cmd = QueclinkPendingCommand::first();
        $this->assertSame('GTCFG', $cmd->command_word);
        $this->assertStringContainsString('AT+GTCFG=gl30,,GL30MEU,150,08E3,006F,1,30,,0,1200,,1,,,,1,1,0000,,,20,1,,1,2,1,0,', $cmd->raw_command);
    }

    public function test_update_global_configuration_rejects_invalid_short_gl30_interval()
    {
        $device = QueclinkDevice::create([
            'imei' => '867963069916998',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);

        $this->actingAs($this->admin)
            ->from('/security-devices/integrations/queclink?tab=settings')
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/configuration/global", [
                'device_name' => 'GL30MEU',
                'gnss_timeout_seconds' => 150,
                'event_mask' => '08E3',
                'report_item_mask' => '006F',
                'mode_selection' => 1,
                'continuous_send_interval_seconds' => 2,
                'start_mode' => 0,
                'specified_time_of_day' => '1200',
                'wakeup_interval_hours' => 1,
                'gnss_enable' => 1,
                'agps_mode' => 1,
                'gsm_report' => '0000',
                'battery_low_percentage' => 10,
                'function_button_mode' => 1,
                'sos_report_mode' => 1,
                'wifi_report' => 2,
                'led_on' => 1,
                'charge_standby_mode' => 0,
            ])
            ->assertSessionHasErrors('continuous_send_interval_seconds');

        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    public function test_personal_tracker_command_uses_gl30_family_when_model_hint_is_blank()
    {
        $canonicalDevice = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'imei' => '867963069916998',
            'device_uid' => '867963069916998',
            'model' => null,
        ]);
        $device = QueclinkDevice::create([
            'imei' => '867963069916998',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'device_id' => $canonicalDevice->id,
            'model_hint' => null,
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/command", [
                'mode' => 'preset',
                'preset' => 'request_location',
            ])
            ->assertRedirect();

        $cmd = QueclinkPendingCommand::first();
        $this->assertSame('GTRTO', $cmd->command_word);
        $this->assertStringStartsWith('AT+GTRTO=gl30,1,', $cmd->raw_command);
        $this->assertStringEndsWith('$', $cmd->raw_command);
    }

    public function test_frames_endpoint_returns_paged_json_for_authorised_user()
    {
        QueclinkRawFrame::create([
            'imei' => '864696060004173',
            'direction' => 'inbound',
            'frame_type' => 'RESP',
            'command_word' => 'GTHBD',
            'raw_frame' => '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
            'parse_ok' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/security-devices/integrations/queclink/frames');

        $response->assertOk()
            ->assertJsonStructure(['frames' => [['id', 'imei', 'direction', 'frame_type', 'raw_frame']]])
            ->assertJsonCount(1, 'frames');
    }

    public function test_frames_endpoint_filters_by_command_parse_status_and_search()
    {
        QueclinkRawFrame::create([
            'imei' => '864696060004173',
            'direction' => 'inbound',
            'frame_type' => 'RESP',
            'command_word' => 'GTHBD',
            'raw_frame' => '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
            'parse_ok' => true,
        ]);

        QueclinkRawFrame::create([
            'imei' => '867963069916998',
            'direction' => 'inbound',
            'frame_type' => 'RESP',
            'command_word' => 'GTALM',
            'raw_frame' => '+RESP:GTALM,bad-payload$',
            'parse_ok' => false,
            'parse_error' => 'bad payload',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/security-devices/integrations/queclink/frames?command_word=GTALM&parse_status=error&search=bad');

        $response->assertOk()
            ->assertJsonCount(1, 'frames')
            ->assertJsonPath('frames.0.command_word', 'GTALM')
            ->assertJsonPath('frames.0.parse_ok', false);
    }

    public function test_provisioning_string_requires_hostname_setting()
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/security-devices/integrations/queclink/provisioning?family=gv500cg');

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Set the public hostname under Listener settings first.');
    }

    public function test_provisioning_string_generates_a_t_gtsr_i_with_configured_hostname_and_port()
    {
        AppSetting::create(['key' => 'queclink.public_hostname', 'value' => 'tracking.example.co.nz']);
        AppSetting::create(['key' => 'queclink.listener.port', 'value' => 8091]);

        $response = $this->actingAs($this->admin)
            ->getJson('/security-devices/integrations/queclink/provisioning?family=gv500cg');

        $response->assertOk();
        $data = $response->json();
        $this->assertStringContainsString('AT+GTSRI=gv500cg,3,,1,tracking.example.co.nz,8091', $data['config_string']);
        $this->assertStringEndsWith('$', $data['config_string']);
        $this->assertNotEmpty($data['instructions']);
    }

    public function test_gl30m_provisioning_string_uses_gl30meu_server_registration_shape()
    {
        AppSetting::create(['key' => 'queclink.public_hostname', 'value' => 'tracking.example.co.nz']);
        AppSetting::create(['key' => 'queclink.listener.port', 'value' => 8091]);

        $response = $this->actingAs($this->admin)
            ->getJson('/security-devices/integrations/queclink/provisioning?family=gl30m');

        $response->assertOk()
            ->assertJsonPath(
                'config_string',
                'AT+GTSRI=gl30,3,0,1,tracking.example.co.nz,8091,tracking.example.co.nz,8091,,5,1,0,30,0,0,FFFF$',
            );
    }

    public function test_unauthorised_user_cannot_reach_hub()
    {
        $u = User::factory()->create();  // no permissions
        $this->actingAs($u)
            ->get('/security-devices/integrations/queclink')
            ->assertForbidden();
    }
}
