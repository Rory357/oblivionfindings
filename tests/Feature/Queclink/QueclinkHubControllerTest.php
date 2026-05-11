<?php

namespace Tests\Feature\Queclink;

use App\Models\AppSetting;
use App\Models\Asset;
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

    public function test_provisioning_string_requires_hostname_setting()
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/security-devices/integrations/queclink/provisioning?family=gv500cg');

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Set the public hostname under Listener settings first.');
    }

    public function test_provisioning_string_generates_AT_GTSRI_with_configured_hostname_and_port()
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

    public function test_unauthorised_user_cannot_reach_hub()
    {
        $u = User::factory()->create();  // no permissions
        $this->actingAs($u)
            ->get('/security-devices/integrations/queclink')
            ->assertForbidden();
    }
}
