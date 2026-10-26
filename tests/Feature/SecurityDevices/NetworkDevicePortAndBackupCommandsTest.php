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
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Credentials\Services\CredentialLeaseLifecycleService;
use App\Domain\SecurityDevices\Credentials\Services\CredentialReferenceRules;
use App\Domain\SecurityDevices\Enums\RelationshipType;
use App\Domain\SecurityDevices\Management\Adapters\NetworkDeviceCommandAdapter;
use App\Domain\SecurityDevices\Management\Contracts\CommandHttpTransport;
use App\Domain\SecurityDevices\Management\Data\CommandDecisionInput;
use App\Domain\SecurityDevices\Management\Data\CommandHttpResponse;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandReconciliationOutcome;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionAdapterRegistry;
use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandApprovalService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandReconciliationService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Management\Services\GovernedCommandDispatchService;
use App\Domain\SecurityDevices\Management\Services\NetworkDeviceCommandPolicyService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

final class NetworkFixtureDnsResolver implements DnsResolver
{
    /** @param list<string> $addresses */
    public function __construct(public array $addresses = ['10.88.1.10']) {}

    public function resolve(string $host): array
    {
        return $this->addresses;
    }
}

final class NetworkFixtureLeaseProvider implements CredentialLeaseProvider
{
    /** @var list<array{siteId: int, reference: string, capabilities: array<int, string>}> */
    public array $calls = [];

    public function __construct(public string $token = 'NETWORK-CONTROLLER-API-TOKEN') {}

    public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
    {
        $this->calls[] = compact('siteId', 'reference', 'capabilities');

        return new CredentialLease(
            'network-cmd-lease-'.count($this->calls),
            CarbonImmutable::now('UTC')->addMinute(),
            ['api_token' => $this->token],
        );
    }
}

final class NetworkFixtureSecretIssuer implements SecretManagerLeaseIssuer
{
    /** @var list<string> */
    public array $revoked = [];

    public function issue(SecretLeaseRequest $request): CredentialLease
    {
        throw new RuntimeException('Adapter must acquire through governed lease provider.');
    }

    public function revoke(string $leaseId): void
    {
        $this->revoked[] = $leaseId;
    }
}

final class NetworkFixtureHttpTransport implements CommandHttpTransport
{
    /** @var list<array{target: AuthorizedProbeTarget, method: string, headers: array<string, string>, json: ?array}> */
    public array $calls = [];

    /** @param list<CommandHttpResponse> $responses */
    public function __construct(public array $responses) {}

    public function request(
        AuthorizedProbeTarget $target,
        string $method,
        array $headers = [],
        ?array $json = null,
    ): CommandHttpResponse {
        $this->calls[] = compact('target', 'method', 'headers', 'json');
        $response = array_shift($this->responses);
        if (! $response instanceof CommandHttpResponse) {
            throw new RuntimeException('No network controller fixture HTTP response remains.');
        }

        return $response;
    }
}

final class NetworkFixtureSnapshotStore implements SnapshotStore
{
    /** @var array<string, string> */
    public array $objects = [];

    public function put(string $path, string $contents): void
    {
        $this->objects[$path] = $contents;
    }

    public function read(string $path): string
    {
        return $this->objects[$path] ?? throw new RuntimeException('Missing stored object: '.$path);
    }

    public function delete(string $path): void
    {
        unset($this->objects[$path]);
    }

    public function exists(string $path): bool
    {
        return isset($this->objects[$path]);
    }
}

