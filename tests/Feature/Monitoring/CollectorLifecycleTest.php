<?php

use App\Domain\Monitoring\Contracts\CollectorCertificateIssuer;
use App\Domain\Monitoring\Data\CollectorCertificateBundle;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryResult;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Discovery\Services\DiscoveryRunner;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Jobs\CompleteDiscoveryRun;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Services\CollectorEnrollmentService;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialLeaseGrant;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Services\CredentialReferenceRules;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class CollectorEnvelopeFixtureSecretIssuer implements SecretManagerLeaseIssuer
{
    /** @var list<string> */
    public array $revoked = [];

    public function issue(SecretLeaseRequest $request): CredentialLease
    {
        return new CredentialLease(
            'collector-envelope-lease-sentinel',
            $request->expiresAt,
            [
                'username' => 'collector-envelope-user',
                'auth_passphrase' => 'collector-envelope-secret-sentinel',
                'privacy_passphrase' => 'collector-envelope-privacy-sentinel',
            ],
        );
    }

    public function revoke(string $leaseId): void
    {
        $this->revoked[] = $leaseId;
    }
}

beforeEach(function () {
    configureCollectorLifecycleTest();
});

function configureCollectorLifecycleTest(): void
{
    CarbonImmutable::setTestNow('2026-07-23T12:00:00Z');
    config()->set('monitoring.collector.trusted_proxy_cidrs', ['10.20.30.0/24']);
    config()->set('monitoring.collector.replay_store', 'array');
    config()->set('monitoring.collector.allow_local_replay_store_for_tests', true);
    config()->set('monitoring.collector.allow_proxy_fingerprint_header', true);
    config()->set('monitoring.collector.request_clock_skew_seconds', 300);
    config()->set('monitoring.collector.configuration_lifetime_seconds', 600);
    config()->set('monitoring.collector.signing_secret_key', base64_encode(collectorCentralSecretKey()));

    app()->instance(CollectorCertificateIssuer::class, new class implements CollectorCertificateIssuer
    {
        public function issue(string $collectorUuid): CollectorCertificateBundle
        {
            return new CollectorCertificateBundle(
                certificatePem: "-----BEGIN CERTIFICATE-----\nfixture-{$collectorUuid}\n-----END CERTIFICATE-----\n",
                privateKeyPem: "-----BEGIN PRIVATE KEY-----\nfixture\n-----END PRIVATE KEY-----\n",
                fingerprint: str_repeat('a', 64),
                expiresAt: CarbonImmutable::now('UTC')->addYear(),
            );
        }
    });
}

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('consumes one enrolment token and binds one collector to its approved Site and projection Device', function () {
    $site = collectorLifecycleSite();
    $actor = User::factory()->create();
    $issue = app(CollectorEnrollmentService::class)->issue($site->id, $actor->id);
    $pair = collectorRequestKeyPair();
    $collectorUuid = '018f0000-0000-7000-8000-000000000009';

    $response = $this->withToken($issue->plainToken)->postJson('/api/monitoring/collectors/enrol', [
        'collector_id' => $collectorUuid,
        'collector_public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
    ])->assertCreated();

    $collector = MonitoringCollector::query()->where('collector_uuid', $collectorUuid)->sole();
    expect($response->json('site_id'))->toBe($site->id)
        ->and($response->json('central_signing_public_key'))->toBe(base64_encode(
            sodium_crypto_sign_publickey_from_secretkey(collectorCentralSecretKey()),
        ))
        ->and($response->json('client_certificate_fingerprint'))->toBe(str_repeat('a', 64))
        ->and($response->json('client_certificate'))->toContain('BEGIN CERTIFICATE')
        ->and($response->json('client_private_key'))->toContain('BEGIN PRIVATE KEY')
        ->and($collector->site_id)->toBe($site->id)
        ->and($collector->collector_device_id)->not->toBeNull()
        ->and($collector->collectorDevice?->assignments()->active()->where([
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
        ])->exists())->toBeTrue()
        ->and($collector->public_key_fingerprint)->toBe(hash('sha256', sodium_crypto_sign_publickey($pair)));
    expect($issue->enrollment->token_hash)->toBe(hash('sha256', $issue->plainToken))
        ->and($issue->enrollment->token_hash)->not->toBe($issue->plainToken);

    $this->withToken($issue->plainToken)->postJson('/api/monitoring/collectors/enrol', [
        'collector_id' => $collectorUuid,
        'collector_public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
    ])->assertUnprocessable();

    $expired = app(CollectorEnrollmentService::class)->issue(
        $site->id,
        $actor->id,
        CarbonImmutable::now('UTC')->addMinute(),
    );
    CarbonImmutable::setTestNow('2026-07-23T12:02:00Z');
    $this->withToken($expired->plainToken)->postJson('/api/monitoring/collectors/enrol', [
        'collector_id' => '018f0000-0000-7000-8000-000000000010',
        'collector_public_key' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
    ])->assertUnprocessable();
});

