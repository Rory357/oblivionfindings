<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorConfigurationService;
use App\Domain\Monitoring\Services\CollectorIngestService;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Management\Contracts\CommandHttpTransport;
use App\Domain\SecurityDevices\Management\Data\CommandHttpResponse;
use App\Domain\SecurityDevices\Management\Data\CommandDecisionInput;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionAdapterRegistry;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionRouteResolver;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandApprovalService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Management\Services\GovernedCommandDispatchService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Str;

/*
 * S&D Phase E (R6): arming and disarming an Inner Range Inception or Gallagher panel. When a hub
 * covers the Site the hub sends it and reads the area back; the server never connects into the
 * house. A hub too old to know the job is never sent one (it would refuse its whole config).
 */

const HUB_ALARM_ADAPTERS = 'unifi_access_timed_unlock_v1,ssh_config_backup_v1,camera_snapshot_v1,alarm_area_control_v1';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.signing.active_key_id', 'hub-routed-alarm');
    config()->set('monitoring.signing.keys', ['hub-routed-alarm' => base64_encode(str_repeat('A', SODIUM_CRYPTO_AUTH_KEYBYTES))]);
    $configPair = sodium_crypto_sign_seed_keypair(str_repeat("\x43", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey($configPair)));

    $this->site = Site::factory()->create(['name' => 'Totara Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    DiscoveryScope::factory()->create([
        'site_id' => $this->site->id, 'collector_id' => null, 'cidrs' => ['10.66.0.0/24'], 'protocols' => ['tcp'],
        'port_bounds' => ['tcp' => [443, 8904]], 'exclusions' => [], 'status' => 'active',
    ]);
    $this->panel = Device::factory()->create([
        'name' => 'Totara Lodge alarm', 'domain' => 'security', 'category' => 'alarm',
        'manufacturer' => 'Gallagher Security', 'model' => 'Controller 6000', 'provider' => 'gallagher',
        'ip_address' => '10.66.0.20', 'last_seen_at' => now(),
        'config' => ['alarm' => ['base_url' => 'https://10.66.0.20:8904']],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $this->panel->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
    ]);
    CredentialReference::query()->create([
        'reference_key' => 'database:gallagher/'.$this->site->id.'/alarm', 'site_id' => $this->site->id, 'provider' => 'gallagher',
        'purpose' => 'device_management', 'capabilities' => ['command:alarm.arm', 'command:alarm.disarm'],
        'secret_manager_reference' => 'secret/data/sites/'.$this->site->id.'/gallagher',
        'secret_manager_reference_hash' => hash('sha256', 'hub-routed-alarm-'.$this->site->id),
        'status' => CredentialReferenceStatus::Active, 'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed, 'version' => 1, 'last_tested_at' => now(),
    ]);

    $hubPair = sodium_crypto_sign_seed_keypair(str_repeat("\x44", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $hubPublic = sodium_crypto_sign_publickey($hubPair);
    $this->hub = MonitoringCollector::factory()->create([
        'site_id' => $this->site->id, 'name' => 'Totara Lodge', 'collector_uuid' => (string) Str::uuid(),
        'public_key' => base64_encode($hubPublic), 'public_key_fingerprint' => hash('sha256', $hubPublic),
        'client_certificate_fingerprint' => hash('sha256', 'hub-routed-alarm-certificate'),
        'status' => 'online', 'last_seen_at' => now(), 'approved_at' => now()->subDay(), 'revoked_at' => null,
        'runtime_status' => ['version' => '2026.11.1', 'command_adapters' => HUB_ALARM_ADAPTERS],
    ]);

    $this->app->instance(DnsResolver::class, new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            return ['10.66.0.20'];
        }
    });
    $this->app->instance(CredentialLeaseProvider::class, new class implements CredentialLeaseProvider
    {
        public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
        {
            return new CredentialLease('lease-'.Str::uuid(), now()->toImmutable()->addMinutes(5), ['api_key' => 'HUB-ALARM-TEST-ONLY-KEY']);
        }
    });
    // The server's own path into the panel must never be used while a hub covers the Site.
    $this->direct = new class implements CommandHttpTransport
    {
        public int $calls = 0;

        public function request(AuthorizedProbeTarget $target, string $method, array $headers = [], ?array $json = null): CommandHttpResponse
        {
            $this->calls++;

            return new CommandHttpResponse(500, '{}');
        }
    };
    $this->app->instance(CommandHttpTransport::class, $this->direct);
    foreach ([ApprovedProbeScopeProvider::class, EgressPolicy::class, CommandCredentialLeaseService::class,
        CommandExecutionAdapterRegistry::class, CommandExecutionRouteResolver::class, DeviceCommandRequestService::class,
        GovernedCommandDispatchService::class, CollectorConfigurationService::class, CollectorIngestService::class] as $service) {
        $this->app->forgetInstance($service);
    }

    $this->manager = User::factory()->create(['approved_at' => now(), 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
    $this->manager->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    HrEmployeeProfile::factory()->create(['user_id' => $this->manager->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);
    // Arming is high risk: a second person approves it before it is sent.
    $this->approver = User::factory()->create(['approved_at' => now(), 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
    $this->approver->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    HrEmployeeProfile::factory()->create(['user_id' => $this->approver->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);

    $this->requestArm = function (string $key): array {
        $command = app(DeviceCommandRequestService::class)->request($this->panel->fresh(), $this->manager, new CommandRequestInput(
            capability: 'alarm.arm', parameters: ['area' => 'zone-main'], reason: 'Night arm after the last staff member left.',
            idempotencyKey: $key, stepUpConfirmedAt: CarbonImmutable::now('UTC'), impactAcknowledged: true,
        ));
        expect($command->status)->toBe(CommandStatus::AwaitingApproval);
        $command = app(DeviceCommandApprovalService::class)->decide($command->fresh(), $this->approver, new CommandDecisionInput(
            CommandApprovalDecision::Approved, 'Approved: the house is empty and the night arm is due.',
        ));
        $attempt = app(GovernedCommandDispatchService::class)->dispatch($command->fresh(), $this->manager);

        return [$command->fresh(), $attempt];
    };

    $this->hubJobs = function (): array {
        $envelope = app(CollectorConfigurationService::class)->signedEnvelope($this->hub->fresh(), 0);
        $signed = json_decode($envelope, true, flags: JSON_THROW_ON_ERROR);

        return json_decode(base64_decode($signed['payload'], true), true, flags: JSON_THROW_ON_ERROR);
    };

    /** What the hub's AlarmAreaCommandRunner sends back. */
    $this->hubResult = function (DeviceCommandRequest $command, DeviceCommandAttempt $attempt, array $job, array $overrides = []): array {
        $at = CarbonImmutable::now('UTC')->startOfSecond();

        return array_replace([
            'item_type' => 'command_result',
            'command_uuid' => $command->command_uuid,
            'attempt_uuid' => $attempt->attempt_uuid,
            'attempt_number' => 1,
            'site_id' => $this->site->id,
            'device_id' => (string) $this->panel->id,
            'capability' => 'alarm.arm',
            'contract_hash' => $job['contract_hash'],
            'execution_status' => 'succeeded',
            'safe_result' => ['area' => 'zone-main', 'provider_state' => 'accepted'],
            'provider_request_reference' => 'alarm:'.$command->command_uuid,
            'safe_failure_reason' => null,
            'accepted_at' => $at->format(DATE_ATOM),
            'started_at' => $at->format(DATE_ATOM),
            'completed_at' => $at->format(DATE_ATOM),
            'reconciliation' => [
                'outcome' => 'matched',
                'observed_state' => ['armed' => true],
                'observation_reference' => 'collector-alarm:area-state:'.hash('sha256', $command->command_uuid.'|'.$attempt->attempt_uuid),
                'safe_evidence_summary' => 'The hub read the area straight after and it shows the state asked for.',
                'observed_at' => $at->format(DATE_ATOM),
            ],
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

it('sends the arm to the hub that covers the Site, with the area and a sealed login only', function (): void {
    [$command, $attempt] = ($this->requestArm)('hub-alarm-job');

    expect($command->collector_id)->toBe($this->hub->id)
        ->and($attempt->runtime)->toBe('collector')
        ->and($attempt->status)->toBe(CommandAttemptStatus::Dispatching)
        ->and($this->direct->calls)->toBe(0);

    $configuration = ($this->hubJobs)();
    $job = $configuration['commands'][0];
    expect($configuration['commands'])->toHaveCount(1)
        ->and($job['adapter'])->toBe('alarm_area_control_v1')
        ->and($job['protocol'])->toBe('command.alarm_rest')
        ->and($job['provider'])->toBe('gallagher')
        ->and($job['target'])->toBe('10.66.0.20')
        ->and($job['site_id'])->toBe($this->site->id)
        ->and($job['parameters'])->toBe(['area' => 'zone-main', 'operation' => 'arm'])
        ->and($job['expected_state'])->toBe(['armed' => true])
        ->and(array_keys($job['endpoint']))->toBe(['driver', 'scheme', 'host', 'port', 'address', 'connect_timeout_seconds', 'response_timeout_seconds', 'max_response_bytes'])
        ->and($job['endpoint']['port'])->toBe(8904)
        ->and($job['endpoint']['scheme'])->toBe('https')
        ->and($job['credential_lease'])->not->toHaveKey('material')
        ->and($configuration['scope']['protocols'])->toContain('command.alarm_rest')
        ->and(json_encode($configuration, JSON_THROW_ON_ERROR))->not->toContain('HUB-ALARM-TEST-ONLY-KEY');
});

it('records the panel’s own answer the hub read back', function (): void {
    [$command, $attempt] = ($this->requestArm)('hub-alarm-success');
    $job = ($this->hubJobs)()['commands'][0];

    $acknowledgement = ($this->ingest)($attempt, ($this->hubResult)($command, $attempt, $job));

    expect($acknowledgement['acknowledged_ids'])->toBe(['command-result:'.$attempt->attempt_uuid])
        ->and($attempt->fresh()->status)->toBe(CommandAttemptStatus::Succeeded)
        ->and($command->fresh()->status)->toBe(CommandStatus::Reconciled)
        ->and($command->fresh()->execution_route)->toBe('collector')
        ->and($this->direct->calls)->toBe(0);
});

it('refuses a hub answer that claims a state it wasn’t asked for, or a failure it can’t give', function (): void {
    [$command, $attempt] = ($this->requestArm)('hub-alarm-tampered');
    $job = ($this->hubJobs)()['commands'][0];

    ($this->ingest)($attempt, ($this->hubResult)($command, $attempt, $job, [
        'reconciliation' => [
            'outcome' => 'matched', 'observed_state' => ['armed' => false], 'observation_reference' => null,
            'safe_evidence_summary' => 'The hub read the area straight after and it shows the state asked for.',
            'observed_at' => CarbonImmutable::now('UTC')->format(DATE_ATOM),
        ],
    ]));
    ($this->ingest)($attempt, ($this->hubResult)($command, $attempt, $job, [
        'execution_status' => 'failed', 'safe_result' => [], 'provider_request_reference' => null,
        'safe_failure_reason' => 'Something the hub never says.', 'reconciliation' => null,
    ]), 2);

    expect($attempt->fresh()->status)->toBe(CommandAttemptStatus::Dispatching)
        ->and($this->direct->calls)->toBe(0);
});

it('never sends an alarm job to a hub too old to know it, and says why', function (): void {
    $this->hub->forceFill(['runtime_status' => ['version' => '2026.10.1', 'command_adapters' => 'unifi_access_timed_unlock_v1']])->save();

    $route = app(CommandExecutionRouteResolver::class)->resolve($this->panel->fresh(), $this->site->id, 'alarm.disarm');

    expect($route->available)->toBeFalse()
        ->and($route->reason)->toContain('needs its software update before it can arm or disarm the alarm')
        ->and($this->direct->calls)->toBe(0);
});

it('leaves an already-queued alarm job out of the config if the hub went back to an older version', function (): void {
    [$command, $attempt] = ($this->requestArm)('hub-alarm-downgrade');
    $this->hub->forceFill(['runtime_status' => ['version' => '2026.10.1']])->save();

    // The alarm job was this hub's only work, so with it left out the server signs no config at all.
    expect(fn () => ($this->hubJobs)())->toThrow(DomainException::class, 'Collector has no executable work');
});

it('never falls back to the server when the covering hub is offline', function (): void {
    $this->hub->forceFill(['status' => 'offline'])->save();

    $route = app(CommandExecutionRouteResolver::class)->resolve($this->panel->fresh(), $this->site->id, 'alarm.arm');

    expect($route->available)->toBeFalse()
        ->and($route->reason)->toContain('Totara Lodge hub isn’t connected')
        ->and($this->direct->calls)->toBe(0);
});

it('still uses the server’s own connection where no hub covers the Site', function (): void {
    $this->hub->forceFill(['revoked_at' => now()])->save();

    $route = app(CommandExecutionRouteResolver::class)->resolve($this->panel->fresh(), $this->site->id, 'alarm.arm');

    expect($route->available)->toBeTrue()
        ->and($route->mode)->toBe('central');
});
