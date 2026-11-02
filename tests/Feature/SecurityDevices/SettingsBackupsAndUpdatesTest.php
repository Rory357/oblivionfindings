<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReferenceAuditEvent;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Credentials\Services\DeviceSavedLogin;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionAdapterRegistry;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionRouteResolver;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Management\Services\GovernedCommandDispatchService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\NetworkConfig\Contracts\NetworkCliSshTransport;
use App\Domain\SecurityDevices\NetworkConfig\Data\NetworkCliCommandResult;
use App\Domain\SecurityDevices\NetworkConfig\Services\NightlySettingsBackups;
use App\Domain\SecurityDevices\NetworkConfig\Services\SettingsLineDiff;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceUpdates;
use App\Domain\SecurityDevices\Screens\Devices\Pages\DeviceSettingsBackups;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * S&D Phase G (G1/G2): nightly settings backups through the governed request and the Site's hub;
 * pop-up 24 "Settings changed" (changed lines, redaction kept); Devices › Upkeep › Updates and pop-up
 * 62 (only what a maker's source said); pop-up 63 "Update the saved login" (permission, audit, and
 * never the secret). Synthetic Sites and TEST-ONLY values only.
 */

const SD_G_TRUSTED_KEY = 'SHA256:CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.signing.active_key_id', 'sd-g-backups');
    config()->set('monitoring.signing.keys', ['sd-g-backups' => base64_encode(str_repeat('G', SODIUM_CRYPTO_AUTH_KEYBYTES))]);
    $configPair = sodium_crypto_sign_seed_keypair(str_repeat("\x51", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey($configPair)));

    $this->site = Site::factory()->create(['name' => 'Synthetic Rimu House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->otherSite = Site::factory()->create(['name' => 'Synthetic Tōtara House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    DiscoveryScope::factory()->create([
        'site_id' => $this->site->id, 'collector_id' => null, 'cidrs' => ['10.77.0.0/24'], 'protocols' => ['tcp'],
        'port_bounds' => ['tcp' => [22]], 'exclusions' => [], 'status' => 'active',
    ]);
    $this->place = function (Device $device, Site $site): Device {
        DeviceAssignment::query()->create([
            'device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $site->id,
            'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
        ]);

        return $device->fresh();
    };
    $this->router = ($this->place)(Device::factory()->create([
        'name' => 'Synthetic Rimu router', 'domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => 'router',
        'manufacturer' => 'MikroTik', 'model' => 'hAP ax3', 'provider' => 'mikrotik', 'ip_address' => '10.77.0.1', 'last_seen_at' => now(),
        'config' => ['ssh' => ['host_key_fingerprint' => SD_G_TRUSTED_KEY]],
    ]), $this->site);
    $this->reference = CredentialReference::query()->create([
        'reference_key' => 'database:mikrotik/'.$this->site->id.'/ssh', 'site_id' => $this->site->id, 'provider' => 'mikrotik',
        'purpose' => 'device_management', 'capabilities' => ['command:network.config.backup'],
        'secret_manager_reference' => 'oblivion/sites/'.$this->site->id.'/mikrotik/device_management/sd-g-test',
        'secret_manager_reference_hash' => hash('sha256', 'sd-g-ssh-'.$this->site->id),
        'status' => CredentialReferenceStatus::Active, 'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed, 'version' => 1, 'last_tested_at' => now(),
    ]);

    $hubPair = sodium_crypto_sign_seed_keypair(str_repeat("\x52", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $hubPublic = sodium_crypto_sign_publickey($hubPair);
    $this->hub = MonitoringCollector::factory()->create([
        'site_id' => $this->site->id, 'name' => 'Synthetic Rimu House', 'collector_uuid' => (string) Str::uuid(),
        'public_key' => base64_encode($hubPublic), 'public_key_fingerprint' => hash('sha256', $hubPublic),
        'client_certificate_fingerprint' => hash('sha256', 'sd-g-hub-certificate'),
        'status' => 'online', 'last_seen_at' => now(), 'approved_at' => now()->subDay(), 'revoked_at' => null,
    ]);

    $this->app->instance(SnapshotStore::class, new class implements SnapshotStore
    {
        /** @var array<string, string> */
        public array $objects = [];

        public function put(string $path, string $contents): void
        {
            $this->objects[$path] = $contents;
        }

        public function read(string $path): string
        {
            return $this->objects[$path] ?? throw new RuntimeException('Missing '.$path);
        }

        public function delete(string $path): void
        {
            unset($this->objects[$path]);
        }

        public function exists(string $path): bool
        {
            return isset($this->objects[$path]);
        }
    });
    $this->app->instance(DnsResolver::class, new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            return ['10.77.0.1'];
        }
    });
    $this->app->instance(CredentialLeaseProvider::class, new class implements CredentialLeaseProvider
    {
        public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
        {
            return new CredentialLease('lease-'.Str::uuid(), now()->toImmutable()->addMinutes(5), ['username' => 'backup_ro', 'password' => 'SD-G-TEST-ONLY-LEASE-PASSWORD']);
        }
    });
    // The server's own SSH path is never used while a hub covers the Site.
    $this->direct = new class implements NetworkCliSshTransport
    {
        public int $calls = 0;

        public function runReadOnlyExport(AuthorizedProbeTarget $target, array $credentialMaterial, string $command, ?string $expectedHostKeyFingerprint = null, int $timeoutSeconds = 15): NetworkCliCommandResult
        {
            $this->calls++;

            return new NetworkCliCommandResult(ok: false, reasonCode: 'ssh_transport_error', output: '');
        }
    };
    $this->app->instance(NetworkCliSshTransport::class, $this->direct);
    foreach ([ConfigurationSnapshotService::class, ApprovedProbeScopeProvider::class, EgressPolicy::class, CommandCredentialLeaseService::class,
        CommandExecutionAdapterRegistry::class, CommandExecutionRouteResolver::class, DeviceCommandRequestService::class,
        GovernedCommandDispatchService::class] as $service) {
        $this->app->forgetInstance($service);
    }

    // The person the nightly run asks as: a facilities manager at the Site (as a person's "Back up now").
    $this->manager = User::factory()->create(['approved_at' => now(), 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
    $this->manager->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
    HrEmployeeProfile::factory()->create(['user_id' => $this->manager->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);

    $this->person = function (array $keys, ?Site $site = null): User {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    };

    $this->capture = fn (Device $device, Site $site, array $lines, CarbonImmutable $at): ConfigurationSnapshot => app(ConfigurationSnapshotService::class)->captureFromCommand(
        device: $device,
        siteId: $site->id,
        source: 'mikrotik_routeros_ssh_backup',
        payload: ['running_config' => implode("\n", $lines), 'manufacturer' => 'MikroTik', 'model' => 'hAP ax3', 'firmware_version' => '7.16.2'],
        capturedAt: $at,
    );
});

/* ------------------------------------------------------------------ */
/*  Nightly backups (G1)                                               */
/* ------------------------------------------------------------------ */

it('does nothing at night until someone is named to ask for the backups', function (): void {
    config()->set('security_devices.nightly_settings_backups.requested_by_user_id', 0);

    $this->artisan('security-devices:nightly-settings-backups')
        ->expectsOutputToContain('Nightly settings backups are off')
        ->assertSuccessful();

    expect(DeviceCommandRequest::query()->count())->toBe(0);
});

it('asks for one backup a night per eligible device, through the hub that covers the Site and as the named person', function (): void {
    config()->set('security_devices.nightly_settings_backups.requested_by_user_id', $this->manager->id);
    // Not eligible: no saved login for its brand at the Site.
    $noLogin = ($this->place)(Device::factory()->create([
        'name' => 'Synthetic Rimu second router', 'domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => 'router',
        'manufacturer' => 'Cisco', 'model' => 'C1111', 'provider' => 'cisco_ios', 'ip_address' => '10.77.0.9', 'last_seen_at' => now(),
    ]), $this->site);
    // Not eligible: another Site, with no saved login there.
    $elsewhere = ($this->place)(Device::factory()->create([
        'name' => 'Synthetic Tōtara router', 'domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => 'router',
        'manufacturer' => 'MikroTik', 'model' => 'hAP ax3', 'provider' => 'mikrotik', 'ip_address' => '10.88.0.1', 'last_seen_at' => now(),
    ]), $this->otherSite);

    $this->artisan('security-devices:nightly-settings-backups')->assertSuccessful();
    // A second run the same night asks for nothing more.
    $this->artisan('security-devices:nightly-settings-backups')->assertSuccessful();

    $request = DeviceCommandRequest::query()->where('device_id', $this->router->id)->sole();
    expect((int) $request->requested_by_user_id)->toBe((int) $this->manager->id)
        ->and($request->capability)->toBe('network.config.backup')
        ->and((int) $request->collector_id)->toBe((int) $this->hub->id)
        ->and($request->reason)->toBe(NightlySettingsBackups::REASON)
        ->and($request->idempotency_key)->toStartWith('nightly-settings-backup:'.$this->router->id.':')
        ->and($request->status)->toBeIn([CommandStatus::Queued, CommandStatus::Dispatching])
        ->and(DeviceCommandAttempt::query()->where('device_command_request_id', $request->id)->where('runtime', '!=', 'collector')->exists())->toBeFalse()
        ->and(DeviceCommandRequest::query()->whereIn('device_id', [$noLogin->id, $elsewhere->id])->exists())->toBeFalse()
        ->and($this->direct->calls)->toBe(0);
});

it('never falls back to the server at night when the covering hub is offline', function (): void {
    config()->set('security_devices.nightly_settings_backups.requested_by_user_id', $this->manager->id);
    $this->hub->forceFill(['status' => 'offline'])->save();

    $this->artisan('security-devices:nightly-settings-backups')
        ->expectsOutputToContain('Skipped (cant_reach): 1')
        ->assertSuccessful();

    expect(DeviceCommandRequest::query()->count())->toBe(0)
        ->and($this->direct->calls)->toBe(0);
});

it('stops asking once the named person may no longer see the device', function (): void {
    $stranger = User::factory()->create(['approved_at' => now()]);
    $stranger->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
    HrEmployeeProfile::factory()->create(['user_id' => $stranger->id, 'primary_site_id' => $this->otherSite->id, 'secondary_site_ids' => [], 'is_active' => true]);
    config()->set('security_devices.nightly_settings_backups.requested_by_user_id', $stranger->id);

    $this->artisan('security-devices:nightly-settings-backups')
        ->expectsOutputToContain('Refused: 1')
        ->assertSuccessful();

    expect(DeviceCommandRequest::query()->count())->toBe(0);
});

/* ------------------------------------------------------------------ */
/*  Pop-up 24, Settings changed                                        */
/* ------------------------------------------------------------------ */

it('shows the changed lines side by side, counts real lines and keeps passwords redacted', function (): void {
    $before = ($this->capture)($this->router, $this->site, [
        '/interface bridge add name=br-lan',
        '/ip firewall filter add chain=forward action=accept src-address=10.77.0.0/24',
        '/user add name=backup group=read password=TEST-ONLY-old-secret',
        '/interface wireless set wlan1 ssid=Synthetic-Staff',
    ], CarbonImmutable::now('UTC')->subDays(2));
    $after = ($this->capture)($this->router, $this->site, [
        '/system note set note=Synthetic',
        '/interface bridge add name=br-lan',
        '/ip firewall filter add chain=forward action=drop src-address=10.77.0.50',
        '/user add name=backup group=read password=TEST-ONLY-new-secret',
        '/interface wireless set wlan1 ssid=Synthetic-Staff-2',
    ], CarbonImmutable::now('UTC')->subDay());
    $viewer = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.reports.view']);

    $response = $this->actingAs($viewer)
        ->getJson(route('security-devices.devices.settings-backups.changes', ['device' => $this->router->id, 'snapshot' => $after->id]))
        ->assertOk()
        ->assertJsonPath('before.id', $before->id)
        ->assertJsonPath('after.id', $after->id)
        ->assertJsonPath('lines', 3)
        ->assertJsonPath('added', 3)
        ->assertJsonPath('removed', 2)
        ->assertJsonPath('too_many', false)
        ->assertJsonPath('changed_by.known', false)
        ->assertJsonPath('changed_by.terminal', null);

    $rows = collect($response->json('rows'));
    expect($rows->where('kind', 'added')->pluck('right.text')->all())->toBe(['/system note set note=Synthetic'])
        ->and($rows->where('kind', 'changed')->pluck('left.text')->all())->toContain('/ip firewall filter add chain=forward action=accept src-address=10.77.0.0/24')
        ->and($rows->where('kind', 'changed')->pluck('right.text')->all())->toContain('/interface wireless set wlan1 ssid=Synthetic-Staff-2')
        // The password line was redacted when stored, so both copies read the same.
        ->and($rows->where('kind', 'same')->pluck('left.text')->all())->toContain('/user add name=backup group=read password=[REDACTED]')
        ->and($response->getContent())->not->toContain('TEST-ONLY-old-secret')
        ->and($response->getContent())->not->toContain('TEST-ONLY-new-secret');

    // The device page says "3 lines changed": a line count, not the shifted-index count (5).
    $history = app(DeviceSettingsBackups::class)->history($viewer, $this->router, $this->site->id);
    expect($history[0]['id'])->toBe($after->id)->and($history[0]['changes'])->toBe(3);

    $download = $this->actingAs($viewer)
        ->get(route('security-devices.devices.settings-backups.download', ['device' => $this->router->id, 'snapshot' => $after->id]))
        ->assertOk();
    expect($download->headers->get('Cache-Control'))->toContain('no-store')
        ->and($download->headers->get('Content-Type'))->toContain('text/plain')
        ->and($download->getContent())->toContain('# Before: Synthetic Rimu router')
        ->and($download->getContent())->toContain('# After: Synthetic Rimu router')
        ->and($download->getContent())->toContain('password=[REDACTED]')
        ->and($download->getContent())->not->toContain('TEST-ONLY-');
});

it('keeps another Site’s backups out (404) and needs the snapshot permission (403)', function (): void {
    $first = ($this->capture)($this->router, $this->site, ['/interface bridge add name=br-lan'], CarbonImmutable::now('UTC')->subDay());
    $elsewhere = ($this->place)(Device::factory()->create([
        'name' => 'Synthetic Tōtara router', 'domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => 'router',
        'manufacturer' => 'MikroTik', 'model' => 'hAP ax3', 'provider' => 'mikrotik', 'ip_address' => '10.88.0.1', 'last_seen_at' => now(),
    ]), $this->otherSite);
    $theirs = ($this->capture)($elsewhere, $this->otherSite, ['/interface bridge add name=br-other'], CarbonImmutable::now('UTC')->subDay());
    $viewer = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.reports.view']);

    $this->actingAs($viewer)->getJson(route('security-devices.devices.settings-backups.changes', ['device' => $elsewhere->id, 'snapshot' => $theirs->id]))->assertNotFound();
    $this->actingAs($viewer)->getJson(route('security-devices.devices.settings-backups.changes', ['device' => $this->router->id, 'snapshot' => $theirs->id]))->assertNotFound();
    $this->actingAs($viewer)->get(route('security-devices.devices.settings-backups.download', ['device' => $elsewhere->id, 'snapshot' => $theirs->id]))->assertNotFound();

    $noSnapshots = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view']);
    $this->actingAs($noSnapshots)->getJson(route('security-devices.devices.settings-backups.changes', ['device' => $this->router->id, 'snapshot' => $first->id]))->assertForbidden();

    // The first copy has nothing before it: it says so instead of inventing a change.
    $this->actingAs($viewer)->getJson(route('security-devices.devices.settings-backups.changes', ['device' => $this->router->id, 'snapshot' => $first->id]))
        ->assertOk()
        ->assertJsonPath('before', null)
        ->assertJsonPath('lines', null)
        ->assertJsonPath('rows', []);
});

it('bounds the line diff and says when there are too many changes', function (): void {
    $diff = new SettingsLineDiff;
    $lines = array_map(fn (int $n): string => '/line set n='.$n, range(1, 3000));
    $rewritten = array_map(fn (string $line): string => $line.' changed', $lines);

    expect($diff->compare($lines, $rewritten)['too_many'])->toBeTrue()
        ->and($diff->compare(array_fill(0, SettingsLineDiff::MAX_LINES + 1, 'a'), [])['too_many'])->toBeTrue()
        ->and($diff->compare(['a', 'b', 'c'], ['a', 'x', 'c'])['lines'])->toBe(1);
});

/* ------------------------------------------------------------------ */
/*  Devices › Upkeep › Updates and pop-up 62                           */
/* ------------------------------------------------------------------ */

it('lists only devices a maker’s source says have an update, and never another Site’s', function (): void {
    $switch = ($this->place)(Device::factory()->create([
        'name' => 'Synthetic Rimu switch', 'domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => 'switch',
        'manufacturer' => 'Ubiquiti', 'model' => 'USW-24-PoE', 'provider' => 'unifi', 'firmware_version' => '6.6.61',
        'meta' => ['firmware_status' => 'updateAvailable', 'firmware_update_version' => '6.6.65'],
    ]), $this->site);
    ($this->place)(Device::factory()->create([
        'name' => 'Synthetic Rimu Wi-Fi', 'domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => 'access_point',
        'manufacturer' => 'Ubiquiti', 'model' => 'U7 Pro', 'provider' => 'unifi', 'firmware_version' => '7.0.1',
        'meta' => ['firmware_status' => 'upToDate'],
    ]), $this->site);
    // A target version someone typed in is not the maker saying an update is ready.
    ($this->place)(Device::factory()->create([
        'name' => 'Synthetic Rimu printer switch', 'domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => 'switch',
        'manufacturer' => 'Cisco', 'model' => 'CBS250', 'provider' => 'cisco_ios', 'firmware_version' => '3.1',
        'meta' => ['desired' => ['firmware_version' => '3.4']],
    ]), $this->site);
    ($this->place)(Device::factory()->create([
        'name' => 'Synthetic other Site switch', 'domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => 'switch',
        'manufacturer' => 'Ubiquiti', 'model' => 'USW-24-PoE', 'provider' => 'unifi', 'firmware_version' => '6.6.61',
        'meta' => ['firmware_status' => 'updateAvailable', 'firmware_update_version' => '6.6.65'],
    ]), $this->otherSite);
    $viewer = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view']);

    $response = $this->actingAs($viewer)
        ->get('/security-devices/devices?tab=upkeep&upkeep=updates')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('updates.source', true)
            ->count('updates.rows', 1)
            ->where('updates.rows.0.id', $switch->id)
            ->where('updates.rows.0.current', '6.6.61')
            ->where('updates.rows.0.next', '6.6.65')
            ->where('updates.rows.0.notes', null)
            ->where('updates.rows.0.install.href', DeviceUpdates::UNIFI_URL)
            ->where('updates.rows.0.plan.label', 'Not planned')
            ->where('updates.can.quiet_time', false)
            // The router and the Cisco switch have no update check here: counted, never shown as up to date.
            ->where('updates.unchecked', fn ($count): bool => (int) $count >= 2));
    expect($response->getContent())->not->toContain('Synthetic other Site switch');

    // The device page offers pop-up 62 for the switch only.
    $entry = app(DeviceDirectory::class)->entries($viewer, null, [$switch->id])->first();
    $update = app(DeviceUpdates::class)->forPage($switch->fresh(), $entry, CarbonImmutable::now());
    expect($update['next'])->toBe('6.6.65')->and($update['source_label'])->toBe('UniFi')
        ->and(DeviceUpdates::available($this->router->fresh()))->toBeNull();
});

/* ------------------------------------------------------------------ */
/*  Pop-up 63, Update the saved login                                  */
/* ------------------------------------------------------------------ */

it('refuses to replace a saved login without the saved-logins permission, and keeps another Site out', function (): void {
    $payload = ['username' => 'TEST-ONLY-backup', 'password' => 'TEST-ONLY-new-password'];
    $url = route('security-devices.devices.saved-login.update', ['device' => $this->router->id]);

    $withoutKey = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.commands.admin']);
    $this->actingAs($withoutKey)->putJson($url, $payload)->assertForbidden();

    // Setup's key alone isn't enough: today's credential manager also checks commands.admin.
    $keyOnly = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.savedLogins.manage']);
    $this->actingAs($keyOnly)->putJson($url, $payload)->assertForbidden();
    expect(app(DeviceSavedLogin::class)->present($keyOnly, $this->router, $this->site->id))->toBeNull();

    $elsewhereAdmin = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.savedLogins.manage', 'securityDevices.commands.admin'], $this->otherSite);
    $this->actingAs($elsewhereAdmin)->putJson($url, $payload)->assertNotFound();

    expect($this->reference->fresh()->version)->toBe(1)
        ->and(CredentialReferenceAuditEvent::query()->where('credential_reference_id', $this->reference->id)->count())->toBe(0);
});

it('replaces the saved login through today’s path, audits it and never sends the secret back', function (): void {
    $admin = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.savedLogins.manage', 'securityDevices.commands.admin']);

    $presented = app(DeviceSavedLogin::class)->present($admin, $this->router, $this->site->id);
    expect($presented['id'])->toBe($this->reference->id)
        ->and($presented['name'])->toBe('MikroTik login')
        ->and(json_encode($presented, JSON_THROW_ON_ERROR))->not->toContain('oblivion/sites/');

    $response = $this->actingAs($admin)
        ->putJson(route('security-devices.devices.saved-login.update', ['device' => $this->router->id]), ['username' => 'TEST-ONLY-backup', 'password' => 'TEST-ONLY-new-password'])
        ->assertOk()
        ->assertJsonPath('state', 'working');
    expect($response->getContent())->not->toContain('TEST-ONLY-new-password')
        ->and($response->getContent())->not->toContain('TEST-ONLY-backup');

    $reference = $this->reference->fresh();
    $actions = CredentialReferenceAuditEvent::query()->where('credential_reference_id', $reference->id)->orderBy('id')->pluck('action')->all();
    expect($reference->status)->toBe(CredentialReferenceStatus::Active)
        ->and($reference->version)->toBeGreaterThan(1)
        ->and((int) $reference->last_rotated_by_user_id)->toBe((int) $admin->id)
        ->and($actions)->toBe(['rotated', 'test_passed'])
        ->and(CredentialReferenceAuditEvent::query()->where('credential_reference_id', $reference->id)->where('actor_user_id', '!=', $admin->id)->exists())->toBeFalse();

    $stored = DB::table('monitoring_encrypted_secrets')->pluck('encrypted_payload')->filter()->implode('|');
    expect($stored)->not->toBe('')
        ->and($stored)->not->toContain('TEST-ONLY-new-password');
});