it('returns only exact assigned Site Device and protocol scope in a signed configuration', function () {
    $record = enrolledCollectorRecord();
    $profile = MonitoringProfile::factory()->create(['interval_seconds' => 60]);
    $approvedDevice = collectorLifecycleDevice($record['site']);
    $otherSite = collectorLifecycleSite();
    $otherDevice = collectorLifecycleDevice($otherSite);
    $approved = Monitor::factory()->create([
        'device_id' => $approvedDevice->id,
        'profile_id' => $profile->id,
        'collector_id' => $record['collector']->id,
        'kind' => MonitorKind::Icmp,
        'target' => '10.44.0.10',
        'config' => [],
    ]);
    Monitor::factory()->create([
        'device_id' => $otherDevice->id,
        'profile_id' => $profile->id,
        'collector_id' => $record['collector']->id,
        'kind' => MonitorKind::Icmp,
        'target' => '10.55.0.10',
        'config' => [],
    ]);
    $unassignedDevice = Device::factory()->itInfrastructure()->create();
    Monitor::factory()->create([
        'device_id' => $unassignedDevice->id,
        'profile_id' => $profile->id,
        'collector_id' => $record['collector']->id,
        'kind' => MonitorKind::Icmp,
        'target' => '10.44.0.11',
        'config' => [],
    ]);
    Monitor::factory()->create([
        'device_id' => $approvedDevice->id,
        'profile_id' => $profile->id,
        'collector_id' => $record['collector']->id,
        'kind' => MonitorKind::Snmp,
        'target' => '10.44.0.10',
        'config' => ['credential_reference' => 'vault:site/snmp'],
    ]);

    $response = collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/configuration',
        ['collector_id' => $record['collector']->collector_uuid, 'after_sequence' => 0],
        $record,
        'configuration-nonce-0001',
    )->assertOk();
    $envelope = json_decode($response->json('envelope'), true, flags: JSON_THROW_ON_ERROR);
    $payloadJson = base64_decode($envelope['payload'], true);
    $payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
    $signature = base64_decode($envelope['signature'], true);

    expect(sodium_crypto_sign_verify_detached(
        $signature,
        $payloadJson,
        sodium_crypto_sign_publickey_from_secretkey(collectorCentralSecretKey()),
    ))->toBeTrue()
        ->and($payload['site_id'])->toBe($record['site']->id)
        ->and($payload['collector_id'])->toBe($record['collector']->collector_uuid)
        ->and($payload['scope']['devices'])->toHaveKey((string) $approvedDevice->id)
        ->and($payload['scope']['devices'])->not->toHaveKey((string) $otherDevice->id)
        ->and($payload['scope']['devices'])->not->toHaveKey((string) $unassignedDevice->id)
        ->and($payload['scope']['cidrs'])->toBe(['10.44.0.10/32'])
        ->and($payload['scope']['protocols'])->toBe(['icmp'])
        ->and($payload['scope']['rate_limits'])->toBe([
            'max_checks_per_run' => 1,
            'packets_per_second' => 50,
        ])
        ->and($payload['checks'])->toHaveCount(1)
        ->and($payload['checks'][0]['id'])->toBe((string) $approved->id)
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain(
            'database', 'DB_PASSWORD', 'command', 'shell', '10.55.0.10',
        );

    $second = collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/configuration',
        ['collector_id' => $record['collector']->collector_uuid, 'after_sequence' => $payload['sequence']],
        $record,
        'configuration-nonce-0002',
    )->assertOk();
    $secondEnvelope = json_decode($second->json('envelope'), true, flags: JSON_THROW_ON_ERROR);
    $secondPayload = json_decode(base64_decode($secondEnvelope['payload'], true), true, flags: JSON_THROW_ON_ERROR);
    expect($secondPayload['sequence'])->toBe($payload['sequence'] + 1);
});

