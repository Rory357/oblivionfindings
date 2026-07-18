<?php

namespace Database\Factories;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Monitor> */
class MonitorFactory extends Factory
{
    protected $model = Monitor::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'device_id' => Device::factory()->itInfrastructure(),
            'profile_id' => MonitoringProfile::factory(),
            'kind' => MonitorKind::Icmp,
            'name' => 'ICMP availability',
            'target' => fake()->ipv4(),
            'config' => [],
            'current_state' => MonitorState::Unknown,
            'pending_count' => 0,
            'affects_availability' => true,
            'is_enabled' => true,
        ];
    }
}
