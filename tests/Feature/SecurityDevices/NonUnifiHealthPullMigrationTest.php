<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Monitoring\Jobs\BuildTopologySnapshot;
use App\Domain\Monitoring\Jobs\PullProviderCapability;
use App\Domain\Monitoring\Jobs\ScheduleProviderCapabilities;
use App\Jobs\Integration\PullIntegrationHealthJob;
use App\Jobs\Integration\SyncIntegrationDevicesJob;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Site;
use App\Services\Integration\Contracts\DeviceSyncCapability;
use App\Services\Integration\Contracts\EventCollectionCapability;
use App\Services\Integration\Contracts\ObservationCollectionCapability;
use App\Services\Integration\Contracts\SnapshotCollectionCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Integration\SyncResult;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
                && $queued->queue === 'monitoring-provider'
                && $queued instanceof ShouldBeUnique
                && $queued->uniqueFor === 90_000
                && $queued->uniqueId() === hash('sha256', 'fixture-observer:'.$site->id.':'.ObservationCollectionCapability::class);
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
        $unifiSite = $this->mappedSite('unifi');
        $milesightSite = $this->mappedSite('milesight');
        foreach (['unifi', 'milesight'] as $provider) {
            IntegrationProviderConnection::query()->create([
                'provider' => $provider,
                'secret_encrypted' => encrypt('test-provider-secret'),
                'status' => IntegrationProviderConnection::STATUS_CONNECTED,
                'requires_credential_replacement' => false,
            ]);
        }
        $scheduler = new ScheduleProviderCapabilities;
        $scheduler->handle(app(IntegrationAdapterRegistry::class));

        $this->assertInstanceOf(ShouldBeUnique::class, $scheduler);
        $this->assertSame('provider-capability-orchestrator', $scheduler->uniqueId());
        $this->assertSame(600, $scheduler->uniqueFor);

        Queue::assertPushed(PullIntegrationHealthJob::class, 2);
        Queue::assertPushed(PullIntegrationHealthJob::class, function (PullIntegrationHealthJob $queued) use ($unifiSite): bool {
            return $queued->provider === 'unifi'
                && $queued->siteId === $unifiSite->id
                && $queued->connection === 'redis'
                && $queued->queue === 'monitoring-provider';
        });
        Queue::assertPushed(PullIntegrationHealthJob::class, function (PullIntegrationHealthJob $queued) use ($milesightSite): bool {
            return $queued->provider === 'milesight'
                && $queued->siteId === $milesightSite->id
                && $queued->connection === 'redis'
                && $queued->queue === 'monitoring-provider';
        });
        Queue::assertPushed(SyncIntegrationDevicesJob::class, 2);
        Queue::assertPushed(SyncIntegrationDevicesJob::class, fn (SyncIntegrationDevicesJob $queued): bool => $queued->provider === 'unifi'
            && $queued->connection === 'redis'
            && $queued->queue === 'monitoring-provider'
            && $queued->uniqueId() === 'unifi:'.$unifiSite->id
            && $queued->uniqueFor === 600);
        Queue::assertPushed(SyncIntegrationDevicesJob::class, fn (SyncIntegrationDevicesJob $queued): bool => $queued->provider === 'milesight'
            && $queued->siteId === $milesightSite->id
            && $queued->connection === 'redis'
            && $queued->queue === 'monitoring-provider');
        Queue::assertPushed(BuildTopologySnapshot::class, 1);
        Queue::assertPushed(BuildTopologySnapshot::class, fn (BuildTopologySnapshot $queued): bool => $queued->provider === 'unifi'
            && $queued->siteId === $unifiSite->id
            && $queued->connection === 'redis'
            && $queued->queue === 'monitoring-topology');
    }

    public function test_provider_scheduler_does_not_queue_unmapped_or_disconnected_provider_work(): void
    {
        $this->mappedSite('unifi');
        IntegrationProviderConnection::query()->create([
            'provider' => 'milesight',
            'secret_encrypted' => encrypt('test-provider-secret'),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'requires_credential_replacement' => false,
        ]);

        (new ScheduleProviderCapabilities)->handle(app(IntegrationAdapterRegistry::class));

        Queue::assertNothingPushed();
    }

    public function test_device_sync_respects_the_provider_minimum_interval_before_calling_the_adapter(): void
    {
        $site = $this->mappedSite('unifi');
        IntegrationProviderConnection::query()->create([
            'provider' => 'unifi',
            'secret_encrypted' => encrypt('test-provider-secret'),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'requires_credential_replacement' => false,
        ]);
        IntegrationSyncLog::query()->create([
            'provider' => 'unifi',
            'site_id' => $site->id,
            'action' => 'sync_devices',
            'status' => IntegrationSyncLog::STATUS_SUCCESS,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
        $adapter = \Mockery::mock(DeviceSyncCapability::class);
        $adapter->shouldNotReceive('syncDevices');
        $applicationRegistry = app(IntegrationAdapterRegistry::class);
        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('capability')
            ->once()
            ->with('unifi', DeviceSyncCapability::class)
            ->andReturn($adapter);
        $registry->shouldReceive('manifest')
            ->once()
            ->with('unifi')
            ->andReturn($applicationRegistry->manifest('unifi'));

        $job = new SyncIntegrationDevicesJob('unifi');
        $job->handle($registry);

        $this->assertSame('redis', $job->connection);
        $this->assertSame('monitoring-provider', $job->queue);
        $this->assertDatabaseCount('integration_sync_logs', 1);
    }

    public function test_provider_timestamp_does_not_suppress_a_due_site_device_sync(): void
    {
        $recentSite = $this->mappedSite('unifi');
        $dueSite = $this->mappedSite('unifi');
        IntegrationProviderConnection::query()->create([
            'provider' => 'unifi',
            'secret_encrypted' => encrypt('test-provider-secret'),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'last_synced_at' => now(),
            'requires_credential_replacement' => false,
        ]);
        IntegrationSyncLog::query()->create([
            'provider' => 'unifi',
            'site_id' => $recentSite->id,
            'action' => 'sync_devices',
            'status' => IntegrationSyncLog::STATUS_SUCCESS,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);

        $adapter = \Mockery::mock(DeviceSyncCapability::class);
        $adapter->shouldReceive('syncDevices')
            ->once()
            ->withArgs(fn (
                IntegrationSiteConfig $siteConfig,
                IntegrationProviderConnection $connection,
            ): bool => $siteConfig->site_id === $dueSite->id && $connection->provider === 'unifi')
            ->andReturn(new SyncResult(processed: 1, updated: 1));
        $applicationRegistry = app(IntegrationAdapterRegistry::class);
        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('capability')
            ->once()
            ->with('unifi', DeviceSyncCapability::class)
            ->andReturn($adapter);
        $registry->shouldReceive('manifest')
            ->once()
            ->with('unifi')
            ->andReturn($applicationRegistry->manifest('unifi'));

        (new SyncIntegrationDevicesJob('unifi'))->handle($registry);

        $this->assertDatabaseHas('integration_sync_logs', [
            'provider' => 'unifi',
            'site_id' => $dueSite->id,
            'action' => 'sync_devices',
            'status' => IntegrationSyncLog::STATUS_SUCCESS,
        ]);
    }

    public function test_device_sync_job_does_not_record_success_after_the_connection_is_disabled_in_flight(): void
    {
        $site = $this->mappedSite('unifi');
        $previousSyncedAt = now()->subDay()->startOfSecond();
        $connection = IntegrationProviderConnection::query()->create([
            'provider' => 'unifi',
            'secret_encrypted' => encrypt('test-provider-secret'),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'last_synced_at' => $previousSyncedAt,
            'requires_credential_replacement' => false,
        ]);
        $adapter = \Mockery::mock(DeviceSyncCapability::class);
        $adapter->shouldReceive('syncDevices')
            ->once()
            ->andReturnUsing(function () use ($connection): SyncResult {
                $connection->update([
                    'status' => IntegrationProviderConnection::STATUS_DISABLED,
                    'requires_credential_replacement' => true,
                ]);

                return new SyncResult(processed: 1, updated: 1);
            });
        $applicationRegistry = app(IntegrationAdapterRegistry::class);
        $registry = \Mockery::mock(IntegrationAdapterRegistry::class);
        $registry->shouldReceive('capability')
            ->once()
            ->with('unifi', DeviceSyncCapability::class)
            ->andReturn($adapter);
        $registry->shouldReceive('manifest')
            ->once()
            ->with('unifi')
            ->andReturn($applicationRegistry->manifest('unifi'));

        (new SyncIntegrationDevicesJob('unifi', $site->id))->handle($registry);

        $this->assertDatabaseHas('integration_sync_logs', [
            'provider' => 'unifi',
            'site_id' => $site->id,
            'action' => 'sync_devices',
            'status' => IntegrationSyncLog::STATUS_FAILED,
        ]);
        $this->assertSame(
            $previousSyncedAt->toIso8601String(),
            $connection->refresh()->last_synced_at?->toIso8601String(),
        );
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
