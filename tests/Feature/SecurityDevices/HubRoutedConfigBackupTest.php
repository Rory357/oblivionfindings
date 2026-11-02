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
use App\Domain\Monitoring\Services\CollectorConfigurationService;
use App\Domain\Monitoring\Services\CollectorIngestService;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
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
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Str;

/*
 * S&D Phase B, routing through hubs (docs/security-devices/GOAL.md): when a hub covers the Site, a
 * router settings backup runs on that hub and never from the server. The server sends a profile
 * name, never command text; the hub's result comes back with the output attached and the server
 * stores it as it always has.
 */

const HUB_ROUTED_TRUSTED_KEY = 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
const HUB_ROUTED_OTHER_KEY = 'SHA256:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.signing.active_key_id', 'hub-routed-backup');
    config()->set('monitoring.signing.keys', ['hub-routed-backup' => base64_encode(str_repeat('H', SODIUM_CRYPTO_AUTH_KEYBYTES))]);
    $configPair = sodium_crypto_sign_seed_keypair(str_repeat("\x41", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey($configPair)));

    $this->site = Site::factory()->create(['name' => 'Rimu House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    DiscoveryScope::factory()->create([
        'site_id' => $this->site->id, 'collector_id' => null, 'cidrs' => ['10.77.0.0/24'], 'protocols' => ['tcp'],
        'port_bounds' => ['tcp' => [22]], 'exclusions' => [], 'status' => 'active',
    ]);
    $this->router = Device::factory()->create([
        'name' => 'Rimu House router', 'domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => 'router',
        'manufacturer' => 'MikroTik', 'model' => 'hAP ax3', 'provider' => 'mikrotik', 'ip_address' => '10.77.0.1', 'last_seen_at' => now(),
        'config' => ['ssh' => ['host_key_fingerprint' => HUB_ROUTED_TRUSTED_KEY]],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $this->router->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
    ]);
    CredentialReference::query()->create([
        'reference_key' => 'database:mikrotik/'.$this->site->id.'/ssh', 'site_id' => $this->site->id, 'provider' => 'mikrotik',
        'purpose' => 'device_management', 'capabilities' => ['command:network.config.backup'],
        'secret_manager_reference' => 'secret/data/sites/'.$this->site->id.'/mikrotik-ssh',
        'secret_manager_reference_hash' => hash('sha256', 'hub-routed-ssh-'.$this->site->id),
        'status' => CredentialReferenceStatus::Active, 'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed, 'version' => 1, 'last_tested_at' => now(),
    ]);

    $hubPair = sodium_crypto_sign_seed_keypair(str_repeat("\x42", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $hubPublic = sodium_crypto_sign_publickey($hubPair);
    $this->hub = MonitoringCollector::factory()->create([
        'site_id' => $this->site->id, 'name' => 'Rimu House', 'collector_uuid' => (string) Str::uuid(),
        'public_key' => base64_encode($hubPublic), 'public_key_fingerprint' => hash('sha256', $hubPublic),
        'client_certificate_fingerprint' => hash('sha256', 'hub-routed-certificate'),
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
            return new CredentialLease('lease-'.Str::uuid(), now()->toImmutable()->addMinutes(5), ['username' => 'backup_ro', 'password' => 'HUB-ROUTED-TEST-ONLY-PASSWORD']);
        }
    });
    // The server's own SSH path must never be used while a hub covers the Site.
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
        GovernedCommandDispatchService::class, CollectorConfigurationService::class, CollectorIngestService::class] as $service) {
        $this->app->forgetInstance($service);
    }

    $this->manager = User::factory()->create(['approved_at' => now(), 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
    $this->manager->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
    HrEmployeeProfile::factory()->create(['user_id' => $this->manager->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);

    $this->requestBackup = function (string $key): array {
        $command = app(DeviceCommandRequestService::class)->request($this->router->fresh(), $this->manager, new CommandRequestInput(
            capability: 'network.config.backup', parameters: [], reason: 'Phase B backup through the hub.', idempotencyKey: $key, impactAcknowledged: true,
        ));
        $attempt = app(GovernedCommandDispatchService::class)->dispatch($command->fresh(), $this->manager);

        return [$command->fresh(), $attempt];
    };

    $this->hubJob = function (): array {
        $envelope = app(CollectorConfigurationService::class)->signedEnvelope($this->hub->fresh(), 0);
        $signed = json_decode($envelope, true, flags: JSON_THROW_ON_ERROR);

        return json_decode(base64_decode($signed['payload'], true), true, flags: JSON_THROW_ON_ERROR);
    };

    /** What the hub's SshConfigBackupRunner sends back. */
    $this->hubResult = function (DeviceCommandRequest $command, DeviceCommandAttempt $attempt, array $job, array $overrides = []): array {
        $at = CarbonImmutable::now('UTC')->startOfSecond();

        return array_replace([
            'item_type' => 'command_result',
            'command_uuid' => $command->command_uuid,
            'attempt_uuid' => $attempt->attempt_uuid,
            'attempt_number' => 1,
            'site_id' => $this->site->id,
            'device_id' => (string) $this->router->id,
            'capability' => 'network.config.backup',
            'contract_hash' => $job['contract_hash'],
            'execution_status' => 'succeeded',
            'safe_result' => [],
            'provider_request_reference' => null,
            'safe_failure_reason' => null,
            'accepted_at' => $at->format(DATE_ATOM),
            'started_at' => $at->format(DATE_ATOM),
            'completed_at' => $at->format(DATE_ATOM),
            'reconciliation' => null,
        ], $overrides);
    };

    $this->ingest = function (DeviceCommandAttempt $attempt, array $payload, int $sequence = 1): array {
        return app(CollectorIngestService::class)->ingest($this->hub->fresh(), [[
            'id' => 'command-result:'.$attempt->attempt_uuid,
            'source_sequence' => $sequence,
            'created_at' => CarbonImmutable::now('UTC')->format(DATE_ATOM),
            'payload' => $payload,
        ]]);
    };
});

it('sends the backup to the hub that covers the Site, with a profile name and no command text or login in the clear', function (): void {
    [$command, $attempt] = ($this->requestBackup)('hub-backup-job');

    expect($command->collector_id)->toBe($this->hub->id)
        ->and($attempt->runtime)->toBe('collector')
        ->and($attempt->status)->toBe(CommandAttemptStatus::Dispatching)
        ->and($this->direct->calls)->toBe(0);

    $configuration = ($this->hubJob)();
    $job = $configuration['commands'][0];
    expect($configuration['commands'])->toHaveCount(1)
        ->and($job['adapter'])->toBe('ssh_config_backup_v1')
        ->and($job['protocol'])->toBe('command.ssh_config_backup')
        ->and($job['target'])->toBe('10.77.0.1')
        ->and($job['parameters'])->toBe(['export_profile' => 'mikrotik_routeros'])
        ->and($job['expected_state'])->toBe(['snapshot_available' => true])
        ->and($job['endpoint']['expected_host_key'])->toBe(HUB_ROUTED_TRUSTED_KEY)
        ->and($job['endpoint']['port'])->toBe(22)
        ->and($job['credential_lease'])->not->toHaveKey('material')
        ->and($configuration['scope']['protocols'])->toContain('command.ssh_config_backup')
        ->and(json_encode($configuration, JSON_THROW_ON_ERROR))->not->toContain('/export', 'show running-config', 'HUB-ROUTED-TEST-ONLY-PASSWORD', 'backup_ro');
});

it('stores the settings the hub sends back and confirms the stored copy', function (): void {
    [$command, $attempt] = ($this->requestBackup)('hub-backup-success');
    $job = ($this->hubJob)()['commands'][0];
    $export = "# 2026-11-01 12:00:00 by RouterOS 7.16\n/interface bridge add name=br-lan\n/ip address add address=10.77.0.1/24 interface=br-lan\n";

    $acknowledgement = ($this->ingest)($attempt, ($this->hubResult)($command, $attempt, $job, [
        'safe_result' => [
            'provider_state' => 'captured',
            'export_profile' => 'mikrotik_routeros',
            'output_bytes' => strlen($export),
            'output_sha256' => hash('sha256', $export),
            'host_key_fingerprint' => HUB_ROUTED_TRUSTED_KEY,
        ],
        'provider_request_reference' => 'ssh-backup:'.$command->command_uuid,
        'attachment' => [
            'kind' => 'cli_output', 'encoding' => 'gzip+base64', 'bytes' => strlen($export),
            'sha256' => hash('sha256', $export), 'data' => base64_encode(gzencode($export)),
        ],
    ]));

    $snapshot = ConfigurationSnapshot::query()->sole();
    $stored = $attempt->fresh();
    expect($acknowledgement['acknowledged_ids'])->toBe(['command-result:'.$attempt->attempt_uuid])
        ->and($stored->status)->toBe(CommandAttemptStatus::Succeeded)
        ->and($stored->safe_result_summary['routed_through'])->toBe('hub')
        ->and($stored->safe_result_summary['snapshot_id'])->toBe($snapshot->id)
        ->and($command->fresh()->status)->toBe(CommandStatus::Reconciled)
        ->and($command->fresh()->execution_route)->toBe('collector')
        ->and($snapshot->source)->toBe('mikrotik_routeros_ssh_backup')
        ->and((int) $snapshot->site_id)->toBe($this->site->id)
        ->and($this->direct->calls)->toBe(0);
});

it('records the key the device offered when it is not trusted yet, and stores nothing', function (): void {
    $this->router->forceFill(['config' => []])->save();
    [$command, $attempt] = ($this->requestBackup)('hub-backup-untrusted');
    $job = ($this->hubJob)()['commands'][0];
    expect($job['endpoint']['expected_host_key'])->toBeNull();

    ($this->ingest)($attempt, ($this->hubResult)($command, $attempt, $job, [
        'execution_status' => 'failed',
        'safe_result' => ['reason_code' => 'host_key_unconfirmed', 'offered_host_key' => HUB_ROUTED_OTHER_KEY],
        'safe_failure_reason' => 'The device’s SSH key isn’t trusted yet, so no login was sent.',
    ]));

    expect($command->fresh()->status)->toBe(CommandStatus::Failed)
        ->and($command->fresh()->safe_failure_reason)->toContain(HUB_ROUTED_OTHER_KEY)
        ->and($command->fresh()->safe_failure_reason)->toContain('no login was sent')
        ->and(data_get($this->router->fresh()->meta, 'ssh_host_key_offered.fingerprint'))->toBe(HUB_ROUTED_OTHER_KEY)
        ->and(ConfigurationSnapshot::query()->count())->toBe(0);
});

it('refuses a hub success whose key is not the trusted one, or whose attachment was damaged', function (): void {
    [$command, $attempt] = ($this->requestBackup)('hub-backup-tampered');
    $job = ($this->hubJob)()['commands'][0];
    $export = "/interface bridge add name=br-lan\n";
    $good = [
        'safe_result' => [
            'provider_state' => 'captured', 'export_profile' => 'mikrotik_routeros', 'output_bytes' => strlen($export),
            'output_sha256' => hash('sha256', $export), 'host_key_fingerprint' => HUB_ROUTED_OTHER_KEY,
        ],
        'provider_request_reference' => 'ssh-backup:'.$command->command_uuid,
        'attachment' => [
            'kind' => 'cli_output', 'encoding' => 'gzip+base64', 'bytes' => strlen($export),
            'sha256' => hash('sha256', $export), 'data' => base64_encode(gzencode($export)),
        ],
    ];
    ($this->ingest)($attempt, ($this->hubResult)($command, $attempt, $job, $good));

    $damaged = $good;
    $damaged['safe_result']['host_key_fingerprint'] = HUB_ROUTED_TRUSTED_KEY;
    $damaged['attachment']['data'] = base64_encode(gzencode("/user add name=intruder\n"));
    ($this->ingest)($attempt, ($this->hubResult)($command, $attempt, $job, $damaged), 2);

    expect(ConfigurationSnapshot::query()->count())->toBe(0)
        ->and($attempt->fresh()->status)->toBe(CommandAttemptStatus::Dispatching);
});

it('never falls back to the server when the covering hub is offline', function (): void {
    $this->hub->forceFill(['status' => 'offline'])->save();

    $route = app(CommandExecutionRouteResolver::class)->resolve($this->router->fresh(), $this->site->id, 'network.config.backup');

    expect($route->available)->toBeFalse()
        ->and($route->reason)->toContain('Rimu House hub isn’t connected')
        ->and($this->direct->calls)->toBe(0);
});

it('still uses the server’s own backup where no hub covers the Site', function (): void {
    $this->hub->forceFill(['revoked_at' => now()])->save();

    $route = app(CommandExecutionRouteResolver::class)->resolve($this->router->fresh(), $this->site->id, 'network.config.backup');

    expect($route->available)->toBeTrue()
        ->and($route->mode)->toBe('central');
});
