<?php

use App\Console\Commands\SeedDemoUnifiSwitchCommand;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
});

it('refuses to run outside local/testing or when app.url or database host points to oblivionfindings.com', function (): void {
    $originalUrl = config('app.url');
    config()->set('app.url', 'https://oblivionfindings.com');

    $this->artisan('security-devices:seed-demo-switch')
        ->expectsOutputToContain('Refused')
        ->assertExitCode(1);

    config()->set('app.url', $originalUrl);

    $defaultConn = (string) config('database.default', 'mysql');
    $originalDriver = config("database.connections.{$defaultConn}.driver");
    $originalHost = config("database.connections.{$defaultConn}.host");
    config()->set("database.connections.{$defaultConn}.driver", 'mysql');
    config()->set("database.connections.{$defaultConn}.host", 'db.oblivionfindings.com');

    $this->artisan('security-devices:seed-demo-switch')
        ->expectsOutputToContain('Refused')
        ->assertExitCode(1);

    config()->set("database.connections.{$defaultConn}.driver", $originalDriver);
    config()->set("database.connections.{$defaultConn}.host", $originalHost);

    $originalEnv = app()['env'];
    app()['env'] = 'production';
    try {
        $this->artisan('security-devices:seed-demo-switch')
            ->expectsOutputToContain('Refused')
            ->assertExitCode(1);
    } finally {
        app()['env'] = $originalEnv;
    }

    expect(Device::query()->where('device_uid', SeedDemoUnifiSwitchCommand::DEMO_SWITCH_UID)->exists())->toBeFalse();
});

it('is never registered in DatabaseSeeder', function (): void {
    $seederContents = (string) file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

    expect($seederContents)
        ->not->toContain('SeedDemoUnifiSwitchCommand')
        ->not->toContain('seed-demo-switch');
});

it('idempotently seeds one demo UniFi switch whose Ports, History and Settings tabs render cleanly', function (): void {
    $site = Site::factory()->create(['name' => 'Totara House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $otherSite = Site::factory()->create(['name' => 'Rimu Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);

    $itAdmin = User::factory()->create(['name' => 'Jordan Peri', 'approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $itAdmin->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'position_title' => 'IT Lead',
        'is_active' => true,
    ]);
    foreach ([
        'securityDevices.viewAny',
        'securityDevices.devices.view',
        'securityDevices.devices.update',
        'securityDevices.events.view',
        'securityDevices.monitoring.manage',
        'securityDevices.integrations.manage',
        'securityDevices.terminal.use',
    ] as $key) {
        $itAdmin->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
    }

    $otherViewer = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $otherViewer->id,
        'primary_site_id' => $otherSite->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);
    foreach (['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view'] as $key) {
        $otherViewer->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
    }

    $this->artisan('security-devices:seed-demo-switch', ['--site' => $site->id])->assertExitCode(0);
    $this->artisan('security-devices:seed-demo-switch', ['--site' => $site->id])->assertExitCode(0);

    $switches = Device::query()->where('device_uid', SeedDemoUnifiSwitchCommand::DEMO_SWITCH_UID)->get();
    expect($switches)->toHaveCount(1);

    $switch = $switches->firstOrFail();
    expect(Monitor::query()->where('device_id', $switch->id)->where('kind', MonitorKind::SnmpInterface)->count())->toBe(8)
        ->and(DeviceRelationship::query()->active()->where('parent_device_id', $switch->id)->count())->toBe(4);

    $this->actingAs($itAdmin)
        ->get("/security-devices/devices/{$switch->id}?tab=ports")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'ports')
            ->where('ports.total', 8)
            ->where('ports.rows.0.port', 3)
            ->where('ports.rows.0.errors', 184)
            ->where('ports.rows.0.problem', true)
            ->where('ports.tally.free', 2)
            ->where('ports.tally.poe_w', 30.5));

    $this->actingAs($itAdmin)
        ->get("/security-devices/devices/{$switch->id}?tab=history")
        ->assertOk()
        ->assertInertia(function ($page): void {
            $page->where('tab', 'history');
            $titles = collect($page->toArray()['props']['history']['lines'])->pluck('title')->all();
            expect($titles)->toContain('Comms cupboard switch (local demo) is offline');
        });

    $this->actingAs($itAdmin)
        ->get("/security-devices/devices/{$switch->id}?tab=settings")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'settings')
            ->where('settings.watching.tell_it_update_ready', true)
            ->where('settings.about.looked_after_by.id', $itAdmin->id)
            // Audit fix (5 Nov): the SSH user comes from the saved login; nothing here overrides it.
            ->where('settings.ssh', null));

    foreach (['overview', 'ports', 'history', 'settings'] as $tab) {
        $this->actingAs($otherViewer)
            ->get("/security-devices/devices/{$switch->id}?tab={$tab}")
            ->assertNotFound();
    }
});