it('delivers credentialed checks only as ciphertext sealed to the enrolled collector identity', function () {
    $record = enrolledCollectorRecord();
    $profile = MonitoringProfile::factory()->create(['interval_seconds' => 60]);
    $device = collectorLifecycleDevice($record['site']);
    $referenceKey = 'vault:monitoring/site-'.$record['site']->id.'/snmp';
    $externalReference = 'secret/data/sites/'.$record['site']->id.'/snmp';
    $rules = app(CredentialReferenceRules::class);
    CredentialReference::query()->create([
        'reference_key' => $referenceKey,
        'site_id' => $record['site']->id,
        'provider' => 'native_monitoring',
        'purpose' => 'collector_monitoring',
        'capabilities' => ['monitoring.collector.snmp.read'],
        'secret_manager_reference' => $externalReference,
        'secret_manager_reference_hash' => $rules->fingerprint($externalReference),
        'status' => CredentialReferenceStatus::Active,
        'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed,
        'version' => 1,
    ]);
    app()->instance(SecretManagerLeaseIssuer::class, new CollectorEnvelopeFixtureSecretIssuer);
    $monitor = Monitor::factory()->create([
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'collector_id' => $record['collector']->id,
        'kind' => MonitorKind::Snmp,
        'target' => '10.44.0.50',
        'config' => ['credential_reference' => $referenceKey],
    ]);

    $response = collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/configuration',
        ['collector_id' => $record['collector']->collector_uuid, 'after_sequence' => 0],
        $record,
        'configuration-sealed-credential-1',
    )->assertOk();
    $envelope = json_decode($response->json('envelope'), true, flags: JSON_THROW_ON_ERROR);
    $payloadJson = base64_decode($envelope['payload'], true);
    $payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
    $check = collect($payload['checks'])->firstWhere('id', (string) $monitor->id);
    $serialised = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    expect($check)->not->toBeNull()
        ->and($check['credential_lease'])->toHaveKey('sealed_material')
        ->and($check['credential_lease'])->not->toHaveKey('material')
        ->and($serialised)->not->toContain(
            'collector-envelope-user',
            'collector-envelope-secret-sentinel',
            'collector-envelope-privacy-sentinel',
            'collector-envelope-lease-sentinel',
        );

    $signingSecret = $record['request_secret_key'];
    $signingPublic = sodium_crypto_sign_publickey_from_secretkey($signingSecret);
    $boxPublic = sodium_crypto_sign_ed25519_pk_to_curve25519($signingPublic);
    $boxSecret = sodium_crypto_sign_ed25519_sk_to_curve25519($signingSecret);
    $boxPair = sodium_crypto_box_keypair_from_secretkey_and_publickey($boxSecret, $boxPublic);
    $ciphertext = base64_decode($check['credential_lease']['sealed_material'], true);
    $plaintext = sodium_crypto_box_seal_open($ciphertext, $boxPair);
    $inner = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
    expect($inner['collector_id'])->toBe($record['collector']->collector_uuid)
        ->and($inner['site_id'])->toBe($record['site']->id)
        ->and($inner['device_id'])->toBe((string) $device->id)
        ->and($inner['material']['auth_passphrase'])->toBe('collector-envelope-secret-sentinel');

    $wrongPair = sodium_crypto_box_keypair();
    expect(sodium_crypto_box_seal_open($ciphertext, $wrongPair))->toBeFalse()
        ->and(CredentialLeaseGrant::query()->sole()->lease_id)->not->toBeNull()
        ->and(DB::table('security_device_credential_lease_grants')->value('lease_id'))
        ->not->toContain('collector-envelope-lease-sentinel');
    sodium_memzero($boxSecret);
    sodium_memzero($plaintext);
});

