<?php

namespace Tests\Feature\Queclink;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\User;
use App\Services\Queclink\LocateNowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocateNowTest extends TestCase
{
    use RefreshDatabase;

    public function test_locate_now_queues_gl30_request_location_command(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'imei' => '861106050000000',
            'device_uid' => '861106050000000',
        ]);
        $queclinkDevice = QueclinkDevice::create([
            'imei' => '861106050000000',
            'device_id' => $device->id,
            'tenant_id' => 1,
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);

        $command = app(LocateNowService::class)->queueForDevice($device, $user);

        $this->assertTrue($command->is($queclinkDevice->pendingCommands()->first()));
        $this->assertSame(QueclinkPendingCommand::STATUS_QUEUED, $command->status);
        $this->assertSame('GTRTO', $command->command_word);
        $this->assertStringStartsWith('AT+GTRTO=gl30,1,', $command->raw_command);
        $this->assertTrue($command->expires_at->isBetween(now()->addMinutes(4), now()->addMinutes(5)->addSecond()));
    }
}
