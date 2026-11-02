<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create([

            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    }

    public function test_cctv_media_permission_is_explicit_and_not_granted_to_general_operational_roles(): void
    {
        $permission = Permission::query()->where('key', 'securityDevices.cctv.media.view')->first();

        $this->assertNotNull($permission);
        $this->assertTrue($this->admin->canDo('securityDevices.cctv.media.view'));
        $this->assertFalse($this->viewerWithRole('provider_manager')->canDo('securityDevices.cctv.media.view'));
        $this->assertTrue($this->viewerWithRole('it_manager')->canDo('securityDevices.cctv.media.view'));
    }

    private function viewerWithRole(string $role): User
    {
        $viewer = User::factory()->create([

            'approved_at' => now(),
        ]);
        $viewer->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $viewer;
    }

    private function assignToSite(Device $device, Site $site): void
    {
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
        ]);
    }

    private function assignToStaff(Device $device, User $viewer): void
    {
        $device->assignments()->active()->update(['released_at' => now()]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_STAFF,
            'assignable_id' => $viewer->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
        ]);
    }

    private function assignToClient(Device $device, Client $client): void
    {
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
        ]);
    }

    private function createControlRoomAlert(Device $device, Site $site, string $title): ControlRoomAlert
    {
        $projection = ControlRoomDevice::create([
            'canonical_device_id' => $device->id,
            'name' => $device->name,
            'type' => match ($device->category) {
                'cctv' => ControlRoomDevice::TYPE_CAMERA,
                'access_control' => ControlRoomDevice::TYPE_DOOR,
                default => ControlRoomDevice::TYPE_ALARM_PANEL,
            },
            'site_id' => $site->id,
            'status' => 'online',
        ]);

        return ControlRoomAlert::factory()->critical()->create([
            'source' => 'security_devices',
            'alert_type' => $title,
            'device_id' => $projection->id,
            'site_id' => $site->id,
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
    }

}