it('requires trusted proxy mTLS identity request signature and a fresh unique nonce', function () {
    $record = enrolledCollectorRecord();
    $heartbeat = collectorHeartbeatPayload();
    $heartbeat['spool_items'] = 3;
    $heartbeat['spool_bytes'] = 4096;
    $heartbeat['oldest_spool_item_at'] = CarbonImmutable::now('UTC')->subMinute()->format(DATE_ATOM);
    $heartbeat['highest_seen_source_sequence'] = 3;
    $payload = [
        'collector_id' => $record['collector']->collector_uuid,
        'status' => $heartbeat,
    ];

    collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/heartbeat',
        $payload,
        $record,
        'replay-resistant-nonce-1',
    )->assertOk();
    expect($record['collector']->fresh()->backlog_items)->toBe(3)
        ->and($record['collector']->fresh()->spool_bytes)->toBe(4096)
        ->and($record['collector']->fresh()->runtime_state)->toBe('writable')
        ->and($record['collector']->fresh()->runtime_status)->toBe(['checks_executed' => 1])
        ->and($record['collector']->fresh()->backlog_oldest_at?->equalTo(CarbonImmutable::now('UTC')->subMinute()))->toBeTrue()
        ->and($record['collector']->fresh()->last_clock_drift_seconds)->toBe(0);

    collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/heartbeat',
        $payload,
        $record,
        'replay-resistant-nonce-1',
    )->assertUnauthorized()->assertExactJson(['message' => 'Collector authentication failed.']);

    collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/heartbeat',
        $payload,
        $record,
        'untrusted-proxy-nonce-1',
        remoteAddress: '203.0.113.10',
    )->assertUnauthorized()->assertExactJson(['message' => 'Collector authentication failed.']);

    collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/heartbeat',
        $payload,
        $record,
        'wrong-certificate-nonce-1',
        certificateFingerprint: str_repeat('b', 64),
    )->assertUnauthorized()->assertExactJson(['message' => 'Collector authentication failed.']);
});

it('requires shared Redis replay protection outside the exact local test override', function () {
    $record = enrolledCollectorRecord();
    $payload = [
        'collector_id' => $record['collector']->collector_uuid,
        'status' => collectorHeartbeatPayload(),
    ];

    config()->set('monitoring.collector.replay_store', 'file');
    config()->set('monitoring.collector.allow_local_replay_store_for_tests', true);
    collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/heartbeat',
        $payload,
        $record,
        'non-shared-file-store-1',
    )->assertUnauthorized()->assertExactJson(['message' => 'Collector authentication failed.']);

    config()->set('monitoring.collector.replay_store', 'array');
    config()->set('monitoring.collector.allow_local_replay_store_for_tests', false);
    collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/heartbeat',
        $payload,
        $record,
        'disabled-local-array-store-1',
    )->assertUnauthorized()->assertExactJson(['message' => 'Collector authentication failed.']);

    config()->set('monitoring.collector.allow_local_replay_store_for_tests', true);
    collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/heartbeat',
        $payload,
        $record,
        'explicit-local-array-store-1',
    )->assertOk();
});

it('derives the collector fingerprint from the verified proxy certificate without proxy scripting', function () {
    $record = enrolledCollectorRecord();
    $certificatePem = collectorLifecycleCertificatePem();
    $certificate = openssl_x509_read($certificatePem);
    $fingerprint = $certificate === false ? false : openssl_x509_fingerprint($certificate, 'sha256');
    expect($fingerprint)->toBeString();

    $record['collector']->forceFill(['client_certificate_fingerprint' => $fingerprint])->save();
    config()->set('monitoring.collector.allow_proxy_fingerprint_header', false);

    collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/heartbeat',
        ['collector_id' => $record['collector']->collector_uuid, 'status' => collectorHeartbeatPayload()],
        $record,
        'verified-pem-certificate-1',
        certificatePem: $certificatePem,
    )->assertOk();

    collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/heartbeat',
        ['collector_id' => $record['collector']->collector_uuid, 'status' => collectorHeartbeatPayload()],
        $record,
        'invalid-pem-certificate-1',
        certificateFingerprint: $fingerprint,
        certificatePem: '-----BEGIN CERTIFICATE-----invalid-----END CERTIFICATE-----',
    )->assertUnauthorized()->assertExactJson(['message' => 'Collector authentication failed.']);
});

