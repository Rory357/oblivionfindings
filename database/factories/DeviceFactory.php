<?php

namespace Database\Factories;

use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        $domain = fake()->randomElement(DeviceDomain::cases());

        return [
            'tenant_id' => 1,
            'name' => fake()->words(3, true),
            'domain' => $domain->value,
            'category' => 'network',
            'subcategory' => null,
            'manufacturer' => fake()->company(),
            'model' => fake()->word(),
            'serial_number' => fake()->unique()->numerify('SN-########'),
            'mac_address' => fake()->macAddress(),
            'status' => DeviceStatus::Active,
            'health_status' => HealthStatus::Healthy,
            'last_seen_at' => now(),
            'provider' => 'manual',
        ];
    }

    public function security(): static
    {
        return $this->state(fn () => [
            'domain' => DeviceDomain::Security->value,
            'category' => 'cctv',
            'subcategory' => 'dome_camera',
        ]);
    }

    public function tracking(): static
    {
        return $this->state(fn () => [
            'domain' => DeviceDomain::Tracking->value,
            'category' => 'personal_tracker',
            'subcategory' => 'wearable_gps',
        ]);
    }

    public function itInfrastructure(): static
    {
        return $this->state(fn () => [
            'domain' => DeviceDomain::ItInfrastructure->value,
            'category' => 'network',
            'subcategory' => 'wireless_ap',
        ]);
    }

    public function iotHealthcare(): static
    {
        return $this->state(fn () => [
            'domain' => DeviceDomain::IotHealthcare->value,
            'category' => 'fall_detection',
            'subcategory' => 'wearable_fall',
        ]);
    }

    public function facilities(): static
    {
        return $this->state(fn () => [
            'domain' => DeviceDomain::Facilities->value,
            'category' => 'cold_chain',
            'subcategory' => 'fridge_sensor',
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn () => [
            'status' => DeviceStatus::Offline,
            'health_status' => HealthStatus::Warning,
        ]);
    }

    public function decommissioned(): static
    {
        return $this->state(fn () => [
            'status' => DeviceStatus::Decommissioned,
        ]);
    }

    public function inStock(): static
    {
        return $this->state(fn () => [
            'status' => DeviceStatus::InStock,
            'health_status' => HealthStatus::Unknown,
            'last_seen_at' => null,
        ]);
    }

    public function withBattery(int $level = 85): static
    {
        return $this->state(fn () => [
            'battery_level' => $level,
            'battery_updated_at' => now(),
        ]);
    }

    public function lowBattery(): static
    {
        return $this->withBattery(10);
    }

    public function forProvider(string $provider): static
    {
        return $this->state(fn () => ['provider' => $provider]);
    }
}
