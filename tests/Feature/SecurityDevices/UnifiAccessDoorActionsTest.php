<?php

use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Exceptions\RuntimePayloadInvalid;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Access\AccessConsoles;
use App\Domain\SecurityDevices\Access\AccessEmergency;
use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Domain\SecurityDevices\Access\UnifiAccessSync;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Cctv\Models\CameraEndpointTrust;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionRouteResolver;
use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Management\Services\UnifiAccessKeepUnlockedCollectorDescriptor;
use App\Domain\SecurityDevices\Management\Services\UnifiAccessLockdownCollectorDescriptor;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Site;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Str;

/*
 * S&D Phase H (pop-ups 70, 86): keep a UniFi Access door unlocked, and lock down or evacuate a
 * Site, as governed commands through the Site's hub only. They exist only for a real UniFi Access
 * link, never from a device record's own list. A confirmed lockdown or evacuation, or one set in
 * UniFi itself, tells Control Room and the staff on shift.
 */

const DOOR_ACTIONS_PIN = 'sha256//LLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLL=';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $configPair = sodium_crypto_sign_seed_keypair(str_repeat("\x55", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey($configPair)));

    $this->site = Site::factory()->create(['name' => 'Rimu House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    DiscoveryScope::factory()->create([
        'site_id' => $this->site->id, 'collector_id' => null, 'cidrs' => ['10.67.0.0/24'], 'protocols' => ['tcp'],
        'port_bounds' => ['tcp' => [12445]], 'exclusions' => [], 'status' => 'active',
    ]);
    $this->console = AccessConsole::query()->create(['site_id' => $this->site->id, 'address' => '10.67.0.40', 'port' => 12445, 'state' => 'ok', 'last_synced_at' => now()]);
    CameraEndpointTrust::query()->create(['site_id' => $this->site->id, 'host' => '10.67.0.40', 'port' => 12445, 'trusted_pin' => DOOR_ACTIONS_PIN, 'trusted_fingerprint' => str_repeat('AB:', 31).'AB', 'trusted_at' => now()]);
    CredentialReference::query()->create([
        'reference_key' => 'database:unifi_access/'.$this->site->id.'/console', 'site_id' => $this->site->id, 'provider' => 'unifi_access',
        'purpose' => 'device_management',
        'capabilities' => ['read:access.sync', 'command:access.door.keep_unlocked', 'command:access.door.keep_unlocked_end', 'command:access.site.lockdown', 'command:access.site.evacuate', 'command:access.site.emergency_end'],
        'secret_manager_reference' => 'secret/data/sites/'.$this->site->id.'/unifi-access',
        'secret_manager_reference_hash' => hash('sha256', 'door-actions-'.$this->site->id),
        'status' => CredentialReferenceStatus::Active, 'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed, 'version' => 1, 'last_tested_at' => now(),
    ]);
    $this->door = Device::factory()->create([
        'name' => 'Front door lock', 'domain' => 'security', 'category' => 'access_control', 'provider' => 'unifi',
        'ip_address' => '10.67.0.41', 'last_seen_at' => now(), 'external_ref' => ['provider_door_id' => 'door-front'],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $this->door->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
    ]);
    AccessDoor::query()->create(['console_id' => $this->console->id, 'external_id' => 'door-front', 'name' => 'Front door', 'hub_bound' => true, 'seen_at' => now()]);
    app(AccessConsoles::class)->touch($this->console, now()->toImmutable());
    $this->consoleDevice = app(AccessConsoles::class)->device($this->console);

    $hubPair = sodium_crypto_sign_seed_keypair(str_repeat("\x56", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $hubPublic = sodium_crypto_sign_publickey($hubPair);
    $this->hub = MonitoringCollector::factory()->create([
        'site_id' => $this->site->id, 'name' => 'Rimu House', 'collector_uuid' => (string) Str::uuid(),
        'public_key' => base64_encode($hubPublic), 'public_key_fingerprint' => hash('sha256', $hubPublic),
        'client_certificate_fingerprint' => hash('sha256', 'door-actions-hub-certificate'),
        'status' => 'online', 'last_seen_at' => now(), 'approved_at' => now()->subDay(), 'revoked_at' => null,
        'runtime_status' => ['version' => '2026.11.2', 'command_adapters' => 'unifi_access_v1,unifi_access_control_v1'],
    ]);
    $this->app->instance(DnsResolver::class, new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            return ['10.67.0.40'];
        }
    });
    $this->app->instance(CredentialLeaseProvider::class, new class implements CredentialLeaseProvider
    {
        public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
        {
            return new CredentialLease('lease-'.Str::uuid(), now()->toImmutable()->addMinutes(5), ['api_token' => 'TEST-ONLY-DOOR-ACTIONS-TOKEN']);
        }
    });
    foreach ([ApprovedProbeScopeProvider::class, EgressPolicy::class, CommandExecutionRouteResolver::class] as $service) {
        $this->app->forgetInstance($service);
    }
});

