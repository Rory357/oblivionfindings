<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Monitoring\Jobs\PullProviderCapability;
use App\Domain\Monitoring\Jobs\ScheduleProviderCapabilities;
use App\Jobs\Integration\PullIntegrationHealthJob;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Services\Integration\Contracts\EventCollectionCapability;
use App\Services\Integration\Contracts\ObservationCollectionCapability;
use App\Services\Integration\Contracts\SnapshotCollectionCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The compatibility job may schedule only declared collection capabilities.
 * It must never call the permissive health facade or write provider results
 * directly into canonical device state.
 */
class NonUnifiHealthPullMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_unadvertised_provider_health_is_not_executed_or_recorded_as_success(): void
    {
        $site = $this->mappedSite('hikvision');
        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('hasCapability')
            ->times(3)
            ->withArgs(fn (string $provider, string $capability): bool => $provider === 'hikvision'
                && in_array($capability, [
                    ObservationCollectionCapability::class,
                    EventCollectionCapability::class,
                    SnapshotCollectionCapability::class,
                ], true))
            ->andReturnFalse();
        $registry->shouldNotReceive('resolve');
        $registry->shouldNotReceive('capability');

        (new PullIntegrationHealthJob('hikvision', $site->id))
            ->handle($registry);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('integration_sync_logs', 0);
        $this->assertDatabaseCount('monitoring_provider_cursors', 0);
        $this->assertDatabaseCount('monitoring_outbox', 0);
    }

    public function test_declared_provider_health_is_delegated_to_the_provider_queue(): void
    {
        $site = $this->mappedSite('fixture-observer');
        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('hasCapability')
            ->once()
            ->with('fixture-observer', ObservationCollectionCapability::class)
            ->andReturnTrue();
        $registry->shouldReceive('hasCapability')
            ->once()
            ->with('fixture-observer', EventCollectionCapability::class)
            ->andReturnFalse();
        $registry->shouldReceive('hasCapability')
            ->once()
            ->with('fixture-observer', SnapshotCollectionCapability::class)
            ->andReturnFalse();

        $job = new PullIntegrationHealthJob('fixture-observer', $site->id);
        $job->handle($registry);

        $this->assertSame('monitoring-provider', $job->queue);
        $this->assertSame('redis', $job->connection);
        Queue::assertPushed(PullProviderCapability::class, function (PullProviderCapability $queued) use ($site): bool {
            return $queued->provider === 'fixture-observer'
                && $queued->siteId === $site->id
                && $queued->capability === ObservationCollectionCapability::class
                && $queued->connection === 'redis'
                && $queued->queue === 'monitoring-provider';
        });
        $this->assertDatabaseCount('integration_sync_logs', 0);
    }

    public function test_declared_provider_events_are_delegated_to_the_provider_queue(): void
    {
        $site = $this->mappedSite('fixture-events');
        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('hasCapability')
            ->once()
            ->with('fixture-events', ObservationCollectionCapability::class)
            ->andReturnFalse();
        $registry->shouldReceive('hasCapability')
            ->once()
            ->with('fixture-events', EventCollectionCapability::class)
            ->andReturnTrue();
        $registry->shouldReceive('hasCapability')
            ->once()
            ->with('fixture-events', SnapshotCollectionCapability::class)
            ->andReturnFalse();

        (new PullIntegrationHealthJob('fixture-events', $site->id))->handle($registry);

        Queue::assertPushed(PullProviderCapability::class, function (PullProviderCapability $queued) use ($site): bool {
            return $queued->provider === 'fixture-events'
                && $queued->siteId === $site->id
                && $queued->capability === EventCollectionCapability::class
                && $queued->connection === 'redis'
                && $queued->queue === 'monitoring-provider';
        });
    }

    public function test_declared_provider_snapshots_are_delegated_to_the_provider_queue(): void
    {
        $site = $this->mappedSite('fixture-snapshots');
        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('hasCapability')
            ->once()
            ->with('fixture-snapshots', ObservationCollectionCapability::class)
            ->andReturnFalse();
        $registry->shouldReceive('hasCapability')
            ->once()
            ->with('fixture-snapshots', EventCollectionCapability::class)
            ->andReturnFalse();
        $registry->shouldReceive('hasCapability')
            ->once()
            ->with('fixture-snapshots', SnapshotCollectionCapability::class)
            ->andReturnTrue();

        (new PullIntegrationHealthJob('fixture-snapshots', $site->id))->handle($registry);

        Queue::assertPushed(PullProviderCapability::class, function (PullProviderCapability $queued) use ($site): bool {
            return $queued->provider === 'fixture-snapshots'
                && $queued->siteId === $site->id
                && $queued->capability === SnapshotCollectionCapability::class
                && $queued->connection === 'redis'
                && $queued->queue === 'monitoring-provider';
        });
    }

    public function test_requested_site_scope_does_not_schedule_other_mapped_sites(): void
    {
        $requested = $this->mappedSite('fixture-observer');
        $this->mappedSite('fixture-observer');
        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('hasCapability')
            ->times(3)
            ->andReturnUsing(fn (string $provider, string $capability): bool => $capability === ObservationCollectionCapability::class);

        (new PullIntegrationHealthJob('fixture-observer', $requested->id))
            ->handle($registry);

        Queue::assertPushed(PullProviderCapability::class, 1);
        Queue::assertPushed(PullProviderCapability::class, fn (PullProviderCapability $queued): bool => $queued->siteId === $requested->id);
    }

    public function test_missing_site_scope_does_not_probe_the_registry_or_schedule_work(): void
    {
        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldNotReceive('hasCapability');

        (new PullIntegrationHealthJob('fixture-observer', 999999))
            ->handle($registry);

        Queue::assertNothingPushed();
    }

    public function test_provider_scheduler_dispatches_only_adapters_with_typed_collection_contracts(): void
    {
        (new ScheduleProviderCapabilities)->handle(app(IntegrationAdapterRegistry::class));

        Queue::assertPushed(PullIntegrationHealthJob::class, 2);
        Queue::assertPushed(PullIntegrationHealthJob::class, function (PullIntegrationHealthJob $queued): bool {
            return $queued->provider === 'unifi'
                && $queued->siteId === null
                && $queued->connection === 'redis'
                && $queued->queue === 'monitoring-provider';
        });
        Queue::assertPushed(PullIntegrationHealthJob::class, function (PullIntegrationHealthJob $queued): bool {
            return $queued->provider === 'milesight'
                && $queued->siteId === null
                && $queued->connection === 'redis'
                && $queued->queue === 'monitoring-provider';
        });
    }

    private function mappedSite(string $provider): Site
    {
        $site = Site::factory()->create();
        IntegrationSiteConfig::query()->create([
            'site_id' => $site->id,
            'provider' => $provider,
            'status' => IntegrationSiteConfig::STATUS_HYBRID,
            'mapped_external_site_id' => 'mapped-'.$site->id,
            'is_active' => true,
        ]);

        return $site;
    }
}
