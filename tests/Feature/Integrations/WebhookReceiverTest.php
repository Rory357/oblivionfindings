<?php

namespace Tests\Feature\Integrations;

use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Services\MonitoringEnvelopeConsumer;
use App\Domain\Monitoring\Services\RuntimeEnvelopeCodec;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Http\Controllers\Api\WebhookReceiverController;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Services\Integration\AlertRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WebhookReceiverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set('cache.default', 'array');
        config()->set('integration-capabilities.webhook.replay_store', 'array');
        config()->set('integration-capabilities.webhook.allow_local_replay_store_for_tests', true);
        config()->set('monitoring.delivery.sequence_lock_store', 'array');
        config()->set('monitoring.delivery.allow_local_sequence_lock_for_tests', true);
        config()->set('monitoring.signing', [
            'active_key_id' => 'webhook-test-key',
            'keys' => [
                'webhook-test-key' => base64_encode(str_repeat("\x42", SODIUM_CRYPTO_AUTH_KEYBYTES)),
            ],
        ]);
    }

    public function test_missing_integration_key_is_rejected(): void
    {
        $this->postJson('/webhooks/unifi', $this->payload())
            ->assertUnauthorized()
            ->assertJson(['error' => 'Webhook rejected']);

        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_disabled_provider_rejects_webhook_intake_without_staging_evidence(): void
    {
        $key = 'disabled-unifi-secret-1234';
        $connection = $this->createProviderConnection(provider: 'unifi', key: $key);
        $connection->update([
            'status' => IntegrationProviderConnection::STATUS_DISABLED,
            'requires_credential_replacement' => true,
        ]);
        $payload = $this->payload();

        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key))
            ->assertUnauthorized()
            ->assertJson(['error' => 'Webhook rejected']);

        $this->assertDatabaseCount('monitoring_outbox', 0);
        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_invalid_integration_key_is_rejected(): void
    {
        $this->createProviderConnection(provider: 'unifi', key: 'correct-secret-1234');

        $payload = $this->payload();
        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, 'wrong-secret-9999'))
            ->assertUnauthorized()
            ->assertJson(['error' => 'Webhook rejected']);

        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_invalid_webhook_signature_is_rejected(): void
    {
        $key = 'unifi-secret-1234';
        $this->createProviderConnection(provider: 'unifi', key: $key);

        $payload = $this->payload();
        $headers = $this->signedHeaders($payload, $key);
        $headers['X-Webhook-Signature'] = 'sha256='.hash_hmac('sha256', 'different body', $key);

        $this->postJson('/webhooks/unifi', $payload, $headers)
            ->assertUnauthorized()
            ->assertJson(['error' => 'Webhook rejected']);

        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_valid_hardware_webhook_is_persisted_and_routed(): void
    {
        $key = 'unifi-secret-1234';
        $site = Site::factory()->create();
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $site);
        $this->mockRouting(times: 1);

        $payload = $this->payload(siteId: $site->id, eventId: 'evt-1001');

        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key))
            ->assertAccepted()
            ->assertJson(['status' => 'accepted']);

        $this->assertDatabaseCount('integration_events', 0);
        $this->consumeStagedEvent($site->id);

        $this->assertDatabaseHas('integration_events', [
            'site_id' => $site->id,
            'canonical_device_id' => Device::query()
                ->where('external_ref->provider_entity_id', 'unifi-device-'.$site->id)
                ->value('id'),
            'provider' => 'unifi',
            'source_event_id' => 'evt-1001',
            'event_type' => 'doorbell.ring',
            'raw_payload' => null,
        ]);
    }

    public function test_duplicate_source_event_id_is_idempotent_across_the_application(): void
    {
        $key = 'unifi-secret-1234';
        $site = Site::factory()->create();
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $site);
        $this->mockRouting(times: 1);

        $payload = $this->payload(siteId: $site->id, eventId: 'evt-1001');

        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key, 'nonce-for-first-event-1001'))
            ->assertAccepted()
            ->assertJson(['status' => 'accepted']);

        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key, 'nonce-for-second-event-1001'))
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        $this->consumeStagedEvent($site->id);
        $this->assertDatabaseCount('integration_events', 1);
    }

    public function test_same_provider_source_event_id_cannot_be_rebound_to_another_canonical_site(): void
    {
        $providerKey = 'application-provider-key-1234';
        $firstSite = Site::factory()->create();
        $secondSite = Site::factory()->create();
        $this->createProviderConnection(provider: 'unifi', key: $providerKey);
        $this->mapProviderToSite('unifi', $firstSite);
        $this->mapProviderToSite('unifi', $secondSite);
        $this->mockRouting(times: 1);

        $firstPayload = $this->payload(siteId: $firstSite->id, eventId: 'evt-shared');
        $this->postJson('/webhooks/unifi', $firstPayload, $this->signedHeaders($firstPayload, $providerKey, 'nonce-for-first-shared-event'))
            ->assertAccepted()
            ->assertJson(['status' => 'accepted']);
        $this->consumeStagedEvent($firstSite->id);

        $secondPayload = $this->payload(siteId: $secondSite->id, eventId: 'evt-shared');
        $this->postJson('/webhooks/unifi', $secondPayload, $this->signedHeaders($secondPayload, $providerKey, 'nonce-for-second-shared-event'))
            ->assertNotFound()
            ->assertJson(['error' => 'Webhook endpoint not found'])
            ->assertJsonMissing(['event_id' => IntegrationEvent::query()->sole()->id]);

        $this->assertDatabaseHas('integration_events', [
            'site_id' => $firstSite->id,
            'source_event_id' => 'evt-shared',
        ]);
        $this->assertDatabaseCount('integration_events', 1);
    }

    public function test_authenticated_webhook_cannot_forge_an_unmapped_site(): void
    {
        $key = 'unifi-secret-1234';
        $mappedSite = Site::factory()->create();
        $unmappedSite = Site::factory()->create();
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $mappedSite);
        $this->mockRouting(times: 0);

        $payload = $this->payload(siteId: $unmappedSite->id);
        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key))
            ->assertNotFound()
            ->assertJson(['error' => 'Webhook endpoint not found']);

        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_authenticated_webhook_cannot_reactivate_an_archived_site_mapping(): void
    {
        $key = 'unifi-secret-1234';
        $site = Site::factory()->create(['archived_at' => now()]);
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $site);
        $this->mockRouting(times: 0);

        $payload = $this->payload(siteId: $site->id);
        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key))
            ->assertNotFound()
            ->assertJson(['error' => 'Webhook endpoint not found']);

        $this->assertDatabaseCount('monitoring_outbox', 0);
        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_invalid_provider_timestamp_is_not_written_to_logs(): void
    {
        Log::spy();
        $controller = new WebhookReceiverController;
        $parseProviderTimestamp = new \ReflectionMethod($controller, 'parseTimestamp');

        $this->assertNotNull($parseProviderTimestamp->invoke(
            $controller,
            'RAW-PROVIDER-TIMESTAMP-SECRET',
        ));

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'unparseable timestamp')
                    && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'RAW-PROVIDER-TIMESTAMP-SECRET');
            })
            ->once();
    }

    public function test_expired_timestamp_and_replayed_nonce_are_rejected_before_persistence(): void
    {
        $key = 'unifi-secret-1234';
        $site = Site::factory()->create();
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $site);
        $payload = $this->payload(siteId: $site->id);

        $expired = $this->signedHeaders($payload, $key, 'nonce-for-expired-event', now()->subMinutes(10)->timestamp);
        $this->postJson('/webhooks/unifi', $payload, $expired)->assertUnauthorized();

        $headers = $this->signedHeaders($payload, $key, 'nonce-for-replayed-event');
        $this->postJson('/webhooks/unifi', $payload, $headers)->assertAccepted();
        $this->postJson('/webhooks/unifi', $payload, $headers)->assertUnauthorized();

        $this->assertDatabaseCount('monitoring_outbox', 1);
        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_freshly_signed_stale_provider_event_is_rejected_before_staging(): void
    {
        $key = 'unifi-stale-event-secret-1234';
        $site = Site::factory()->create();
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $site);
        $payload = $this->payload(siteId: $site->id, eventId: 'evt-stale-provider-time');
        $payload['time'] = now()->subDays(2)->getTimestampMs();

        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key))
            ->assertUnauthorized()
            ->assertJson(['error' => 'Webhook rejected']);

        $this->assertDatabaseCount('monitoring_outbox', 0);
        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_authenticated_webhook_cannot_forge_a_device_from_another_site(): void
    {
        $key = 'unifi-device-scope-secret-1234';
        $targetSite = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $targetSite);
        $this->mapProviderToSite('unifi', $foreignSite);
        $this->mockRouting(times: 0);

        $payload = $this->payload(
            siteId: $targetSite->id,
            eventId: 'evt-forged-device',
            deviceSiteId: $foreignSite->id,
        );
        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key))
            ->assertNotFound()
            ->assertJson(['error' => 'Webhook endpoint not found']);

        $this->assertDatabaseCount('monitoring_outbox', 0);
        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_no_id_whitespace_and_key_order_replay_uses_one_deterministic_provider_source_identity(): void
    {
        $key = 'unifi-fallback-identity-secret-1234';
        $site = Site::factory()->create();
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $site);
        $this->mockRouting(times: 1);
        $payload = $this->payload(siteId: $site->id);
        unset($payload['_id']);
        $firstBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $secondBody = json_encode([
            'device_id' => $payload['device_id'],
            'subsystem' => $payload['subsystem'],
            'msg' => $payload['msg'],
            'key' => $payload['key'],
            'severity' => $payload['severity'],
            'time' => $payload['time'],
            'site_id' => $payload['site_id'],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        $this->postRawJson(
            '/webhooks/unifi',
            $firstBody,
            $this->signedBodyHeaders($firstBody, $key, 'nonce-for-fallback-event-one'),
        )
            ->assertAccepted()
            ->assertJson(['status' => 'accepted', 'accepted' => 1]);
        $this->postRawJson(
            '/webhooks/unifi',
            $secondBody,
            $this->signedBodyHeaders($secondBody, $key, 'nonce-for-fallback-event-two'),
        )
            ->assertOk()
            ->assertJson(['status' => 'duplicate', 'accepted' => 0, 'duplicates' => 1]);

        $outbox = MonitoringOutbox::query()->sole();
        $this->assertSame('provider:unifi:webhooks', $outbox->source);
        $this->consumeStagedEvent($site->id);

        $event = IntegrationEvent::query()->sole();
        $this->assertStringStartsWith('oblivion-fallback-v1:', $event->source_event_id);
        $this->assertDatabaseCount('integration_events', 1);
    }

    public function test_source_identity_reuse_with_changed_authoritative_content_is_concealed_and_denied(): void
    {
        $key = 'unifi-conflicting-identity-secret-1234';
        $site = Site::factory()->create();
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $site);
        $this->mockRouting(times: 1);
        $payload = $this->payload(siteId: $site->id, eventId: 'evt-conflicting-content');

        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key, 'nonce-for-original-content'))
            ->assertAccepted();
        $this->consumeStagedEvent($site->id);

        $payload['msg'] = 'A different event body reusing the same provider identity';
        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key, 'nonce-for-conflicting-content'))
            ->assertNotFound()
            ->assertExactJson(['error' => 'Webhook endpoint not found']);

        $this->assertDatabaseCount('monitoring_outbox', 1);
        $this->assertDatabaseCount('integration_events', 1);
    }

    public function test_mapping_revoked_after_acceptance_is_quarantined_before_projection(): void
    {
        $key = 'unifi-revoked-binding-secret-1234';
        $site = Site::factory()->create();
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $siteConfig = $this->mapProviderToSite('unifi', $site);
        $this->mockRouting(times: 0);
        $payload = $this->payload(siteId: $site->id, eventId: 'evt-binding-revoked');

        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key))
            ->assertAccepted();
        $siteConfig->update(['is_active' => false]);

        $this->consumeStagedEvent($site->id);

        $this->assertDatabaseCount('integration_events', 0);
        $this->assertDatabaseHas('monitoring_dead_letters', [
            'consumer' => 'event-projector',
            'source' => 'provider:unifi:webhooks',
            'reason_code' => 'site_scope_violation',
            'site_id' => $site->id,
        ]);
    }

    public function test_signed_legacy_webhook_event_without_a_binding_is_quarantined_as_invalid_payload(): void
    {
        $key = 'unifi-legacy-unbound-secret-1234';
        $site = Site::factory()->create();
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $site);
        $this->mockRouting(times: 0);
        $idempotencyKey = 'event:'.hash('sha256', 'unifi:legacy-unbound-event');
        $envelope = RuntimeEnvelope::new(
            type: RuntimeMessageType::Event,
            source: "provider:unifi:site:{$site->id}:events",
            sequence: 1,
            idempotencyKey: $idempotencyKey,
            payload: [
                'event_family' => 'provider_event',
                'site_id' => $site->id,
                'provider' => 'unifi',
                'source_app' => 'unifi',
                'source_event_id' => 'legacy-unbound-event',
                'occurred_at' => now()->utc()->toIso8601String(),
                'severity' => 'warn',
                'event_type' => 'doorbell.ring',
                'normalized_payload' => ['summary' => 'Legacy unbound webhook event'],
                'body_hash' => hash('sha256', 'legacy-unbound-webhook-body'),
            ],
        );

        app(MonitoringEnvelopeConsumer::class)->consume(
            'event-projector',
            app(RuntimeEnvelopeCodec::class)->encode($envelope),
            $site->id,
        );

        $this->assertDatabaseCount('integration_events', 0);
        $this->assertDatabaseHas('monitoring_dead_letters', [
            'consumer' => 'event-projector',
            'source' => "provider:unifi:site:{$site->id}:events",
            'idempotency_key' => $idempotencyKey,
            'reason_code' => 'payload_invalid',
            'site_id' => $site->id,
        ]);
    }

    public function test_concurrent_no_id_replay_stages_and_projects_one_effect_on_mysql(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $key = 'unifi-concurrent-fallback-secret-1234';
        $site = Site::factory()->create();
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $site);
        $payload = $this->payload(siteId: $site->id);
        unset($payload['_id']);
        $database = $connection->getDatabaseName();
        $token = (string) Str::uuid();
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."webhook-release-{$token}";
        $readyPaths = [];
        $processes = [];

        $connection->commit();

        try {
            foreach (['first', 'second'] as $index => $suffix) {
                $readyPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."webhook-ready-{$index}-{$token}";
                $processes[] = $this->startConcurrentWebhookWorker(
                    database: $database,
                    payload: $payload,
                    headers: $this->signedHeaders($payload, $key, "nonce-for-concurrent-{$suffix}"),
                    readyPath: $readyPaths[$index],
                    releasePath: $releasePath,
                );
            }

            $this->waitForWebhookWorkerFiles($readyPaths, 'Concurrent webhook workers did not become ready.');
            touch($releasePath);

            $responses = [];
            foreach ($processes as $process) {
                $process->wait();
                if (! $process->isSuccessful()) {
                    throw new \RuntimeException(
                        trim($process->getErrorOutput()) ?: 'A concurrent webhook worker failed.',
                    );
                }
                $responses[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }

            $statuses = array_column($responses, 'status');
            sort($statuses);
            $this->assertSame([200, 202], $statuses);
            $this->assertEqualsCanonicalizing(
                ['accepted', 'duplicate'],
                array_map(static fn (array $response): string => $response['body']['status'], $responses),
            );
            $this->assertDatabaseCount('monitoring_outbox', 1);

            $this->mockRouting(times: 1);
            $this->consumeStagedEvent($site->id);
            $this->assertDatabaseCount('integration_events', 1);
            $this->assertDatabaseCount('monitoring_inbox', 1);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([...$readyPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            foreach ([
                'monitoring_dead_letters',
                'monitoring_inbox',
                'monitoring_consumer_checkpoints',
                'integration_events',
                'monitoring_outbox',
                'device_assignments',
                'devices',
                'integration_site_configs',
                'integration_tenant_secrets',
                'audit_logs',
                'cache_locks',
                'cache',
                'sites',
            ] as $table) {
                DB::table($table)->delete();
            }
            $connection->beginTransaction();
        }
    }

    public function test_provider_without_verified_webhook_capability_is_hidden(): void
    {
        $this->postJson('/webhooks/queclink', [])->assertNotFound();
        $this->postJson('/webhooks/not-registered', [])->assertNotFound();
        $this->assertDatabaseCount('monitoring_outbox', 0);
    }

    public function test_routing_failure_rolls_back_projection_and_monitoring_replay_completes_once(): void
    {
        $key = 'unifi-routing-recovery-1234';
        $site = Site::factory()->create();
        $this->createProviderConnection('unifi', $key);
        $this->mapProviderToSite('unifi', $site);
        $payload = $this->payload($site->id, 'evt-routing-recovery');
        $attempt = 0;

        $this->mock(AlertRoutingService::class, function (MockInterface $mock) use (&$attempt): void {
            $mock->shouldReceive('processEvent')
                ->twice()
                ->withArgs(fn (IntegrationEvent $event): bool => $event->exists)
                ->andReturnUsing(function () use (&$attempt) {
                    if ($attempt++ === 0) {
                        throw new \RuntimeException('injected alert routing failure');
                    }

                    return null;
                });
        });

        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key))
            ->assertAccepted();
        $outbox = MonitoringOutbox::query()->sole();

        try {
            app(MonitoringEnvelopeConsumer::class)->consume(
                'event-projector',
                $outbox->envelope_bytes,
                $site->id,
            );
            $this->fail('The injected routing failure should roll back the monitoring projection.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('injected alert routing failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('integration_events', 0);
        $this->assertDatabaseCount('monitoring_inbox', 0);

        app(MonitoringEnvelopeConsumer::class)->consume(
            'event-projector',
            $outbox->envelope_bytes,
            $site->id,
        );
        app(MonitoringEnvelopeConsumer::class)->consume(
            'event-projector',
            $outbox->envelope_bytes,
            $site->id,
        );

        $this->assertDatabaseCount('integration_events', 1);
        $this->assertDatabaseCount('monitoring_inbox', 1);
        $this->assertNotNull(\DB::table('monitoring_inbox')->value('processed_at'));
    }

    private function createProviderConnection(string $provider, string $key): IntegrationProviderConnection
    {
        return IntegrationProviderConnection::create([
            'provider' => $provider,
            'secret_encrypted' => Crypt::encryptString($key),
            'secret_last4' => substr($key, -4),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);
    }

    private function mapProviderToSite(string $provider, Site $site): IntegrationSiteConfig
    {
        $siteConfig = IntegrationSiteConfig::create([
            'site_id' => $site->id,
            'provider' => $provider,
            'mapped_external_site_id' => 'unifi-site-'.$site->id,
            'status' => IntegrationSiteConfig::STATUS_HYBRID,
            'is_active' => true,
        ]);
        $device = Device::factory()->forProvider($provider)->create([
            'external_ref' => [
                'provider' => $provider,
                'provider_entity_id' => 'unifi-device-'.$site->id,
            ],
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        return $siteConfig;
    }

    private function mockRouting(int $times): void
    {
        $this->mock(AlertRoutingService::class, function (MockInterface $mock) use ($times): void {
            $mock->shouldReceive('processEvent')
                ->times($times)
                ->withArgs(fn (IntegrationEvent $event): bool => $event->exists)
                ->andReturnNull();
        });
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function signedHeaders(
        array $payload,
        string $key,
        string $nonce = 'nonce-for-webhook-event-1001',
        ?int $timestamp = null,
    ): array {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->signedBodyHeaders($body, $key, $nonce, $timestamp);
    }

    /** @return array<string, string> */
    private function signedBodyHeaders(
        string $body,
        string $key,
        string $nonce,
        ?int $timestamp = null,
    ): array {
        $timestamp ??= now()->timestamp;

        return [
            'X-Integration-Key' => $key,
            'X-Webhook-Timestamp' => (string) $timestamp,
            'X-Webhook-Nonce' => $nonce,
            'X-Webhook-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $key),
        ];
    }

    /** @param array<string, string> $headers */
    private function postRawJson(string $uri, string $body, array $headers): TestResponse
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $uri, server: $server, content: $body);
    }

    private function consumeStagedEvent(int $siteId): void
    {
        $outbox = MonitoringOutbox::query()->latest('id')->firstOrFail();
        app(MonitoringEnvelopeConsumer::class)->consume(
            'event-projector',
            $outbox->envelope_bytes,
            $siteId,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function startConcurrentWebhookWorker(
        string $database,
        array $payload,
        array $headers,
        string $readyPath,
        string $releasePath,
    ): Process {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
$headers = json_decode(base64_decode($argv[3]), true, flags: JSON_THROW_ON_ERROR);
config()->set('cache.stores.webhook-concurrency', [
    'driver' => 'database',
    'connection' => null,
    'table' => 'cache',
    'lock_connection' => null,
    'lock_table' => 'cache_locks',
]);
config()->set('integration-capabilities.webhook.replay_store', 'webhook-concurrency');
config()->set('integration-capabilities.webhook.allow_local_replay_store_for_tests', true);
config()->set('monitoring.delivery.sequence_lock_store', 'webhook-concurrency');
config()->set('monitoring.delivery.allow_local_sequence_lock_for_tests', true);
config()->set('monitoring.signing', [
    'active_key_id' => 'webhook-test-key',
    'keys' => [
        'webhook-test-key' => base64_encode(str_repeat("\x42", SODIUM_CRYPTO_AUTH_KEYBYTES)),
    ],
]);
Illuminate\Support\Facades\Queue::fake();
file_put_contents($argv[4], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[5])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the webhook release barrier.');
    }
    usleep(10_000);
}
$server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
foreach ($headers as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}
$request = Illuminate\Http\Request::create(
    '/webhooks/unifi',
    'POST',
    [],
    [],
    [],
    $server,
    json_encode($payload, JSON_THROW_ON_ERROR),
);
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR),
], JSON_THROW_ON_ERROR);
PHP;

        $process = new Process([
            PHP_BINARY,
            '-r',
            $worker,
            base_path(),
            base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            base64_encode(json_encode($headers, JSON_THROW_ON_ERROR)),
            $readyPath,
            $releasePath,
        ], base_path(), [
            'APP_ENV' => 'testing',
            'DB_DATABASE' => $database,
        ]);
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    /** @param list<string> $paths */
    private function waitForWebhookWorkerFiles(array $paths, string $message): void
    {
        $deadline = microtime(true) + 15;
        while (collect($paths)->contains(fn (string $path): bool => ! is_file($path))) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException($message);
            }
            usleep(10_000);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        ?int $siteId = null,
        string $eventId = 'evt-1001',
        ?int $deviceSiteId = null,
    ): array {
        $siteId ??= Site::factory()->create()->id;

        return [
            '_id' => $eventId,
            'site_id' => 'unifi-site-'.$siteId,
            'time' => now()->getTimestampMs(),
            'severity' => 'warning',
            'key' => 'doorbell.ring',
            'msg' => 'Front entry doorbell rang',
            'subsystem' => 'protect',
            'device_id' => 'unifi-device-'.($deviceSiteId ?? $siteId),
        ];
    }
}
