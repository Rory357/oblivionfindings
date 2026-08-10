<?php

use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Contracts\CommandDispatchPort;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Exceptions\EgressDenied;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
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
use App\Domain\SecurityDevices\Management\Adapters\UnifiAccessCommandAdapter;
use App\Domain\SecurityDevices\Management\Contracts\CommandHttpTransport;
use App\Domain\SecurityDevices\Management\Data\CommandDecisionInput;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandHttpResponse;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandReconciliationOutcome;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionAdapterRegistry;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandApprovalService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandReconciliationService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Str;

final class UnifiAccessFixtureDnsResolver implements DnsResolver
{
    /** @param list<string> $addresses */
    public function __construct(public array $addresses = ['10.77.4.5']) {}

    public function resolve(string $host): array
    {
        return $this->addresses;
    }
}

final class UnifiAccessFixtureLeaseProvider implements CredentialLeaseProvider
{
    /** @var list<array{site_id: int, reference: string, capabilities: array<int, string>}> */
    public array $calls = [];

    public function __construct(public string $token = 'UNIFI-ACCESS-API-TOKEN-SECRET') {}

    public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
    {
        $this->calls[] = compact('siteId', 'reference', 'capabilities');

        return new CredentialLease(
            'unifi-command-lease-'.count($this->calls),
            CarbonImmutable::now('UTC')->addMinute(),
            ['api_token' => $this->token],
        );
    }
}

final class UnifiAccessFixtureSecretIssuer implements SecretManagerLeaseIssuer
{
    /** @var list<string> */
    public array $revoked = [];

    public function issue(SecretLeaseRequest $request): CredentialLease
    {
        throw new RuntimeException('The adapter must acquire through the governed lease provider.');
    }

    public function revoke(string $leaseId): void
    {
        $this->revoked[] = $leaseId;
    }
}

final class UnifiAccessFixtureHttpTransport implements CommandHttpTransport
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
            throw new RuntimeException('No UniFi Access fixture response remains.');
        }

        return $response;
    }
}

/** @return array{site: Site, device: Device, reference: CredentialReference} */
function unifiAccessCommandDevice(): array
{
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $doorId = '0ed545f8-2fcd-4839-9021-b39e707f6aa9';
    $device = Device::factory()->security()->create([
        'name' => 'Harbour service door',
        'category' => 'access_control',
        'subcategory' => 'door_controller',
        'provider' => 'unifi',
        'external_ref' => [
            'provider' => 'unifi',
            'provider_resource_kind' => 'door',
            'provider_door_id' => $doorId,
        ],
        'config' => [
            'management' => [
                'capabilities' => ['access.door.unlock_timed'],
                'unifi_access' => ['unlock_duration_seconds' => 15],
            ],
        ],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assigned_at' => now()->subMinute(),
    ]);
    DiscoveryScope::factory()->create([
        'site_id' => $site->id,
        'collector_id' => null,
        'cidrs' => ['10.77.0.0/16'],
        'protocols' => ['provider'],
        'port_bounds' => ['provider' => [12445]],
        'exclusions' => [],
        'status' => 'active',
    ]);
    IntegrationSiteSecret::query()->create([
        'site_id' => $site->id,
        'provider' => 'unifi',
        'capability' => 'access_api',
        'base_url' => 'https://access.example.test:12445',
        'secret_encrypted' => 'LEGACY-SECRET-MUST-NOT-BE-READ',
        'is_enabled' => true,
        'last_tested_at' => now(),
        'last_error' => null,
    ]);
    $reference = CredentialReference::query()->create([
        'reference_key' => 'vault:unifi/'.$site->id.'/access-management',
        'site_id' => $site->id,
        'provider' => 'unifi',
        'purpose' => 'device_management',
        'capabilities' => ['command:access.door.unlock_timed'],
        'secret_manager_reference' => 'secret/data/sites/'.$site->id.'/unifi-access',
        'secret_manager_reference_hash' => hash('sha256', 'unifi-access-'.$site->id),
        'status' => CredentialReferenceStatus::Active,
        'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed,
        'version' => 1,
        'last_tested_at' => now(),
    ]);

    return compact('site', 'device', 'reference');
}