it('ingests contiguous collector observations once and parks an out of order gap visibly', function () {
    $record = enrolledCollectorRecord();
    $device = collectorLifecycleDevice($record['site']);
    $monitor = Monitor::factory()->create([
        'device_id' => $device->id,
        'collector_id' => $record['collector']->id,
        'kind' => MonitorKind::Icmp,
        'target' => '10.44.0.10',
        'config' => [],
    ]);
    $first = collectorObservationItem($monitor, $device, 1, 'collector-item-1');

    $accepted = collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/observations',
        ['collector_id' => $record['collector']->collector_uuid, 'items' => [$first]],
        $record,
        'observation-nonce-0001',
    )->assertOk();

    expect($accepted->json('acknowledged_ids'))->toBe(['collector-item-1'])
        ->and($accepted->json('acknowledged_source_sequence'))->toBe(1)
        ->and(MonitorObservation::query()->where('source_key', "collector:{$record['collector']->collector_uuid}:1")->count())->toBe(1);

    $duplicate = collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/observations',
        ['collector_id' => $record['collector']->collector_uuid, 'items' => [$first]],
        $record,
        'observation-nonce-0002',
    )->assertOk();
    expect($duplicate->json('acknowledged_ids'))->toBe(['collector-item-1'])
        ->and(MonitorObservation::query()->count())->toBe(1);

    $gap = collectorObservationItem($monitor, $device, 3, 'collector-item-3');
    $parked = collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/observations',
        ['collector_id' => $record['collector']->collector_uuid, 'items' => [$gap]],
        $record,
        'observation-nonce-0003',
    )->assertOk();

    expect($parked->json('acknowledged_ids'))->toBe([])
        ->and($parked->json('acknowledged_source_sequence'))->toBe(1)
        ->and($record['collector']->fresh()->gap_count)->toBe(1)
        ->and($record['collector']->checkpoint?->gap_from)->toBe(2)
        ->and($record['collector']->checkpoint?->gap_to)->toBe(2);

    $second = collectorObservationItem($monitor, $device, 2, 'collector-item-2');
    collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/observations',
        ['collector_id' => $record['collector']->collector_uuid, 'items' => [$second]],
        $record,
        'observation-nonce-0004',
    )->assertOk()->assertJson([
        'acknowledged_ids' => ['collector-item-2'],
        'acknowledged_source_sequence' => 2,
    ]);
    collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/observations',
        ['collector_id' => $record['collector']->collector_uuid, 'items' => [$gap]],
        $record,
        'observation-nonce-0005',
    )->assertOk()->assertJson([
        'acknowledged_ids' => ['collector-item-3'],
        'acknowledged_source_sequence' => 3,
    ]);
    expect($record['collector']->fresh()->gap_count)->toBe(0)
        ->and(MonitorObservation::query()->count())->toBe(3);

    $poison = collectorObservationItem($monitor, $device, 4, 'collector-item-4');
    $poison['payload']['target'] = '10.44.0.99';
    $poison['payload']['metrics']['secret_token'] = 'must-not-enter-dlq-evidence';
    collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/observations',
        ['collector_id' => $record['collector']->collector_uuid, 'items' => [$poison]],
        $record,
        'observation-nonce-0006',
    )->assertOk()->assertJson([
        'acknowledged_ids' => ['collector-item-4'],
        'acknowledged_source_sequence' => 4,
    ]);
    expect(MonitoringDeadLetter::query()->where([
        'consumer' => 'collector-intake',
        'reason_code' => 'collector_scope_violation',
        'site_id' => $record['site']->id,
    ])->count())->toBe(1)
        ->and(MonitoringDeadLetter::query()->sole()->envelope_bytes)->not->toContain('must-not-enter-dlq-evidence')
        ->and(MonitorObservation::query()->count())->toBe(3);
});