/** @return array{site: Site, switch: Device, camera: Device} */
function createNetworkCommandSwitch(): array
{
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $switch = Device::factory()->itInfrastructure()->create([
        'name' => 'Harbour Core Switch',
        'category' => 'network',
        'subcategory' => 'managed_switch',
        'provider' => 'aruba',
        'last_seen_at' => now(),
        'external_ref' => [
            'provider' => 'aruba',
            'provider_device_id' => 'sw-harbour-01',
        ],
        'config' => [
            'management' => [
                'capabilities' => [
                    'network.port.enable',
                    'network.port.disable',
                    'network.port.bounce',
                    'network.config.backup',
                ],
                'network' => [
                    'interfaces' => [
                        ['name' => 'Gi1/0/12', 'role' => 'access', 'label' => 'Staff printer'],
                        ['name' => 'Gi1/0/24', 'role' => 'access', 'label' => 'Entry camera PoE'],
                        ['name' => 'Gi1/0/48', 'role' => 'uplink', 'label' => 'Core router trunk'],
                    ],
                ],
            ],
        ],
    ]);

    $camera = Device::factory()->security()->create([
        'name' => 'Entry CCTV Camera',
        'category' => 'cctv',
    ]);

    foreach ([$switch, $camera] as $item) {
        DeviceAssignment::query()->create([
            'device_id' => $item->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now()->subHour(),
        ]);
    }

    $creator = User::factory()->create(['approved_at' => now()]);
    DeviceRelationship::query()->create([
        'parent_device_id' => $switch->id,
        'child_device_id' => $camera->id,
        'relationship_type' => RelationshipType::ConnectedTo,
        'port' => 'Gi1/0/24',
        'linked_at' => now()->subHour(),
        'created_by_user_id' => $creator->id,
    ]);

    DiscoveryScope::factory()->create([
        'site_id' => $site->id,
        'collector_id' => null,
        'cidrs' => ['10.88.0.0/16'],
        'protocols' => ['provider'],
        'port_bounds' => ['provider' => [8443]],
        'exclusions' => [],
        'status' => 'active',
    ]);

    IntegrationSiteSecret::query()->create([
        'site_id' => $site->id,
        'provider' => 'aruba',
        'capability' => 'network_management_api',
        'base_url' => 'https://controller.example.test:8443',
        'secret_encrypted' => 'LEGACY-DO-NOT-USE',
        'is_enabled' => true,
        'last_tested_at' => now(),
        'last_error' => null,
    ]);

    CredentialReference::query()->create([
        'reference_key' => 'vault:aruba/'.$site->id.'/network-management',
        'site_id' => $site->id,
        'provider' => 'aruba',
        'purpose' => 'device_management',
        'capabilities' => [
            'command:network.port.enable',
            'command:network.port.disable',
            'command:network.port.bounce',
            'command:network.config.backup',
        ],
        'secret_manager_reference' => 'secret/data/sites/'.$site->id.'/aruba-mgmt',
        'secret_manager_reference_hash' => hash('sha256', 'aruba-mgmt-'.$site->id),
        'status' => CredentialReferenceStatus::Active,
        'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed,
        'version' => 1,
        'last_tested_at' => now(),
    ]);

    return compact('site', 'switch', 'camera');
}

function createNetworkCommandActor(Site $site, string $roleName = 'it_manager'): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);

    return $user;
}

/** @param list<CommandHttpResponse> $responses */
function installNetworkCommandAdapterFixture(array $responses, NetworkFixtureSnapshotStore $store): NetworkFixtureHttpTransport
{
    app()->instance(SnapshotStore::class, $store);
    app()->forgetInstance(ConfigurationSnapshotService::class);
    app()->instance(DnsResolver::class, new NetworkFixtureDnsResolver);
    app()->forgetInstance(ApprovedProbeScopeProvider::class);
    app()->forgetInstance(EgressPolicy::class);

    $leases = new NetworkFixtureLeaseProvider;
    $issuer = new NetworkFixtureSecretIssuer;
    $http = new NetworkFixtureHttpTransport($responses);
    $credentials = new CommandCredentialLeaseService(
        $leases,
        new CredentialLeaseLifecycleService($issuer, new CredentialReferenceRules),
    );

    $adapter = new NetworkDeviceCommandAdapter(
        app(CanonicalDeviceSiteResolver::class),
        app(DeclaredDeviceCommandCapabilities::class),
        app(NetworkDeviceCommandPolicyService::class),
        $credentials,
        app(EgressPolicy::class),
        $http,
        app(ConfigurationSnapshotService::class),
    );

    app()->instance(CommandExecutionAdapterRegistry::class, new CommandExecutionAdapterRegistry([$adapter]));
    app()->forgetInstance(GovernedCommandDispatchService::class);
    app()->forgetInstance(DeviceCommandReconciliationService::class);

    return $http;
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T05:00:00Z'));
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.signing.active_key_id', 'network-cmd-test-key');
    config()->set('monitoring.signing.keys', [
        'network-cmd-test-key' => base64_encode(str_repeat('N', SODIUM_CRYPTO_AUTH_KEYBYTES)),
    ]);
});

afterEach(function (): void {
    $this->travelBack();
});

it('keeps standard access port restarts Ready while elevating uplink and security-linked ports to independent approval', function (): void {
    $fixture = createNetworkCommandSwitch();
    $store = new NetworkFixtureSnapshotStore;
    installNetworkCommandAdapterFixture([], $store);

    $requester = createNetworkCommandActor($fixture['site']);
    $service = app(DeviceCommandRequestService::class);

    $accessPortRequest = $service->request($fixture['switch'], $requester, new CommandRequestInput(
        capability: 'network.port.bounce',
        parameters: ['interface' => 'Gi1/0/12'],
        reason: 'Restart jammed office printer PoE interface.',
        idempotencyKey: 'bounce-access-port-1',
        impactAcknowledged: true,
    ));

    $uplinkPortRequest = $service->request($fixture['switch'], $requester, new CommandRequestInput(
        capability: 'network.port.bounce',
        parameters: ['interface' => 'Gi1/0/48'],
        reason: 'Recover negotiated link speed on core uplink port.',
        idempotencyKey: 'bounce-uplink-port-1',
        impactAcknowledged: true,
    ));

    $cameraPortRequest = $service->request($fixture['switch'], $requester, new CommandRequestInput(
        capability: 'network.port.bounce',
        parameters: ['interface' => 'Gi1/0/24'],
        reason: 'Power-cycle PoE feed for unresponsive entry camera.',
        idempotencyKey: 'bounce-camera-port-1',
        impactAcknowledged: true,
    ));

    expect($accessPortRequest->status)->toBe(CommandStatus::Ready)
        ->and($uplinkPortRequest->status)->toBe(CommandStatus::AwaitingApproval)
        ->and($cameraPortRequest->status)->toBe(CommandStatus::AwaitingApproval);
});