/** @param list<CommandHttpResponse> $responses @return array{adapter: UnifiAccessCommandAdapter, http: UnifiAccessFixtureHttpTransport, leases: UnifiAccessFixtureLeaseProvider, issuer: UnifiAccessFixtureSecretIssuer} */
function unifiAccessCommandAdapter(array $responses, array $cidrs = ['10.77.0.0/16']): array
{
    app()->instance(DnsResolver::class, new UnifiAccessFixtureDnsResolver);
    DiscoveryScope::query()->update(['cidrs' => $cidrs]);
    app()->forgetInstance(ApprovedProbeScopeProvider::class);
    app()->forgetInstance(EgressPolicy::class);
    $leases = new UnifiAccessFixtureLeaseProvider;
    $issuer = new UnifiAccessFixtureSecretIssuer;
    $http = new UnifiAccessFixtureHttpTransport($responses);
    $credentials = new CommandCredentialLeaseService(
        $leases,
        new CredentialLeaseLifecycleService($issuer, new CredentialReferenceRules),
    );
    $adapter = new UnifiAccessCommandAdapter(
        app(CanonicalDeviceSiteResolver::class),
        $credentials,
        app(EgressPolicy::class),
        $http,
    );

    return compact('adapter', 'http', 'leases', 'issuer');
}

/** @return array{requester: User, approver: User} */
function unifiAccessCommandActors(): array
{
    $role = Role::query()->where('name', 'it_manager')->firstOrFail();
    $requester = User::factory()->create(['approved_at' => now()]);
    $approver = User::factory()->create(['approved_at' => now()]);
    $requester->roles()->attach($role);
    $approver->roles()->attach($role);

    return compact('requester', 'approver');
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.signing.active_key_id', 'unifi-access-command-test');
    config()->set('monitoring.signing.keys', [
        'unifi-access-command-test' => base64_encode(str_repeat('U', SODIUM_CRYPTO_AUTH_KEYBYTES)),
    ]);
});

it('advertises the UniFi door action only with exact fresh Site endpoint mapping and governed credentials', function () {
    $record = unifiAccessCommandDevice();
    $fixture = unifiAccessCommandAdapter([]);

    expect($fixture['adapter']->supports($record['device'], 'access.door.unlock_timed'))->toBeTrue()
        ->and($fixture['adapter']->supports($record['device'], 'access.door.lock'))->toBeFalse();

    $record['reference']->update(['rotation_status' => CredentialRotationStatus::Overdue]);
    expect($fixture['adapter']->supports($record['device']->fresh(), 'access.door.unlock_timed'))->toBeFalse();

    $record['reference']->update(['rotation_status' => CredentialRotationStatus::Current]);
    IntegrationSiteSecret::query()->where('site_id', $record['site']->id)->update([
        'last_tested_at' => now()->subDays(2),
    ]);
    expect($fixture['adapter']->supports($record['device']->fresh(), 'access.door.unlock_timed'))->toBeFalse();

    IntegrationSiteSecret::query()->where('site_id', $record['site']->id)->update(['last_tested_at' => now()]);
    DiscoveryScope::query()->where('site_id', $record['site']->id)->update(['exclusions' => ['10.77.4.5']]);
    expect($fixture['adapter']->supports($record['device']->fresh(), 'access.door.unlock_timed'))->toBeFalse();
});