it('delivers and ingests bounded remote discovery work through the authenticated ordered collector path', function () {
    Queue::fake();
    $record = enrolledCollectorRecord();
    $scope = DiscoveryScope::factory()->create([
        'site_id' => $record['site']->id,
        'collector_id' => $record['collector']->id,
        'cidrs' => ['10.44.0.40/31'],
        'seed_hosts' => [],
        'protocols' => ['icmp', 'tcp', 'tls'],
        'exclusions' => [],
        'port_bounds' => ['tcp' => [22], 'tls' => [443]],
        'max_targets_per_run' => 2,
        'packets_per_second' => 20,
        'status' => 'active',
    ]);
    $runner = app(DiscoveryRunner::class);
    $run = $runner->start($scope, 'manual:user:7');
    $runner->execute($run->id);

    $response = collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/configuration',
        ['collector_id' => $record['collector']->collector_uuid, 'after_sequence' => 0],
        $record,
        'remote-discovery-configuration-1',
    )->assertOk();
    $envelope = json_decode($response->json('envelope'), true, flags: JSON_THROW_ON_ERROR);
    $payload = json_decode(base64_decode($envelope['payload'], true), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['version'])->toBe(2)
        ->and($payload['checks'])->toBe([])
        ->and($payload['scope']['devices'])->toBe([])
        ->and($payload['scope']['cidrs'])->toBe(['10.44.0.40/31'])
        ->and($payload['discovery_runs'])->toHaveCount(1)
        ->and($payload['discovery_runs'][0]['id'])->toBe($run->run_uuid)
        ->and($payload['discovery_runs'][0]['targets'])->toBe([
            ['target' => '10.44.0.40', 'source' => 'cidr'],
            ['target' => '10.44.0.41', 'source' => 'cidr'],
        ])
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain(
            'database', 'credential_reference', 'password', 'secret', 'command', 'shell',
        );

    $poisonTarget = 'private-target-secret.example';
    $poison = collectorDiscoveryItem($run->run_uuid, $poisonTarget, 1, 'collector-discovery-poison-1');
    collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/observations',
        ['collector_id' => $record['collector']->collector_uuid, 'items' => [$poison]],
        $record,
        'remote-discovery-upload-poison-1',
    )->assertOk()->assertJson([
        'acknowledged_ids' => ['collector-discovery-poison-1'],
        'acknowledged_source_sequence' => 1,
    ]);
    $letter = MonitoringDeadLetter::query()->where('consumer', 'collector-intake')->sole();
    expect($letter->reason_code)->toBe('collector_scope_violation')
        ->and($letter->envelope_bytes)->not->toContain($poisonTarget);

    $firstObservedAt = CarbonImmutable::now('UTC')->subMinutes(7)->startOfSecond();
    $items = [
        collectorDiscoveryItem($run->run_uuid, '10.44.0.40', 2, 'collector-discovery-40'),
        collectorDiscoveryItem($run->run_uuid, '10.44.0.41', 3, 'collector-discovery-41'),
    ];
    $items[0]['payload']['observed_at'] = $firstObservedAt->format(DATE_ATOM);
    $accepted = collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/observations',
        ['collector_id' => $record['collector']->collector_uuid, 'items' => $items],
        $record,
        'remote-discovery-upload-valid-1',
    )->assertOk();

    expect($accepted->json('acknowledged_ids'))->toBe(['collector-discovery-40', 'collector-discovery-41'])
        ->and($accepted->json('acknowledged_source_sequence'))->toBe(3)
        ->and(DiscoveryResult::query()->where('discovery_run_id', $run->id)->where('outcome', 'found')->count())->toBe(2)
        ->and(DiscoveryCandidate::query()->where('discovery_run_id', $run->id)->count())->toBe(2)
        ->and(DiscoveryResult::query()
            ->where('discovery_run_id', $run->id)
            ->orderBy('id')
            ->firstOrFail()
            ->observed_at
            ->toAtomString())->toBe($firstObservedAt->toAtomString());
    Queue::assertPushed(CompleteDiscoveryRun::class, 1);

    $completed = $runner->complete($run->id);
    expect($completed->status)->toBe('completed')
        ->and($completed->found_count)->toBe(2)
        ->and($completed->proposed_count)->toBe(2)
        ->and($runner->collectorWork($record['collector']->fresh(), 512))->toBe([]);
});