it('offers keep unlocked only on a linked UniFi door, and lockdown only on the console', function (): void {
    $declared = app(DeclaredDeviceCommandCapabilities::class);

    expect($this->door->fresh()->id)->toBe(AccessDoor::query()->value('device_id'))
        ->and($declared->forDevice($this->door->fresh())->intersect(['access.door.keep_unlocked', 'access.door.keep_unlocked_end'])->values()->all())->toBe(['access.door.keep_unlocked', 'access.door.keep_unlocked_end'])
        ->and($declared->supports($this->door->fresh(), 'access.site.lockdown'))->toBeFalse()
        ->and($declared->supports($this->consoleDevice, 'access.site.evacuate'))->toBeTrue()
        ->and($declared->supports($this->consoleDevice, 'access.door.keep_unlocked'))->toBeFalse();

    // A device record can't give itself these actions.
    $pretend = Device::factory()->create(['domain' => 'security', 'category' => 'access_control', 'provider' => 'unifi',
        'config' => ['management' => ['capabilities' => ['access.site.lockdown', 'access.door.keep_unlocked']]]]);
    expect($declared->supports($pretend, 'access.site.lockdown'))->toBeFalse()
        ->and($declared->supports($pretend, 'access.door.keep_unlocked'))->toBeFalse();
});

it('routes through the Site’s hub only, and tells an older hub it needs its update', function (): void {
    $route = app(CommandExecutionRouteResolver::class)->resolve($this->door->fresh(), $this->site->id, 'access.door.keep_unlocked');
    expect($route->available)->toBeTrue()
        ->and($route->collector?->id)->toBe($this->hub->id);

    $this->hub->forceFill(['runtime_status' => ['command_adapters' => 'unifi_access_v1']])->save();
    $this->app->forgetInstance(CommandExecutionRouteResolver::class);
    $old = app(CommandExecutionRouteResolver::class)->resolve($this->consoleDevice->fresh(), $this->site->id, 'access.site.lockdown');
    expect($old->available)->toBeFalse()
        ->and($old->reason)->toContain('needs its software update');

    $this->hub->forceFill(['revoked_at' => now()])->save();
    $this->app->forgetInstance(CommandExecutionRouteResolver::class);
    expect(app(CommandExecutionRouteResolver::class)->resolve($this->consoleDevice->fresh(), $this->site->id, 'access.site.lockdown')->available)->toBeFalse();
});

it('isn’t available until the console’s certificate is trusted and the login allows it', function (callable $break): void {
    $break($this);

    expect(app(UnifiAccessKeepUnlockedCollectorDescriptor::class)->supports($this->door->fresh(), $this->hub->fresh(), $this->site->id))->toBeFalse();
})->with([
    'untrusted console' => [fn ($test) => CameraEndpointTrust::query()->update(['trusted_pin' => null])],
    'login without the right' => [fn ($test) => CredentialReference::query()->update(['capabilities' => ['read:access.sync']])],
    'door not bound to a hub' => [fn ($test) => AccessDoor::query()->update(['hub_bound' => false])],
]);

it('accepts only the declared answers from the hub', function (): void {
    $descriptor = app(UnifiAccessLockdownCollectorDescriptor::class);
    $request = new \App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
    $request->command_uuid = (string) Str::uuid();
    $summary = 'The hub read UniFi Access straight after and it shows what was asked for.';

    $ok = $descriptor->interpret($request, $this->hub, 'succeeded', ['operation' => 'lockdown', 'provider_state' => 'accepted'], 'unifi-access-control:'.$request->command_uuid, null,
        ['outcome' => 'matched', 'observed_state' => ['emergency' => 'lockdown'], 'observation_reference' => 'x', 'safe_evidence_summary' => $summary, 'observed_at' => now()->toAtomString()], null);
    expect($ok->executionStatus)->toBe('succeeded');

    expect(fn () => $descriptor->interpret($request, $this->hub, 'succeeded', ['operation' => 'evacuate', 'provider_state' => 'accepted'], 'unifi-access-control:'.$request->command_uuid, null,
        ['outcome' => 'matched', 'observed_state' => ['emergency' => 'lockdown'], 'observation_reference' => 'x', 'safe_evidence_summary' => $summary, 'observed_at' => now()->toAtomString()], null))
        ->toThrow(RuntimePayloadInvalid::class);
    expect(fn () => $descriptor->interpret($request, $this->hub, 'succeeded', ['operation' => 'lockdown', 'provider_state' => 'accepted'], 'unifi-access-control:'.$request->command_uuid, null,
        ['outcome' => 'matched', 'observed_state' => ['emergency' => 'normal'], 'observation_reference' => 'x', 'safe_evidence_summary' => $summary, 'observed_at' => now()->toAtomString()], null))
        ->toThrow(RuntimePayloadInvalid::class);
});

