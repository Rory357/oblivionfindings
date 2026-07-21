<?php

namespace Tests\Feature\Integrations;

use App\Http\Controllers\Api\WebhookReceiverController;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Services\Integration\AlertRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Tests\TestCase;

class WebhookReceiverTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_integration_key_is_rejected(): void
    {
        $this->postJson('/webhooks/unifi', $this->payload())
            ->assertUnauthorized()
            ->assertJson(['error' => 'Missing integration key']);

        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_invalid_integration_key_is_rejected(): void
    {
        $this->createProviderConnection(provider: 'unifi', key: 'correct-secret-1234');

        $this->postJson('/webhooks/unifi', $this->payload(), [
            'X-Integration-Key' => 'wrong-secret-9999',
        ])
            ->assertUnauthorized()
            ->assertJson(['error' => 'Invalid integration key']);

        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_invalid_webhook_signature_is_rejected(): void
    {
        $key = 'unifi-secret-1234';
        $this->createProviderConnection(provider: 'unifi', key: $key);

        $this->postJson('/webhooks/unifi', $this->payload(), [
            'X-Integration-Key' => $key,
            'X-Webhook-Signature' => hash_hmac('sha256', 'different body', $key),
        ])
            ->assertUnauthorized()
            ->assertJson(['error' => 'Invalid signature']);

        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_valid_hardware_webhook_is_persisted_and_routed(): void
    {
        $key = 'unifi-secret-1234';
        $site = Site::factory()->create(['tenant_id' => 1]);
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $site);
        $this->mockRouting(times: 1);

        $payload = $this->payload(siteId: $site->id, eventId: 'evt-1001');

        $this->postJson('/webhooks/unifi', $payload, [
            'X-Integration-Key' => $key,
            'X-Webhook-Signature' => hash_hmac('sha256', json_encode($payload), $key),
        ])
            ->assertOk()
            ->assertJson(['status' => 'accepted']);

        $this->assertDatabaseHas('integration_events', [
            'tenant_id' => 1,
            'site_id' => $site->id,
            'provider' => 'unifi',
            'source_event_id' => 'evt-1001',
            'event_type' => 'doorbell.ring',
        ]);
    }

    public function test_duplicate_source_event_id_is_idempotent_across_the_application(): void
    {
        $key = 'unifi-secret-1234';
        $site = Site::factory()->create(['tenant_id' => 1]);
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $site);
        $this->mockRouting(times: 1);

        $payload = $this->payload(siteId: $site->id, eventId: 'evt-1001');

        $this->postJson('/webhooks/unifi', $payload, [
            'X-Integration-Key' => $key,
        ])->assertOk()->assertJson(['status' => 'accepted']);

        $this->postJson('/webhooks/unifi', $payload, [
            'X-Integration-Key' => $key,
        ])->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertDatabaseCount('integration_events', 1);
    }

    public function test_same_provider_source_event_id_is_duplicate_across_sites(): void
    {
        $providerKey = 'application-provider-key-1234';
        $firstSite = Site::factory()->create(['tenant_id' => 1]);
        $secondSite = Site::factory()->create(['tenant_id' => 2]);
        $this->createProviderConnection(provider: 'unifi', key: $providerKey);
        $this->mapProviderToSite('unifi', $firstSite);
        $this->mapProviderToSite('unifi', $secondSite);
        $this->mockRouting(times: 1);

        $this->postJson('/webhooks/unifi', $this->payload(siteId: $firstSite->id, eventId: 'evt-shared'), [
            'X-Integration-Key' => $providerKey,
        ])->assertOk()->assertJson(['status' => 'accepted']);

        $this->postJson('/webhooks/unifi', $this->payload(siteId: $secondSite->id, eventId: 'evt-shared'), [
            'X-Integration-Key' => $providerKey,
        ])->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertDatabaseHas('integration_events', [
            'tenant_id' => 1,
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

        $this->postJson('/webhooks/unifi', $this->payload(siteId: $unmappedSite->id), [
            'X-Integration-Key' => $key,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_invalid_provider_timestamp_is_not_written_to_logs(): void
    {
        Log::spy();
        $controller = new class extends WebhookReceiverController
        {
            public function parseProviderTimestamp(mixed $value): ?Carbon
            {
                return $this->parseTimestamp($value);
            }
        };

        $this->assertNotNull($controller->parseProviderTimestamp('RAW-PROVIDER-TIMESTAMP-SECRET'));

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'unparseable timestamp')
                    && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'RAW-PROVIDER-TIMESTAMP-SECRET');
            })
            ->once();
    }

    private function createProviderConnection(string $provider, string $key): IntegrationProviderConnection
    {
        return IntegrationProviderConnection::create([
            'tenant_id' => 1,
            'provider' => $provider,
            'secret_encrypted' => encrypt($key),
            'secret_last4' => substr($key, -4),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);
    }

    private function mapProviderToSite(string $provider, Site $site): IntegrationSiteConfig
    {
        return IntegrationSiteConfig::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'provider' => $provider,
            'status' => IntegrationSiteConfig::STATUS_HYBRID,
            'is_active' => true,
        ]);
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

    /**
     * @return array<string, mixed>
     */
    private function payload(?int $siteId = null, string $eventId = 'evt-1001'): array
    {
        return [
            '_id' => $eventId,
            'site_id' => $siteId ?? Site::factory()->create()->id,
            'time' => now()->getTimestampMs(),
            'severity' => 'warning',
            'key' => 'doorbell.ring',
            'msg' => 'Front entry doorbell rang',
            'subsystem' => 'protect',
            'mac' => 'aa:bb:cc:dd:ee:ff',
        ];
    }
}
