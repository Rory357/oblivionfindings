<?php

use App\Domain\SecurityDevices\Drivers\Alarms\AjaxHubAlarmDriver;
use App\Domain\SecurityDevices\Drivers\Alarms\ArrowheadEliteAlarmDriver;
use App\Domain\SecurityDevices\Drivers\Alarms\BoschSolutionAlarmDriver;
use App\Domain\SecurityDevices\Drivers\Alarms\DscPowerSeriesNeoDriver;
use App\Domain\SecurityDevices\Drivers\Alarms\IctProtegeAlarmDriver;
use App\Domain\SecurityDevices\Drivers\Alarms\InnerRangeIntegritiDriver;
use App\Domain\SecurityDevices\Drivers\Alarms\ParadoxAlarmDriver;
use App\Domain\SecurityDevices\Drivers\Contracts\AlarmPanelDriver;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Drivers\Services\DeviceDriverRegistry;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Phase A guard (docs/security-devices/GOAL.md, section 2): nothing claims more than it does.
 */

/** @return list<string> driver keys with a recorded walk in docs/security-devices/HARDWARE-WALKS.md */
function recordedHardwareWalks(): array
{
    $path = base_path('docs/security-devices/HARDWARE-WALKS.md');
    expect(is_file($path))->toBeTrue('docs/security-devices/HARDWARE-WALKS.md is missing.');

    preg_match_all('/^\|\s*`([a-z0-9_.]+)`\s*\|/m', (string) file_get_contents($path), $matches);

    return array_values(array_unique($matches[1]));
}

/** Controls that must never be claimed by a view-only driver. */
const VIEW_ONLY_FORBIDDEN_CAPABILITIES = [
    DriverCapability::AlarmArm,
    DriverCapability::AlarmDisarm,
    DriverCapability::AccessDoorUnlock,
    DriverCapability::NetworkPoeCycle,
    DriverCapability::NetworkReboot,
    DriverCapability::ComputerRestart,
    DriverCapability::ComputerServiceControl,
];

/** Controls with no working adapter yet; no driver may claim them until one is built and walked. */
const UNBUILT_CAPABILITIES = [
    DriverCapability::NetworkPoeCycle,
    DriverCapability::NetworkReboot,
];

it('lets a driver say "Tested on real hardware" only with a recorded walk', function (): void {
    $walked = recordedHardwareWalks();
    $claiming = [];

    foreach (app(DeviceDriverRegistry::class)->all() as $driver) {
        $meta = $driver->metadata();

        if ($meta->supportLevel === DriverSupportLevel::TestedOnHardware) {
            $claiming[] = $meta->key;
            expect($walked)->toContain($meta->key, "{$meta->key} claims real-hardware testing with no walk in HARDWARE-WALKS.md.");
            expect(trim((string) $meta->hardwareEvidence))->not->toBe('', "{$meta->key} needs hardwareEvidence describing its walk.");
        } else {
            expect($meta->hardwareEvidence)->toBeNull("{$meta->key} shows hardware evidence without claiming a walk.");
        }
    }

    foreach ($walked as $key) {
        expect($claiming)->toContain($key, "HARDWARE-WALKS.md lists {$key}, but that driver doesn't claim real-hardware testing.");
    }
});

it('marks every report-only alarm panel view only, with no arm, disarm or unlock', function (): void {
    foreach (app(DeviceDriverRegistry::class)->all() as $driver) {
        $meta = $driver->metadata();

        if ($meta->deviceClass === DriverDeviceClass::AlarmPanel && ! $driver instanceof AlarmPanelDriver) {
            expect($meta->viewOnly)->toBeTrue("{$meta->key} can't control a panel, so it must be view only.");
        }

        if ($meta->viewOnly) {
            foreach (VIEW_ONLY_FORBIDDEN_CAPABILITIES as $control) {
                expect($meta->capabilities)->not->toContain($control, "{$meta->key} is view only but claims {$control->value}.");
            }
        }
    }
});

it('keeps the seven report-only alarm brands view only', function (string $key): void {
    $meta = app(DeviceDriverRegistry::class)->find($key)?->metadata();

    expect($meta)->not->toBeNull()
        ->and($meta->viewOnly)->toBeTrue()
        ->and($meta->toArray()['view_only'])->toBeTrue()
        ->and($meta->capabilities)->not->toContain(DriverCapability::AlarmArm)
        ->and($meta->capabilities)->not->toContain(DriverCapability::AlarmDisarm);
})->with([
    'Ajax' => AjaxHubAlarmDriver::KEY,
    'Arrowhead' => ArrowheadEliteAlarmDriver::KEY,
    'Bosch' => BoschSolutionAlarmDriver::KEY,
    'DSC' => DscPowerSeriesNeoDriver::KEY,
    'ICT Protege' => IctProtegeAlarmDriver::KEY,
    'Inner Range Integriti' => InnerRangeIntegritiDriver::KEY,
    'Paradox' => ParadoxAlarmDriver::KEY,
]);

it('claims no control that has no working adapter yet', function (): void {
    foreach (app(DeviceDriverRegistry::class)->all() as $driver) {
        foreach (UNBUILT_CAPABILITIES as $unbuilt) {
            expect($driver->metadata()->capabilities)->not->toContain($unbuilt, "{$driver->key()} claims {$unbuilt->value}, which isn't built.");
        }
    }
});
