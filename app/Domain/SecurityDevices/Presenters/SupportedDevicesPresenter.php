<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Drivers\Services\DeviceDriverRegistry;
use App\Models\User;

final readonly class SupportedDevicesPresenter
{
    public function __construct(
        private DeviceDriverRegistry $registry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(User $user): array
    {
        $drivers = $this->registry->all();
        $rows = [];
        $brands = [];
        $classCounts = [];
        $levelCounts = [
            DriverSupportLevel::TestedOnHardware->value => 0,
            DriverSupportLevel::BuiltFromDocs->value => 0,
            DriverSupportLevel::Planned->value => 0,
        ];

        foreach (DriverDeviceClass::cases() as $deviceClass) {
            $classCounts[$deviceClass->value] = 0;
        }

        foreach ($drivers as $driver) {
            $meta = $driver->metadata();
            $rows[] = $meta->toArray();
            $brands[strtolower($meta->brand)] = $meta->brand;
            $classCounts[$meta->deviceClass->value] = ($classCounts[$meta->deviceClass->value] ?? 0) + 1;
            $levelCounts[$meta->supportLevel->value] = ($levelCounts[$meta->supportLevel->value] ?? 0) + 1;
        }

        $classes = array_map(
            static fn (DriverDeviceClass $deviceClass): array => [
                'key' => $deviceClass->value,
                'label' => $deviceClass->label(),
                'count' => $classCounts[$deviceClass->value] ?? 0,
            ],
            DriverDeviceClass::cases(),
        );

        $supportLevels = array_map(
            static fn (DriverSupportLevel $level): array => [
                'key' => $level->value,
                'label' => $level->label(),
                'badge_variant' => $level->badgeVariant(),
                'count' => $levelCounts[$level->value] ?? 0,
            ],
            DriverSupportLevel::cases(),
        );

        return [
            'actor_id' => (int) $user->id,
            'generated_at' => now()->toImmutable()->toISOString(),
            'summary' => [
                'total_drivers' => count($rows),
                'brands_count' => count($brands),
                'tested_on_hardware' => $levelCounts[DriverSupportLevel::TestedOnHardware->value],
                'built_from_docs' => $levelCounts[DriverSupportLevel::BuiltFromDocs->value],
                'planned' => $levelCounts[DriverSupportLevel::Planned->value],
            ],
            'classes' => $classes,
            'support_levels' => $supportLevels,
            'drivers' => $rows,
        ];
    }
}