it('denies every authenticated endpoint after revocation and permits governed re-enrolment', function () {
    $record = enrolledCollectorRecord();
    app(CollectorEnrollmentService::class)->revoke($record['collector'], $record['actor']->id);

    collectorLifecycleSignedPost(
        $this,
        '/api/monitoring/collectors/heartbeat',
        ['collector_id' => $record['collector']->collector_uuid, 'status' => collectorHeartbeatPayload()],
        $record,
        'revoked-heartbeat-nonce',
    )->assertUnauthorized()->assertExactJson(['message' => 'Collector authentication failed.']);

    $replacement = app(CollectorEnrollmentService::class)->issue(
        $record['site']->id,
        $record['actor']->id,
        replacesCollectorId: $record['collector']->id,
    );
    $newPair = sodium_crypto_sign_keypair();
    $this->withToken($replacement->plainToken)->postJson('/api/monitoring/collectors/enrol', [
        'collector_id' => '018f0000-0000-7000-8000-000000000099',
        'collector_public_key' => base64_encode(sodium_crypto_sign_publickey($newPair)),
    ])->assertUnprocessable();

    $this->withToken($replacement->plainToken)->postJson('/api/monitoring/collectors/enrol', [
        'collector_id' => $record['collector']->collector_uuid,
        'collector_public_key' => base64_encode(sodium_crypto_sign_publickey($newPair)),
    ])->assertCreated();

    expect($record['collector']->fresh()->revoked_at)->toBeNull()
        ->and($record['collector']->fresh()->status)->toBe('online')
        ->and($record['collector']->fresh()->public_key_fingerprint)
        ->toBe(hash('sha256', sodium_crypto_sign_publickey($newPair)));
});

/** @return array{site: Site, actor: User, collector: MonitoringCollector, request_secret_key: string} */
function enrolledCollectorRecord(): array
{
    $site = collectorLifecycleSite();
    $actor = User::factory()->create();
    $issue = app(CollectorEnrollmentService::class)->issue($site->id, $actor->id);
    $pair = collectorRequestKeyPair();
    $uuid = fake()->uuid();
    test()->withToken($issue->plainToken)->postJson('/api/monitoring/collectors/enrol', [
        'collector_id' => $uuid,
        'collector_public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
    ])->assertCreated();

    return [
        'site' => $site,
        'actor' => $actor,
        'collector' => MonitoringCollector::query()->where('collector_uuid', $uuid)->sole(),
        'request_secret_key' => sodium_crypto_sign_secretkey($pair),
    ];
}

function collectorLifecycleSite(): Site
{
    return Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
}

function collectorLifecycleDevice(Site $site): Device
{
    $device = Device::factory()->itInfrastructure()->create();
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => AssignmentType::Permanent,
        'assigned_at' => now()->subMinute(),
    ]);

    return $device;
}

function collectorLifecycleSignedPost(
    TestCase $test,
    string $path,
    array $payload,
    array $record,
    string $nonce,
    string $remoteAddress = '10.20.30.40',
    ?string $certificateFingerprint = null,
    ?string $certificatePem = null,
): TestResponse {
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $timestamp = CarbonImmutable::now('UTC')->format(DATE_ATOM);
    $signature = sodium_crypto_sign_detached(
        "POST\n{$path}\n{$timestamp}\n{$nonce}\n".hash('sha256', $body),
        $record['request_secret_key'],
    );

    return $test->call('POST', $path, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_OBLIVION_COLLECTOR_TIMESTAMP' => $timestamp,
        'HTTP_X_OBLIVION_COLLECTOR_NONCE' => $nonce,
        'HTTP_X_OBLIVION_COLLECTOR_SIGNATURE' => base64_encode($signature),
        'HTTP_X_OBLIVION_CLIENT_CERTIFICATE_FINGERPRINT' => $certificateFingerprint ?? str_repeat('a', 64),
        'HTTP_X_OBLIVION_VERIFIED_CLIENT_CERTIFICATE' => $certificatePem === null ? '' : rawurlencode($certificatePem),
        'REMOTE_ADDR' => $remoteAddress,
    ], $body);
}

