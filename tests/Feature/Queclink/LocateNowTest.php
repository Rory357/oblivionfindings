<?php

namespace Tests\Feature\Queclink;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Site;
use App\Services\Queclink\LocateNowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocateNowTest extends TestCase
{
    use RefreshDatabase;

    public function test_locate_now_hands_a_paired_tracker_to_the_governed_management_action(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'imei' => '861106050000000',
            'device_uid' => '861106050000000',
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
        QueclinkDevice::create([
            'imei' => '861106050000000',
            'device_id' => $device->id,
            'tenant_id' => 1,
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);

        $url = app(LocateNowService::class)->managementUrlForDevice($device);

        $this->assertSame(
            "/security-devices/devices/{$device->id}?section=management&action=tracking.location_refresh",
            $url,
        );
        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }
}
