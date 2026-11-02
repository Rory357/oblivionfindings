<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** S&D round 3: a camera that still answers but gives no picture "needs attention"; it isn't Offline. */
class DeviceAnswersWithoutPictureTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_failed_reachability_check_makes_a_device_offline(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $site = Site::factory()->create(['name' => 'Aurora House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $viewer = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $viewer->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach (['securityDevices.viewAny', 'securityDevices.devices.view'] as $key) {
            $viewer->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        $camera = fn (string $name, MonitorState $ping, ?MonitorState $picture): Device => tap(Device::factory()->security()->create(['name' => $name]), function (Device $device) use ($site, $ping, $picture): void {
            DeviceAssignment::create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $site->id, 'assigned_at' => now()->subDays(3)]);
            Monitor::factory()->create(['device_id' => $device->id, 'kind' => MonitorKind::Icmp, 'name' => 'Answers on the network', 'current_state' => $ping, 'effective_state' => $ping, 'affects_availability' => true]);
            if ($picture !== null) {
                Monitor::factory()->create(['device_id' => $device->id, 'kind' => MonitorKind::Http, 'name' => 'Picture', 'current_state' => $picture, 'effective_state' => $picture, 'affects_availability' => true]);
            }
        });
        $noPicture = $camera('Back yard camera', MonitorState::Healthy, MonitorState::Failed);
        $gone = $camera('Driveway camera', MonitorState::Failed, MonitorState::Failed);
        $onlyWeb = tap(Device::factory()->security()->create(['name' => 'Gate camera']), function (Device $device) use ($site): void {
            DeviceAssignment::create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $site->id, 'assigned_at' => now()->subDays(3)]);
            Monitor::factory()->create(['device_id' => $device->id, 'kind' => MonitorKind::Http, 'name' => 'Picture', 'current_state' => MonitorState::Failed, 'effective_state' => MonitorState::Failed, 'affects_availability' => true]);
        });

        $states = app(DeviceDirectory::class)->entries($viewer)->mapWithKeys(fn ($entry): array => [$entry->id() => $entry->state]);

        $this->assertSame(DeviceState::Problem, $states[$noPicture->id], 'It answers: no picture is a problem, not offline.');
        $this->assertSame(DeviceState::Offline, $states[$gone->id]);
        $this->assertSame(DeviceState::Offline, $states[$onlyWeb->id], 'Without a reachability check, its only availability check decides, as before.');
    }
}