function collectorLifecycleCertificatePem(): string
{
    return <<<'PEM'
-----BEGIN CERTIFICATE-----
MIICszCCAZugAwIBAgIJAL8i2684yECtMA0GCSqGSIb3DQEBCwUAMBkxFzAVBgNVBAMTDmNvbGxl
Y3Rvci50ZXN0MB4XDTI2MDgwMzIxNDM0N1oXDTI3MDgwNDIxNDM0N1owGTEXMBUGA1UEAxMOY29s
bGVjdG9yLnRlc3QwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQC4P9n46h+Pm8CD2zbw
+xZ82kxQ4qCjYv+1OhOIkO+sgppxnXw9RvQAQW7htP8p3LgcDcSMxiGfwGF6LNZ20LjWAm/GPdAg
53mcKKPGw7s5baMz3rYLcn1NNN4tuHcKTGVwMEO/a5nv5H4g6MoYnPmoCWLoeFbvTSuNPKuTFZAB
0HNhyyGJIObwMzKFs2qoUnYOYj48BUz9FDoSoVRw8kGVVwWPo/pupMbed4iAJkS5mTc3opp8d+MN
/2V88fHkHBJPCwgkWYq4fLBBi8lXHJAlAUjJx0BbT7+Uyw+G1ARD3RkeY0HFWqwkLM7P8M2TQvR2
rVyVuH33el+8IqNUtHDdAgMBAAEwDQYJKoZIhvcNAQELBQADggEBAB/nMY5Dn39zion44L0KmJqz
rWO97Np1Jk67sXM275m7OW9jKyemL9rDPSOlvut1cEAlOyCVPWLuSK4Fc+a+bkWHr4oIW7gSGImY
BIokAkRXxJ19UzQYR53oYycdqBqmGv5QvIUCe2z0z1L8APd/6jP3HQ6kE7IrsNCaoNxvaeN8Z8zq
TLEkCR0JF+SAyTw+vqtf+ghDUQMc/FUCnWIBfioExMHoH9eBaTCIBV0JptOQ9DzJQxtkMB10kbxd
kuyOWJHCq2eL+Mm/qF974WVccuRQd3z6TiV1Tt87sWyAZcKttHGZt+DWopzqcn5f1+hUFq/M7a4b
yQIzjb5ytY4xkZg=
-----END CERTIFICATE-----
PEM;
}

function collectorRequestKeyPair(): string
{
    return sodium_crypto_sign_seed_keypair(str_repeat("\x71", SODIUM_CRYPTO_SIGN_SEEDBYTES));
}

function collectorCentralSecretKey(): string
{
    $pair = sodium_crypto_sign_seed_keypair(str_repeat("\x72", SODIUM_CRYPTO_SIGN_SEEDBYTES));

    return sodium_crypto_sign_secretkey($pair);
}

function collectorObservationItem(Monitor $monitor, Device $device, int $sequence, string $id): array
{
    return [
        'id' => $id,
        'source_sequence' => $sequence,
        'created_at' => CarbonImmutable::now('UTC')->format(DATE_ATOM),
        'payload' => [
            'check_id' => (string) $monitor->id,
            'device_id' => (string) $device->id,
            'protocol' => 'icmp',
            'target' => (string) $monitor->target,
            'observed_at' => CarbonImmutable::now('UTC')->format(DATE_ATOM),
            'duration_ms' => 12,
            'state' => 'healthy',
            'reason_code' => 'icmp_reply',
            'metrics' => ['exit_code' => 0],
        ],
    ];
}

function collectorDiscoveryItem(string $runUuid, string $target, int $sequence, string $id): array
{
    return [
        'id' => $id,
        'source_sequence' => $sequence,
        'created_at' => CarbonImmutable::now('UTC')->format(DATE_ATOM),
        'payload' => [
            'item_type' => 'discovery_result',
            'run_id' => $runUuid,
            'target' => $target,
            'observed_at' => CarbonImmutable::now('UTC')->format(DATE_ATOM),
            'outcome' => 'found',
            'identity' => [
                'mac_addresses' => [],
                'certificate_fingerprint' => null,
                'hostname' => null,
                'addresses' => [filter_var($target, FILTER_VALIDATE_IP) ? $target : '10.44.0.250'],
                'fingerprint' => 'network:icmp,tcp:443',
            ],
        ],
    ];
}

function collectorHeartbeatPayload(): array
{
    return [
        'reported_at' => CarbonImmutable::now('UTC')->format(DATE_ATOM),
        'state' => 'writable',
        'spool_items' => 0,
        'spool_bytes' => 0,
        'oldest_spool_item_at' => null,
        'corrupted_frames' => 0,
        'acknowledged_source_sequence' => 0,
        'highest_seen_source_sequence' => 0,
        'runtime' => ['checks_executed' => 1],
    ];
}