it('executes the official remote unlock contract and freshly reconciles the exact mapped door', function () {
    $record = unifiAccessCommandDevice();
    $doorId = $record['device']->external_ref['provider_door_id'];
    $fixture = unifiAccessCommandAdapter([
        new CommandHttpResponse(200, json_encode([
            'code' => 'SUCCESS',
            'data' => ['id' => $doorId, 'is_bind_hub' => true, 'door_lock_relay_status' => 'lock'],
        ], JSON_THROW_ON_ERROR)),
        new CommandHttpResponse(200, '{"code":"SUCCESS","data":"success","msg":"success"}'),
        new CommandHttpResponse(200, json_encode([
            'code' => 'SUCCESS',
            'data' => ['id' => $doorId, 'is_bind_hub' => true, 'door_lock_relay_status' => 'lock'],
        ], JSON_THROW_ON_ERROR)),
    ]);
    $context = new CommandExecutionContext(
        commandUuid: (string) Str::uuid(),
        attemptUuid: (string) Str::uuid(),
        attemptNumber: 1,
        device: $record['device'],
        siteId: $record['site']->id,
        capability: 'access.door.unlock_timed',
        parameters: ['duration_seconds' => 15],
        expectedState: ['locked' => true],
        idempotencyKey: 'unifi-access-contract-1',
        expiresAt: CarbonImmutable::now('UTC')->addMinutes(5),
    );

    $result = $fixture['adapter']->execute($context);
    $observation = $fixture['adapter']->observe($context);

    expect($result->status)->toBe(CommandAttemptStatus::Succeeded)
        ->and($result->safeSummary)->toBe([
            'provider_state' => 'accepted',
            'previous_lock_state' => 'locked',
            'unlock_duration_seconds' => 15,
        ])
        ->and($observation->state)->toBe(['locked' => true])
        ->and($fixture['http']->calls)->toHaveCount(3)
        ->and($fixture['http']->calls[0]['method'])->toBe('GET')
        ->and($fixture['http']->calls[1]['method'])->toBe('PUT')
        ->and($fixture['http']->calls[2]['method'])->toBe('GET')
        ->and($fixture['http']->calls[1]['json']['extra'])->toMatchArray([
            'command_uuid' => $context->commandUuid,
            'attempt_uuid' => $context->attemptUuid,
            'attempt_number' => 1,
            'duration_seconds' => 15,
        ])
        ->and($fixture['http']->calls[1]['target']->url())->toEndWith("/api/v1/developer/doors/{$doorId}/unlock")
        ->and($fixture['issuer']->revoked)->toBe(['unifi-command-lease-1', 'unifi-command-lease-2'])
        ->and(json_encode([$result, $observation], JSON_THROW_ON_ERROR))->not->toContain($fixture['leases']->token)
        ->not->toContain('LEGACY-SECRET-MUST-NOT-BE-READ');
});

it('surfaces provider rate limiting once without repeating the side effect', function () {
    $record = unifiAccessCommandDevice();
    $doorId = $record['device']->external_ref['provider_door_id'];
    $fixture = unifiAccessCommandAdapter([
        new CommandHttpResponse(200, json_encode([
            'code' => 'SUCCESS',
            'data' => ['id' => $doorId, 'is_bind_hub' => true, 'door_lock_relay_status' => 'lock'],
        ], JSON_THROW_ON_ERROR)),
        new CommandHttpResponse(429, '{"code":"RATE_LIMITED"}'),
    ]);
    $context = new CommandExecutionContext(
        commandUuid: (string) Str::uuid(),
        attemptUuid: (string) Str::uuid(),
        attemptNumber: 1,
        device: $record['device'],
        siteId: $record['site']->id,
        capability: 'access.door.unlock_timed',
        parameters: ['duration_seconds' => 15],
        expectedState: ['locked' => true],
        idempotencyKey: 'unifi-access-rate-limit',
        expiresAt: CarbonImmutable::now('UTC')->addMinutes(5),
    );

    $result = $fixture['adapter']->execute($context);

    expect($result->status)->toBe(CommandAttemptStatus::Failed)
        ->and($result->safeFailureReason)->toBe('UniFi Access rate-limited the door action.')
        ->and($fixture['http']->calls)->toHaveCount(2)
        ->and($fixture['http']->calls[1]['method'])->toBe('PUT')
        ->and($fixture['issuer']->revoked)->toBe(['unifi-command-lease-1']);
});

