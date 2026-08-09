<?php

namespace Tests\Support\Monitoring;

use App\Domain\Monitoring\Contracts\CollectorCertificateIssuer;
use App\Domain\Monitoring\Data\CollectorCertificateBundle;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Services\CollectorEnrollmentService;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class CollectorOutageFixture
{
    public static function configure(): void
    {
        CarbonImmutable::setTestNow('2026-07-23T12:00:00Z');
        config()->set('monitoring.collector.trusted_proxy_cidrs', ['10.20.30.0/24']);
        config()->set('monitoring.collector.replay_store', 'array');
        config()->set('monitoring.collector.allow_local_replay_store_for_tests', true);
        config()->set('monitoring.collector.request_clock_skew_seconds', 300);
        config()->set('monitoring.collector.configuration_lifetime_seconds', 600);
        config()->set('monitoring.collector.signing_secret_key', base64_encode(self::centralSecretKey()));

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

    /** @return array{site: Site, actor: User, collector: MonitoringCollector} */
    public static function enrolled(TestCase $test): array
    {
        $site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $actor = User::factory()->create();
        $issue = app(CollectorEnrollmentService::class)->issue($site->id, $actor->id);
        $pair = sodium_crypto_sign_seed_keypair(str_repeat("\x71", SODIUM_CRYPTO_SIGN_SEEDBYTES));
        $uuid = fake()->uuid();

        $test->withToken($issue->plainToken)->postJson('/api/monitoring/collectors/enrol', [
            'collector_id' => $uuid,
            'collector_public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ])->assertCreated();

        return [
            'site' => $site,
            'actor' => $actor,
            'collector' => MonitoringCollector::query()->where('collector_uuid', $uuid)->sole(),
        ];
    }

    public static function device(Site $site): Device
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

    /** @return array<string, mixed> */
    public static function heartbeat(
        int $acknowledgedSourceSequence = 0,
        int $highestSeenSourceSequence = 0,
    ): array {
        return [
            'reported_at' => CarbonImmutable::now('UTC')->format(DATE_ATOM),
            'state' => 'writable',
            'spool_items' => 0,
            'spool_bytes' => 0,
            'oldest_spool_item_at' => null,
            'corrupted_frames' => 0,
            'acknowledged_source_sequence' => $acknowledgedSourceSequence,
            'highest_seen_source_sequence' => $highestSeenSourceSequence,
            'runtime' => ['checks_executed' => 1],
        ];
    }

    public static function canonicalObservation(
        Monitor $monitor,
        MonitoringCollector $collector,
        int $sourceSequence,
        CarbonImmutable $at,
    ): MonitorObservation {
        return MonitorObservation::query()->create([
            'monitor_id' => $monitor->id,
            'source_key' => "collector:{$collector->collector_uuid}:{$sourceSequence}",
            'state' => $monitor->current_state,
            'observed_at' => $at,
            'ingested_at' => $at,
        ]);
    }

    /** @param list<int> $monitorIds */
    public static function rosterFingerprint(array $monitorIds): string
    {
        sort($monitorIds, SORT_NUMERIC);

        return hash('sha256', json_encode(array_values(array_unique($monitorIds)), JSON_THROW_ON_ERROR));
    }

    private static function centralSecretKey(): string
    {
        $pair = sodium_crypto_sign_seed_keypair(str_repeat("\x72", SODIUM_CRYPTO_SIGN_SEEDBYTES));

        return sodium_crypto_sign_secretkey($pair);
    }
}
