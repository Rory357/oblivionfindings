<?php

namespace App\Domain\SecurityDevices\Drivers\Services;

use App\Domain\SecurityDevices\Drivers\Contracts\AlarmPanelDriver;
use App\Domain\SecurityDevices\Drivers\Contracts\CameraDeviceDriver;
use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Contracts\NetworkConfigBackupDriver;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

final class DeviceDriverRegistry
{
    /** @var array<string, DeviceDriver> */
    private array $drivers = [];

    /**
     * @param  iterable<DeviceDriver>  $drivers
     */
    public function __construct(iterable $drivers = [])
    {
        foreach ($drivers as $driver) {
            $this->drivers[$driver->key()] = $driver;
        }
    }

    /**
     * @return list<DeviceDriver>
     */
    public function all(): array
    {
        $items = array_values($this->drivers);

        usort($items, static function (DeviceDriver $left, DeviceDriver $right): int {
            $leftMeta = $left->metadata();
            $rightMeta = $right->metadata();

            $classCmp = $leftMeta->deviceClass->sortOrder() <=> $rightMeta->deviceClass->sortOrder();
            if ($classCmp !== 0) {
                return $classCmp;
            }

            $levelCmp = $leftMeta->supportLevel->sortOrder() <=> $rightMeta->supportLevel->sortOrder();
            if ($levelCmp !== 0) {
                return $levelCmp;
            }

            return strcasecmp($leftMeta->brand.' '.$leftMeta->name, $rightMeta->brand.' '.$rightMeta->name);
        });

        return $items;
    }

    public function find(string $key): ?DeviceDriver
    {
        return $this->drivers[$key] ?? null;
    }

    /**
     * @return list<DeviceDriver>
     */
    public function forClass(DriverDeviceClass $class): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (DeviceDriver $driver): bool => $driver->metadata()->deviceClass === $class,
        ));
    }

    public function resolveForDevice(Device $device): ?DeviceDriver
    {
        $explicitKey = trim((string) (data_get($device->config, 'driver_key') ?? data_get($device->meta, 'driver_key') ?? ''));
        if ($explicitKey !== '' && isset($this->drivers[$explicitKey])) {
            return $this->drivers[$explicitKey];
        }

        foreach ($this->drivers as $driver) {
            if ($driver->metadata()->supportLevel !== DriverSupportLevel::Planned && $driver->matchesDevice($device)) {
                return $driver;
            }
        }

        foreach ($this->drivers as $driver) {
            if ($driver->matchesDevice($device)) {
                return $driver;
            }
        }

        return null;
    }

    public function resolveCameraDriver(Device $device): ?CameraDeviceDriver
    {
        $explicitKey = trim((string) (data_get($device->config, 'driver_key') ?? data_get($device->meta, 'driver_key') ?? ''));
        if ($explicitKey !== '' && isset($this->drivers[$explicitKey]) && $this->drivers[$explicitKey] instanceof CameraDeviceDriver) {
            return $this->drivers[$explicitKey];
        }

        foreach ($this->drivers as $driver) {
            if ($driver instanceof CameraDeviceDriver && $driver->matchesDevice($device)) {
                return $driver;
            }
        }

        return null;
    }

    public function resolveAlarmDriver(Device $device): ?AlarmPanelDriver
    {
        $explicitKey = trim((string) (data_get($device->config, 'driver_key') ?? data_get($device->meta, 'driver_key') ?? ''));
        if ($explicitKey !== '' && isset($this->drivers[$explicitKey]) && $this->drivers[$explicitKey] instanceof AlarmPanelDriver) {
            return $this->drivers[$explicitKey];
        }

        foreach ($this->drivers as $driver) {
            if ($driver instanceof AlarmPanelDriver
                && $driver->metadata()->supportLevel !== DriverSupportLevel::Planned
                && $driver->matchesDevice($device)) {
                return $driver;
            }
        }

        return null;
    }

    public function resolveNetworkConfigBackupDriver(Device $device): ?NetworkConfigBackupDriver
    {
        $explicitKey = trim((string) (data_get($device->config, 'driver_key') ?? data_get($device->meta, 'driver_key') ?? ''));
        if ($explicitKey !== '' && isset($this->drivers[$explicitKey]) && $this->drivers[$explicitKey] instanceof NetworkConfigBackupDriver) {
            return $this->drivers[$explicitKey];
        }

        foreach ($this->drivers as $driver) {
            if ($driver instanceof NetworkConfigBackupDriver
                && $driver->metadata()->supportLevel !== DriverSupportLevel::Planned
                && $driver->matchesDevice($device)) {
                return $driver;
            }
        }

        return null;
    }
}