it('fails before provider transport when the UniFi endpoint resolves outside approved Site networks', function () {
    $record = unifiAccessCommandDevice();
    $fixture = unifiAccessCommandAdapter([], ['10.88.0.0/16']);
    $context = new CommandExecutionContext(
        commandUuid: (string) Str::uuid(),
        attemptUuid: (string) Str::uuid(),
        attemptNumber: 1,
        device: $record['device'],
        siteId: $record['site']->id,
        capability: 'access.door.unlock_timed',
        parameters: ['duration_seconds' => 15],
        expectedState: ['locked' => true],
        idempotencyKey: 'unifi-access-egress-denial',
        expiresAt: CarbonImmutable::now('UTC')->addMinutes(5),
    );

    expect(fn () => $fixture['adapter']->execute($context))
        ->toThrow(EgressDenied::class, 'resolved address outside scope')
        ->and($fixture['http']->calls)->toBe([])
        ->and($fixture['issuer']->revoked)->toBe(['unifi-command-lease-1']);
});

it('runs the production UniFi adapter through request approval dispatch and relock reconciliation', function () {
    $record = unifiAccessCommandDevice();
    $actors = unifiAccessCommandActors();
    $doorId = $record['device']->external_ref['provider_door_id'];
    $fixture = unifiAccessCommandAdapter([
        new CommandHttpResponse(200, json_encode([
            'code' => 'SUCCESS',
            'data' => ['id' => $doorId, 'is_bind_hub' => true, 'door_lock_relay_status' => 'lock'],
        ], JSON_THROW_ON_ERROR)),
        new CommandHttpResponse(200, '{"code":"SUCCESS","data":"success"}'),
        new CommandHttpResponse(200, json_encode([
            'code' => 'SUCCESS',
            'data' => ['id' => $doorId, 'is_bind_hub' => true, 'door_lock_relay_status' => 'lock'],
        ], JSON_THROW_ON_ERROR)),
    ]);
    app()->instance(
        CommandExecutionAdapterRegistry::class,
        new CommandExecutionAdapterRegistry([$fixture['adapter']]),
    );
    $command = app(DeviceCommandRequestService::class)->request(
        $record['device'],
        $actors['requester'],
        new CommandRequestInput(
            capability: 'access.door.unlock_timed',
            parameters: ['duration_seconds' => 15],
            reason: 'Let the verified maintenance technician through the service entrance.',
            idempotencyKey: 'unifi-access-e2e-'.$record['device']->id,
            stepUpConfirmedAt: CarbonImmutable::now('UTC')->startOfSecond(),
            impactAcknowledged: true,
        ),
    );
    app(DeviceCommandApprovalService::class)->decide(
        $command,
        $actors['approver'],
        new CommandDecisionInput(
            decision: CommandApprovalDecision::Approved,
            comment: 'Technician identity and service window independently confirmed.',
        ),
    );

    $attempt = app(CommandDispatchPort::class)
        ->dispatch($command->fresh(), $actors['requester']);
    $reconciliation = app(DeviceCommandReconciliationService::class)
        ->reconcile($command->fresh(), $actors['requester']);

    expect($attempt->status)->toBe(CommandAttemptStatus::Succeeded)
        ->and($reconciliation->outcome)->toBe(CommandReconciliationOutcome::Matched)
        ->and($command->fresh()->status)->toBe(CommandStatus::Reconciled)
        ->and($command->attempts()->count())->toBe(1)
        ->and($command->reconciliations()->count())->toBe(1)
        ->and($command->auditEvents()->where('action', 'reconciliation_matched')->count())->toBe(1)
        ->and(json_encode($command->fresh()->toArray(), JSON_THROW_ON_ERROR))->not->toContain($fixture['leases']->token)
        ->not->toContain('LEGACY-SECRET-MUST-NOT-BE-READ');
});
