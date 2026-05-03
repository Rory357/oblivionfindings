<?php

namespace Tests\Feature\Integrations;

use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\IntegrationTenantSecret;
use App\Models\Site;
use App\Services\Integration\AlertRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->createSecret(provider: 'unifi', key: 'correct-secret-1234');

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
        $this->createSecret(provider: 'unifi', key: $key);

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
        $this->createSecret(provider: 'unifi', key: $key, tenantId: 1);
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

    public function test_duplicate_source_event_id_is_idempotent_within_the_same_tenant(): void
    {
        $key = 'unifi-secret-1234';
        $site = Site::factory()->create(['tenant_id' => 1]);
        $this->createSecret(provider: 'unifi', key: $key, tenantId: 1);
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

    public function test_same_provider_source_event_id_is_not_duplicate_across_tenants(): void
    {
        $firstKey = 'first-tenant-key-1234';
        $secondKey = 'second-tenant-key-5678';
        $firstSite = Site::factory()->create(['tenant_id' => 1]);
        $secondSite = Site::factory()->create(['tenant_id' => 2]);
        $this->createSecret(provider: 'unifi', key: $firstKey, tenantId: 1);
        $this->createSecret(provider: 'unifi', key: $secondKey, tenantId: 2);
        $this->mockRouting(times: 2);

        $this->postJson('/webhooks/unifi', $this->payload(siteId: $firstSite->id, eventId: 'evt-shared'), [
            'X-Integration-Key' => $firstKey,
        ])->assertOk()->assertJson(['status' => 'accepted']);

        $this->postJson('/webhooks/unifi', $this->payload(siteId: $secondSite->id, eventId: 'evt-shared'), [
            'X-Integration-Key' => $secondKey,
        ])->assertOk()->assertJson(['status' => 'accepted']);

        $this->assertDatabaseHas('integration_events', [
            'tenant_id' => 1,
            'site_id' => $firstSite->id,
            'source_event_id' => 'evt-shared',
        ]);
        $this->assertDatabaseHas('integration_events', [
            'tenant_id' => 2,
            'site_id' => $secondSite->id,
            'source_event_id' => 'evt-shared',
        ]);
    }

    private function createSecret(string $provider, string $key, int $tenantId = 1): IntegrationTenantSecret
    {
        return IntegrationTenantSecret::create([
            'tenant_id' => $tenantId,
            'provider' => $provider,
            'secret_encrypted' => encrypt($key),
            'secret_last4' => substr($key, -4),
            'status' => IntegrationTenantSecret::STATUS_CONNECTED,
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
