<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Services\CollectorConfigurationService;
use App\Domain\Monitoring\Services\CollectorIngestService;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Management\Data\CommandDecisionInput;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandReconciliation;
use App\Domain\SecurityDevices\Management\Services\CollectorCommandRecoveryService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandApprovalService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Management\Services\GovernedCommandDispatchService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Validation\ValidationException;

final class CollectorCommandFixtureDns implements DnsResolver
{
    public function resolve(string $host): array
    {
        return ['10.77.4.5'];
    }
}

final class CollectorCommandFixtureLeases implements CredentialLeaseProvider
{
    public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
    {
        return new CredentialLease(
            'collector-command-fixture-lease',
            CarbonImmutable::now('UTC')->addMinute(),
            ['api_token' => 'REMOTE-COLLECTOR-UNIFI-TOKEN-SECRET'],
        );
    }
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.signing.active_key_id', 'collector-command-request');
    config()->set('monitoring.signing.keys', [
        'collector-command-request' => base64_encode(str_repeat('C', SODIUM_CRYPTO_AUTH_KEYBYTES)),
    ]);
    $configPair = sodium_crypto_sign_seed_keypair(str_repeat("\x31", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    config()->set(
        'monitoring.collector.signing_secret_key',
        base64_encode(sodium_crypto_sign_secretkey($configPair)),
    );
    app()->instance(DnsResolver::class, new CollectorCommandFixtureDns);
    app()->instance(CredentialLeaseProvider::class, new CollectorCommandFixtureLeases);
});

it('delivers one signed remote command and ingests its ordered immutable reconciled result', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $role = Role::query()->where('name', 'it_manager')->firstOrFail();
    $requester = User::factory()->create(['approved_at' => now()]);
    $approver = User::factory()->create(['approved_at' => now()]);
    $requester->roles()->attach($role);
    $approver->roles()->attach($role);

    $collectorPair = sodium_crypto_sign_seed_keypair(str_repeat("\x32", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $collectorPublic = sodium_crypto_sign_publickey($collectorPair);
    $collector = MonitoringCollector::factory()->create([
        'site_id' => $site->id,
        'collector_uuid' => 'b49a7aa1-a9ad-49f8-ae51-7801385d18fa',
        'public_key' => base64_encode($collectorPublic),
        'public_key_fingerprint' => hash('sha256', $collectorPublic),
        'client_certificate_fingerprint' => hash('sha256', 'collector-command-certificate'),
        'status' => 'online',
        'last_seen_at' => now(),
    ]);
    $device = Device::factory()->security()->create([
        'name' => 'Remote service entrance',
        'category' => 'access_control',
        'subcategory' => 'door_controller',
        'provider' => 'unifi',
        'last_seen_at' => now(),
        'external_ref' => [
            'provider' => 'unifi',
            'provider_resource_kind' => 'door',
            'provider_door_id' => '0ed545f8-2fcd-4839-9021-b39e707f6aa9',
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
    $profile = MonitoringProfile::factory()->create([
        'stale_after_seconds' => 300,
        'interval_seconds' => 60,
        'is_active' => true,
    ]);
    Monitor::factory()->create([
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'collector_id' => $collector->id,
        'kind' => MonitorKind::Icmp,
        'target' => '10.77.4.5',
        'current_state' => MonitorState::Healthy,
        'last_observation_at' => now(),
        'is_enabled' => true,
    ]);
    DiscoveryScope::factory()->create([
        'site_id' => $site->id,
        'collector_id' => $collector->id,
        'cidrs' => ['10.77.0.0/16'],
        'seed_hosts' => [],
        'protocols' => ['provider'],
        'port_bounds' => ['provider' => [12445]],
        'exclusions' => [],
        'status' => 'active',
    ]);
    IntegrationSiteSecret::query()->create([
        'site_id' => $site->id,
        'provider' => 'unifi',
        'capability' => 'access_api',
        'base_url' => 'https://access.remote.example.test:12445',
        'secret_encrypted' => 'LEGACY-SECRET-MUST-NOT-BE-READ',
        'is_enabled' => true,
        'last_tested_at' => now(),
        'last_error' => null,
    ]);
    CredentialReference::query()->create([
        'reference_key' => 'vault:unifi/'.$site->id.'/remote-access-management',
        'site_id' => $site->id,
        'provider' => 'unifi',
        'purpose' => 'device_management',
        'capabilities' => ['command:access.door.unlock_timed'],
        'secret_manager_reference' => 'secret/data/sites/'.$site->id.'/unifi-access',
        'secret_manager_reference_hash' => hash('sha256', 'collector-command-'.$site->id),
        'status' => CredentialReferenceStatus::Active,
        'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed,
        'version' => 1,
        'last_tested_at' => now(),
    ]);

    $command = app(DeviceCommandRequestService::class)->request(
        $device,
        $requester,
        new CommandRequestInput(
            capability: 'access.door.unlock_timed',
            parameters: ['duration_seconds' => 15],
            reason: 'Let the approved engineer through the remote service entrance.',
            idempotencyKey: 'remote-door-'.$device->id,
            stepUpConfirmedAt: CarbonImmutable::now('UTC'),
            impactAcknowledged: true,
        ),
    );
    expect($command->collector_id)->toBe($collector->id)
        ->and($command->status)->toBe(CommandStatus::AwaitingApproval);

    $command = app(DeviceCommandApprovalService::class)->decide(
        $command,
        $approver,
        new CommandDecisionInput(
            CommandApprovalDecision::Approved,
            'Approved for the named engineer and the bounded access window.',
        ),
    );
    $attempt = app(GovernedCommandDispatchService::class)->dispatch($command, $requester);
    expect($attempt->runtime)->toBe('collector')
        ->and($attempt->status)->toBe(CommandAttemptStatus::Dispatching)
        ->and($command->fresh()->execution_route)->toBe('collector')
        ->and($command->fresh()->status)->toBe(CommandStatus::Dispatching);

    $envelope = app(CollectorConfigurationService::class)->signedEnvelope($collector, 0);
    $signed = json_decode($envelope, true, flags: JSON_THROW_ON_ERROR);
    $configuration = json_decode(base64_decode($signed['payload'], true), true, flags: JSON_THROW_ON_ERROR);
    expect($configuration['version'])->toBe(3)
        ->and($configuration['commands'])->toHaveCount(1)
        ->and($configuration['commands'][0]['command_uuid'])->toBe($command->command_uuid)
        ->and($configuration['commands'][0]['attempt_uuid'])->toBe($attempt->attempt_uuid)
        ->and($configuration['commands'][0]['site_id'])->toBe($site->id)
        ->and($configuration['commands'][0]['device_id'])->toBe((string) $device->id)
        ->and($configuration['commands'][0]['target'])->toBe('10.77.4.5')
        ->and($configuration['commands'][0]['adapter'])->toBe('unifi_access_timed_unlock_v1')
        ->and(json_encode($configuration, JSON_THROW_ON_ERROR))->not->toContain(
            'REMOTE-COLLECTOR-UNIFI-TOKEN-SECRET',
            'LEGACY-SECRET-MUST-NOT-BE-READ',
            $command->signature,
        );

    $descriptor = $configuration['commands'][0];
    $at = CarbonImmutable::now('UTC')->startOfSecond();
    $payload = [
        'item_type' => 'command_result',
        'command_uuid' => $command->command_uuid,
        'attempt_uuid' => $attempt->attempt_uuid,
        'attempt_number' => 1,
        'site_id' => $site->id,
        'device_id' => (string) $device->id,
        'capability' => 'access.door.unlock_timed',
        'contract_hash' => $descriptor['contract_hash'],
        'execution_status' => 'succeeded',
        'safe_result' => [
            'provider_state' => 'accepted',
            'previous_lock_state' => 'locked',
            'unlock_duration_seconds' => 15,
        ],
        'provider_request_reference' => 'unifi-access:'.$command->command_uuid,
        'safe_failure_reason' => null,
        'accepted_at' => $at->format(DATE_ATOM),
        'started_at' => $at->format(DATE_ATOM),
        'completed_at' => $at->format(DATE_ATOM),
        'reconciliation' => [
            'outcome' => 'matched',
            'observed_state' => ['locked' => true],
            'observation_reference' => 'collector-unifi:door-state:'.hash('sha256', $attempt->attempt_uuid),
            'safe_evidence_summary' => 'The remote Site collector freshly confirmed that the door relay returned to locked.',
            'observed_at' => $at->format(DATE_ATOM),
        ],
    ];
    $items = [[
        'id' => 'command-result:'.$attempt->attempt_uuid,
        'source_sequence' => 1,
        'created_at' => $at->format(DATE_ATOM),
        'payload' => $payload,
    ]];
    $acknowledgement = app(CollectorIngestService::class)->ingest($collector, $items);
    expect($acknowledgement)->toBe([
        'acknowledged_ids' => ['command-result:'.$attempt->attempt_uuid],
        'acknowledged_source_sequence' => 1,
    ])
        ->and($attempt->fresh()->status)->toBe(CommandAttemptStatus::Succeeded)
        ->and($attempt->fresh()->evidence_reference)->toBe(
            'collector:'.$collector->collector_uuid.':sequence:1',
        )
        ->and($command->fresh()->status)->toBe(CommandStatus::Reconciled)
        ->and(DeviceCommandReconciliation::query()->where('device_command_request_id', $command->id)->count())->toBe(1)
        ->and(DeviceEvent::query()->where([
            'device_id' => $device->id,
            'event_type' => 'management_command_reconciled',
        ])->count())->toBe(1);

    $evidenceResponse = $this->actingAs($requester)->get(
        "/security-devices/devices/{$device->id}/commands/{$command->id}/evidence",
    );
    $evidenceResponse->assertOk()
        ->assertHeader('Content-Type', 'application/json; charset=UTF-8')
        ->assertHeader('Cache-Control', 'no-store, private');
    $evidence = json_decode($evidenceResponse->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
    expect($evidence['schema_version'])->toBe(1)
        ->and($evidence['command']['uuid'])->toBe($command->command_uuid)
        ->and($evidence['command']['execution_route'])->toBe('collector')
        ->and($evidence['attempts'][0]['runtime'])->toBe('collector')
        ->and($evidence['reconciliations'][0]['outcome'])->toBe('matched')
        ->and($evidence['audit_chain']['linked'])->toBeTrue()
        ->and(collect($evidence['audit_chain']['events'])->pluck('action'))->toContain('evidence_exported')
        ->and(json_encode($evidence, JSON_THROW_ON_ERROR))->not->toContain(
            'Let the approved engineer through the remote service entrance.',
            'REMOTE-COLLECTOR-UNIFI-TOKEN-SECRET',
            'LEGACY-SECRET-MUST-NOT-BE-READ',
            'secret/data/sites/',
            $command->signature,
        );

    $outsideSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $outsideViewer = User::factory()->create(['approved_at' => now()]);
    $outsideViewer->roles()->attach(Role::query()->where('name', 'coordinator')->firstOrFail());
    HrEmployeeProfile::factory()->create([
        'user_id' => $outsideViewer->id,
        'primary_site_id' => $outsideSite->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);
    $this->actingAs($outsideViewer)
        ->get("/security-devices/devices/{$device->id}/commands/{$command->id}/evidence")
        ->assertNotFound();
    expect($command->auditEvents()->where('action', 'evidence_exported')->count())->toBe(1);

    $items[0]['id'] = 'command-result-replayed:'.$attempt->attempt_uuid;
    $items[0]['source_sequence'] = 2;
    app(CollectorIngestService::class)->ingest($collector->fresh(), $items);
    expect(DeviceCommandReconciliation::query()->where('device_command_request_id', $command->id)->count())->toBe(1)
        ->and(DeviceEvent::query()->where([
            'device_id' => $device->id,
            'event_type' => 'management_command_reconciled',
        ])->count())->toBe(1);

    $items[0]['id'] = 'command-result-tampered:'.$attempt->attempt_uuid;
    $items[0]['source_sequence'] = 3;
    $items[0]['payload']['contract_hash'] = str_repeat('0', 64);
    app(CollectorIngestService::class)->ingest($collector->fresh(), $items);
    expect(MonitoringDeadLetter::query()->where([
        'consumer' => 'collector-intake',
        'site_id' => $site->id,
    ])->count())->toBe(1)
        ->and(DeviceCommandReconciliation::query()->where('device_command_request_id', $command->id)->count())->toBe(1);

    $blocked = app(DeviceCommandRequestService::class)->request(
        $device,
        $requester,
        new CommandRequestInput(
            capability: 'access.door.unlock_timed',
            parameters: ['duration_seconds' => 15],
            reason: 'Prepare a second bounded remote entrance access request.',
            idempotencyKey: 'remote-door-blocked-'.$device->id,
            stepUpConfirmedAt: CarbonImmutable::now('UTC'),
            impactAcknowledged: true,
        ),
    );
    $blocked = app(DeviceCommandApprovalService::class)->decide(
        $blocked,
        $approver,
        new CommandDecisionInput(
            CommandApprovalDecision::Approved,
            'Approved only while the enrolled remote collection path remains current.',
        ),
    );
    $collector->forceFill(['status' => 'unavailable'])->save();
    expect(fn () => app(GovernedCommandDispatchService::class)->dispatch($blocked, $requester))
        ->toThrow(ValidationException::class)
        ->and($blocked->fresh()->status)->toBe(CommandStatus::Blocked)
        ->and($blocked->fresh()->blocked_reason_code)->toBe('collector_delivery_unavailable')
        ->and($blocked->attempts()->count())->toBe(0);

    $collector->forceFill(['status' => 'online'])->save();
    $requestCommand = function (string $key, string $reason) use (
        $device,
        $requester,
        $approver,
    ) {
        $request = app(DeviceCommandRequestService::class)->request(
            $device,
            $requester,
            new CommandRequestInput(
                capability: 'access.door.unlock_timed',
                parameters: ['duration_seconds' => 15],
                reason: $reason,
                idempotencyKey: $key,
                stepUpConfirmedAt: CarbonImmutable::now('UTC'),
                impactAcknowledged: true,
            ),
        );

        return app(DeviceCommandApprovalService::class)->decide(
            $request,
            $approver,
            new CommandDecisionInput(
                CommandApprovalDecision::Approved,
                'Approved for collector timeout and recovery-path verification.',
            ),
        );
    };

    $neverIssued = $requestCommand(
        'remote-door-never-issued-'.$device->id,
        'Prove expiry before any remote collector configuration is issued.',
    );
    $neverIssuedAttempt = app(GovernedCommandDispatchService::class)->dispatch($neverIssued, $requester);
    $recovery = app(CollectorCommandRecoveryService::class);
    expect($recovery->recover($neverIssued->expires_at->addSecond()))->toBe([
        'expired_before_delivery' => 1,
        'uncertain_after_delivery' => 0,
    ])
        ->and($neverIssued->fresh()->status)->toBe(CommandStatus::Expired)
        ->and($neverIssuedAttempt->fresh()->status)->toBe(CommandAttemptStatus::Expired);

    $issuedWithoutResult = $requestCommand(
        'remote-door-issued-no-result-'.$device->id,
        'Prove an issued command becomes uncertain when its ordered result never returns.',
    );
    $issuedAttempt = app(GovernedCommandDispatchService::class)->dispatch($issuedWithoutResult, $requester);
    app(CollectorConfigurationService::class)->signedEnvelope($collector->fresh(), 1);
    expect($issuedAttempt->fresh()->provider_request_reference)->toContain(':config:2')
        ->and($recovery->recover($issuedWithoutResult->expires_at->addSecond()))->toBe([
            'expired_before_delivery' => 0,
            'uncertain_after_delivery' => 1,
        ])
        ->and($issuedWithoutResult->fresh()->status)->toBe(CommandStatus::Uncertain)
        ->and($issuedAttempt->fresh()->status)->toBe(CommandAttemptStatus::Uncertain)
        ->and($issuedWithoutResult->fresh()->safe_failure_reason)->toContain('was not repeated');
});
