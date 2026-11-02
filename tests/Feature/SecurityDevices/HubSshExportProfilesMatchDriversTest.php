<?php

use App\Domain\SecurityDevices\Drivers\Network\ArubaAosConfigDriver;
use App\Domain\SecurityDevices\Drivers\Network\CiscoIosConfigDriver;
use App\Domain\SecurityDevices\Drivers\Network\FortinetFortiOsConfigDriver;
use App\Domain\SecurityDevices\Drivers\Network\MikroTikRouterOsConfigDriver;
use App\Domain\SecurityDevices\Models\Device;

/*
 * S&D Phase B: the hub runs settings backups with its own copy of the drivers' read-only export
 * commands (the server sends only a profile name). The two copies must say the same thing.
 */

it('gives the hub exactly the export commands and error words the server drivers use', function (): void {
    require_once base_path('collector/src/Runtime/SshExportProfiles.php');
    $profiles = Oblivion\Collector\Runtime\SshExportProfiles::PROFILES;
    $drivers = [
        app(MikroTikRouterOsConfigDriver::class),
        app(CiscoIosConfigDriver::class),
        app(ArubaAosConfigDriver::class),
        app(FortinetFortiOsConfigDriver::class),
    ];

    expect(array_keys($profiles))->toEqualCanonicalizing(array_map(fn ($driver) => $driver->vendorFamily(), $drivers));
    foreach ($drivers as $driver) {
        expect($profiles[$driver->vendorFamily()])->toBe([
            'commands' => $driver->exportCommands(new Device),
            'cli_errors' => $driver->cliErrorPatterns(),
        ]);
    }
});