it('dispatches and reconciles network port bounce and secret-redacted configuration backup commands end to end', function (): void {
    $fixture = createNetworkCommandSwitch();
    $store = new NetworkFixtureSnapshotStore;

    $http = installNetworkCommandAdapterFixture([
        // 1. POST port bounce on Gi1/0/48
        new CommandHttpResponse(200, json_encode([
            'status' => 'accepted',
        ], JSON_THROW_ON_ERROR)),
        // 2. GET port readback on Gi1/0/48
        new CommandHttpResponse(200, json_encode([
            'data' => [
                'interface' => 'Gi1/0/48',
                'admin_status' => 'up',
                'bounced' => true,
            ],
        ], JSON_THROW_ON_ERROR)),
        // 3. GET configuration backup
        new CommandHttpResponse(200, json_encode([
            'data' => [
                'hostname' => 'harbour-core-sw01',
                'firmware_version' => '16.11.02',
                'running_config_lines' => [
                    'hostname harbour-core-sw01',
                    'enable secret 5 $1$xyz$RAW-CONTROLLER-ENABLE-SECRET',
                    'snmp-server community RAW-SNMP-COMMUNITY RO',
                    'vlan 10 name Management',
                ],
            ],
        ], JSON_THROW_ON_ERROR)),
    ], $store);

    $requester = createNetworkCommandActor($fixture['site']);
    $approver = createNetworkCommandActor($fixture['site']);

    $bounceCommand = app(DeviceCommandRequestService::class)->request($fixture['switch'], $requester, new CommandRequestInput(
        capability: 'network.port.bounce',
        parameters: ['interface' => 'Gi1/0/48'],
        reason: 'Bounce uplink port during approved maintenance verification.',
        idempotencyKey: 'dispatch-uplink-bounce-1',
        impactAcknowledged: true,
    ));
    expect($bounceCommand->status)->toBe(CommandStatus::AwaitingApproval);

    app(DeviceCommandApprovalService::class)->decide($bounceCommand, $approver, new CommandDecisionInput(
        decision: CommandApprovalDecision::Approved,
        comment: 'Confirmed out-of-band management path is healthy.',
    ));

    app(GovernedCommandDispatchService::class)->dispatch($bounceCommand->fresh(), $requester);
    $reconciledBounce = app(DeviceCommandReconciliationService::class)->reconcile($bounceCommand->fresh(), $requester);

    expect($reconciledBounce->outcome)->toBe(CommandReconciliationOutcome::Matched)
        ->and($bounceCommand->fresh()->status)->toBe(CommandStatus::Reconciled)
        ->and($http->calls[0]['method'])->toBe('POST')
        ->and($http->calls[0]['target']->path)->toContain('/interfaces/Gi1%2F0%2F48/bounce');

    $backupCommand = app(DeviceCommandRequestService::class)->request($fixture['switch'], $requester, new CommandRequestInput(
        capability: 'network.config.backup',
        parameters: [],
        reason: 'Capture verified pre-change switch configuration backup.',
        idempotencyKey: 'dispatch-config-backup-1',
        impactAcknowledged: true,
    ));
    expect($backupCommand->status)->toBe(CommandStatus::Ready);

    app(GovernedCommandDispatchService::class)->dispatch($backupCommand, $requester);
    $reconciledBackup = app(DeviceCommandReconciliationService::class)->reconcile($backupCommand->fresh(), $requester);

    $snapshot = ConfigurationSnapshot::query()
        ->where('device_id', $fixture['switch']->id)
        ->where('source_kind', 'command')
        ->sole();
    $persistedBytes = $store->read($snapshot->storage_path);

    expect($reconciledBackup->outcome)->toBe(CommandReconciliationOutcome::Matched)
        ->and($backupCommand->fresh()->status)->toBe(CommandStatus::Reconciled)
        ->and($persistedBytes)->toContain('harbour-core-sw01')
        ->and($persistedBytes)->toContain('[REDACTED]')
        ->and($persistedBytes)->not->toContain('RAW-CONTROLLER-ENABLE-SECRET')
        ->and($persistedBytes)->not->toContain('RAW-SNMP-COMMUNITY');
});