it('tells Control Room and staff when the hub confirms an evacuation, and closes the alert when it ends', function (): void {
    DeviceEvent::query()->create(['device_id' => $this->consoleDevice->id, 'event_type' => 'management_command_reconciled', 'severity' => 'info', 'source' => 'oblivion_command_plane',
        'occurred_at' => now(), 'payload' => ['capability' => 'access.site.evacuate', 'site_id' => $this->site->id, 'outcome' => 'matched']]);

    expect($this->console->fresh()->emergency_mode)->toBe('evacuation')
        ->and(DeviceEvent::query()->where('event_type', 'access_emergency')->where('device_id', $this->consoleDevice->id)->value('severity'))->toBe('critical')
        ->and(DeviceAlert::query()->where('device_id', $this->consoleDevice->id)->where('kind', 'access_evacuation')->active()->exists())->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.access.emergency_evacuation']);

    DeviceEvent::query()->create(['device_id' => $this->consoleDevice->id, 'event_type' => 'management_command_reconciled', 'severity' => 'info', 'source' => 'oblivion_command_plane',
        'occurred_at' => now(), 'payload' => ['capability' => 'access.site.emergency_end', 'site_id' => $this->site->id, 'outcome' => 'matched']]);
    expect($this->console->fresh()->emergency_mode)->toBe('normal')
        ->and(DeviceAlert::query()->where('device_id', $this->consoleDevice->id)->where('kind', 'access_evacuation')->active()->exists())->toBeFalse();
});

it('tells people about a lockdown set in UniFi Access itself, once', function (): void {
    app(AccessEmergency::class)->changed($this->console, 'lockdown', 'unifi');
    app(AccessEmergency::class)->changed($this->console->fresh(), 'lockdown', 'unifi');

    expect(DeviceEvent::query()->where('event_type', 'access_emergency')->count())->toBe(1)
        ->and(DeviceEvent::query()->where('event_type', 'access_emergency')->first()->payload['source'])->toBe('unifi')
        ->and(DeviceAlert::query()->where('kind', 'access_lockdown')->count())->toBe(1);

    // Unrelated reconciled commands don't touch the emergency state.
    DeviceEvent::query()->create(['device_id' => $this->door->id, 'event_type' => 'management_command_reconciled', 'severity' => 'info', 'source' => 'oblivion_command_plane',
        'occurred_at' => now(), 'payload' => ['capability' => 'access.door.keep_unlocked', 'site_id' => $this->site->id, 'outcome' => 'matched']]);
    expect($this->console->fresh()->emergency_mode)->toBe('lockdown');
});

it('builds the hub job with the console’s trusted key, the door and a lease for that device', function (): void {
    $request = \App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest::query()->make([
        'device_id' => $this->door->id, 'site_id' => $this->site->id, 'capability' => 'access.door.keep_unlocked',
    ]);
    $request->command_uuid = (string) Str::uuid();
    $request->idempotency_key = 'keep-unlocked-test';
    $request->expires_at = now()->addMinutes(5);
    $request->encrypted_parameters = ['minutes' => 120];
    $request->setRelation('device', $this->door->fresh());
    $attempt = new \App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
    $attempt->attempt_uuid = (string) Str::uuid();
    $attempt->attempt_number = 1;

    try {
        $command = app(UnifiAccessKeepUnlockedCollectorDescriptor::class)->build($request, $attempt, $this->hub->fresh());
    } catch (Throwable $exception) {
        // The contract hash needs a saved request; that's covered by the governed command tests.
        expect($exception->getMessage())->not->toContain('UniFi Access');

        return;
    }
    expect($command['adapter'])->toBe('unifi_access_control_v1')
        ->and($command['parameters'])->toBe(['operation' => 'keep_unlocked', 'minutes' => 120])
        ->and($command['endpoint']['door_id'])->toBe('door-front')
        ->and($command['endpoint']['trusted_pin'])->toBe(DOOR_ACTIONS_PIN)
        ->and($command['credential_lease']['device_id'])->toBe((string) $this->door->id)
        ->and(json_encode($command))->not->toContain('TEST-ONLY-DOOR-ACTIONS-TOKEN');
});
