<?php

namespace Tests\Feature\Queclink;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocateNowRoutesTest extends TestCase
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

    public function test_authorized_user_can_queue_locate_now_from_resident_tracking(): void
    {
        ['client' => $client, 'device' => $device] = $this->createPairedResidentTracker('861106050000001');

        $this->actingAs($this->admin)
            ->from('/fleet-assets/resident-tracking')
            ->post("/fleet-assets/resident-tracking/{$client->id}/locate-now")
            ->assertRedirect('/fleet-assets/resident-tracking')
            ->assertSessionHas('success');

        $command = QueclinkPendingCommand::first();

        $this->assertSame($device->id, $command->device->device_id);
        $this->assertSame('GTRTO', $command->command_word);
        $this->assertStringStartsWith('AT+GTRTO=gl30,1,', $command->raw_command);
        $this->assertTrue($command->expires_at->isBetween(now()->addMinutes(4), now()->addMinutes(5)->addSecond()));
    }

    public function test_authorized_user_can_queue_locate_now_from_client_location_tab(): void
    {
        ['client' => $client] = $this->createPairedResidentTracker('861106050000002');

        $this->actingAs($this->admin)
            ->from("/operations/clients/{$client->id}?tab=location")
            ->post("/operations/clients/{$client->id}/location/locate-now")
            ->assertRedirect("/operations/clients/{$client->id}?tab=location")
            ->assertSessionHas('success');

        $this->assertSame(1, QueclinkPendingCommand::query()->count());
        $this->assertSame('GTRTO', QueclinkPendingCommand::first()->command_word);
    }

    public function test_locate_now_requires_a_resident_tracker(): void
    {
        $client = Client::create(['first_name' => 'No', 'last_name' => 'Tracker']);

        $this->actingAs($this->admin)
            ->from('/fleet-assets/resident-tracking')
            ->post("/fleet-assets/resident-tracking/{$client->id}/locate-now")
            ->assertRedirect('/fleet-assets/resident-tracking')
            ->assertSessionHasErrors('tracker');

        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    public function test_locate_now_rejects_unpaired_or_non_queclink_tracker(): void
    {
        $client = Client::create(['first_name' => 'Manual', 'last_name' => 'Tracker']);
        $device = Device::factory()->tracking()->create([
            'provider' => 'manual',
            'imei' => 'MANUAL-001',
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $client->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->from("/operations/clients/{$client->id}?tab=location")
            ->post("/operations/clients/{$client->id}/location/locate-now")
            ->assertRedirect("/operations/clients/{$client->id}?tab=location")
            ->assertSessionHasErrors('tracker');

        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    /**
     * @return array{client: Client, device: Device, queclinkDevice: QueclinkDevice}
     */
    private function createPairedResidentTracker(string $imei): array
    {
        $client = Client::create(['first_name' => 'Amelia', 'last_name' => 'Wilson']);
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'imei' => $imei,
            'device_uid' => $imei,
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $client->id,
            'assigned_at' => now(),
        ]);
        $queclinkDevice = QueclinkDevice::create([
            'imei' => $imei,
            'device_id' => $device->id,
            'tenant_id' => 1,
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);

        return compact('client', 'device', 'queclinkDevice');
    }
}
