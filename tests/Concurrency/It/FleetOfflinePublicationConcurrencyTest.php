<?php

namespace Tests\Concurrency\It;

use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Jobs\DetectFleetOfflineDevices;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\Site;
use App\Services\Fleet\FleetSignalService;
use App\Services\Fleet\FleetTelemetryIngestService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Standalone: real commits and workers in the wrapper-owned disposable schema. */
final class FleetOfflinePublicationConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || getenv('DB_HOST') !== '127.0.0.1'
            || preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN')) !== 1) {
            throw new RuntimeException('Use the isolated IT test wrapper for Fleet concurrency verification.');
        }

        return parent::createApplication();
    }

    public function test_competing_scans_recheck_heartbeats_and_publish_one_committed_episode(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('oblivion_it_support_test_'.getenv('TEST_TOKEN'), DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        Notification::fake();

        foreach ([true, false] as $heartbeatAfterScan) {
            $state = FleetVehicleStateSnapshot::query()->create([
                'asset_id' => Asset::factory()->vehicle()->create()->id,
                'status' => 'online',
                'last_seen_at' => now()->subMinutes(20),
            ]);
            $barrier = storage_path('framework/testing/'.getenv('TEST_TOKEN').'-fleet-offline-'.Str::uuid());
            $ready = [$barrier.'-0.ready', $barrier.'-1.ready'];
            $release = $barrier.'.release';
            $workers = [];
            try {
                foreach ($ready as $path) {
                    $worker = new Process([
                        PHP_BINARY, base_path('tests/Support/It/fleet-offline-concurrency-worker.php'),
                        (string) $state->asset_id, $path, $release,
                    ], base_path(), timeout: 45);
                    $worker->start();
                    $workers[] = $worker;
                }
                $deadline = microtime(true) + 30;
                while (count(array_filter($ready, 'is_file')) !== 2) {
                    foreach ($workers as $worker) {
                        if (! $worker->isRunning()) {
                            throw new RuntimeException('Fleet worker stopped before its scanned-candidate barrier: '.$worker->getErrorOutput());
                        }
                    }
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Fleet scanned-candidate barrier timed out.');
                    }
                    usleep(10_000);
                }
                // Both workers have selected the old online row before this write.
                if ($heartbeatAfterScan) {
                    $state->update(['last_seen_at' => now()]);
                }
                touch($release);
                $results = [];
                foreach ($workers as $worker) {
                    $worker->wait();
                    $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                    $results[] = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                }
                $expected = $heartbeatAfterScan ? 0 : 1;
                $this->assertSame($heartbeatAfterScan ? 'online' : 'offline', $state->fresh()->status);
                $signals = FleetSignal::query()->where('asset_id', $state->asset_id)->get();
                $this->assertCount($expected, $signals);
                $this->assertSame($expected, FleetSignalOutbox::query()->whereIn('fleet_signal_id', $signals->modelKeys())->count());
                $queueLevels = array_merge(...array_column($results, 'queue_levels'));
                $eventLevels = array_merge(...array_column($results, 'event_levels'));
                $this->assertSame($expected ? [0] : [], $queueLevels, 'Queue publication must follow a real root commit.');
                $this->assertSame($expected ? [0] : [], $eventLevels, 'Domain listeners must follow a real root commit.');
                foreach ($results as $result) {
                    $this->assertTrue($result['scanned']);
                    $this->assertSame(0, $result['transaction_level']);
                }
            } finally {
                foreach ($workers as $worker) {
                    if ($worker->isRunning()) {
                        $worker->stop(1);
                    }
                }
                foreach ([...$ready, $release] as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
        }
        Queue::fake();
        config()->set('fleet.signals.offline_after_minutes', 15);
        SignalSource::query()->firstOrCreate(['slug' => 'queclink_fleet'], [
            'name' => 'Queclink Fleet', 'vendor' => 'queclink', 'status' => 'active',
        ]);
        $this->assertHeartbeatOverlapsDetector('state');
        $this->assertHeartbeatOverlapsDetector('commit');
        $this->assertCompetingOutboxDelivery();
    }

    private function assertHeartbeatOverlapsDetector(string $stage): void
    {
        [$asset] = $this->availabilityFixture();
        $barrier = $this->availabilityBarrier();
        $workers = [];
        try {
            $workers[] = $this->availabilityWorker('detect_'.$stage, $asset->id, $barrier, 0);
            $this->awaitReady([$barrier.'-0.ready'], $workers);
            $workers[] = $this->availabilityWorker('heartbeat_'.($stage === 'state' ? 'state' : 'device'), $asset->id, $barrier, 1);
            $this->awaitReady([$barrier.'-1.ready'], $workers);
            touch($barrier.'.release');
            $results = $this->availabilityResults($workers);
            $offline = FleetSignal::query()->where('asset_id', $asset->id)->where('signal_type', 'device.offline')->get();
            $online = FleetSignal::query()->where('asset_id', $asset->id)->where('signal_type', 'device.online')->get();
            $this->assertSame('online', FleetVehicleStateSnapshot::query()->findOrFail($asset->id)->status);
            $this->assertSame($offline->count(), $online->count(), 'No committed offline source may lose the following recovery.');
            $this->assertLessThanOrEqual(1, $offline->count());
            if ($stage === 'commit') {
                $this->assertCount(1, $offline, 'The held detector had already persisted the source before heartbeat publication.');
            } else {
                $this->assertGreaterThanOrEqual(3, array_sum(array_column($results, 'state_locks')), 'The actual lock inversion must be retried by one worker.');
            }
            if ($offline->isNotEmpty()) {
                $this->assertSame($offline->sole()->id, $online->sole()->payload['availability']['offline_signal_id']);
            }
            $signalIds = $offline->merge($online)->modelKeys();
            $this->assertSame(count($signalIds), FleetSignalOutbox::query()->whereIn('fleet_signal_id', $signalIds)->count());
            $expectedLevels = array_fill(0, count($signalIds), 0);
            $this->assertSame($expectedLevels, array_merge(...array_column($results, 'queue_levels')));
            $this->assertSame($expectedLevels, array_merge(...array_column($results, 'event_levels')));
        } finally {
            $this->cleanAvailabilityWorkers($workers, $barrier);
        }
    }

    private function assertCompetingOutboxDelivery(): void
    {
        foreach ([false, true] as $recoverBeforeDelivery) {
            [$asset, $device] = $this->availabilityFixture();
            (new DetectFleetOfflineDevices)->handle(app(FleetSignalService::class));
            $offline = FleetSignal::query()->where('asset_id', $asset->id)->where('signal_type', 'device.offline')->sole();
            $offlineOutbox = $offline->outbox()->sole();
            if (! $recoverBeforeDelivery) {
                $this->deliverConcurrently([$offlineOutbox->id, $offlineOutbox->id]);
                $this->assertSame(1, ControlRoomAlert::query()->where('asset_id', $asset->id)->count());
            }
            $this->ingestHeartbeat($device);
            $recovery = FleetSignal::query()->where('asset_id', $asset->id)->where('signal_type', 'device.online')->sole();
            $recoveryOutbox = $recovery->outbox()->sole();
            $this->deliverConcurrently($recoverBeforeDelivery
                ? [$offlineOutbox->id, $recoveryOutbox->id]
                : [$recoveryOutbox->id, $recoveryOutbox->id]);
            $this->assertSame('sent', $offlineOutbox->fresh()->status);
            $this->assertSame('sent', $recoveryOutbox->fresh()->status);
            $this->assertSame(2, Signal::query()->where('asset_id', $asset->id)->where('status', 'processed')->count());
            $this->assertSame($recoverBeforeDelivery ? 0 : 1, ControlRoomAlert::query()->where('asset_id', $asset->id)->count());
            if (! $recoverBeforeDelivery) {
                $alert = ControlRoomAlert::query()->where('asset_id', $asset->id)->sole();
                $this->assertSame('open', $alert->status);
                $this->assertTrue($alert->context['fleet_recoveries'][(string) $offline->id]['verification_required']);
                $this->assertSame(1, AuditLog::query()->where('action', 'fleet.availability.recovery_recorded')->count());
            }
        }
    }

    /** @return array{Asset, Device} */
    private function availabilityFixture(): array
    {
        $asset = Asset::factory()->vehicle()->create([
            'site_id' => Site::factory()->create(['is_active' => true, 'archived' => false])->id,
            'home_site_id' => null, 'client_id' => null,
        ]);
        $uid = 'IT-FLEET-RACE-'.$asset->id;
        $device = Device::factory()->tracking()->create(['provider' => 'queclink', 'imei' => $uid, 'device_uid' => $uid]);
        DeviceAssetLink::query()->create([
            'device_id' => $device->id, 'asset_id' => $asset->id,
            'link_type' => LinkType::InstalledIn, 'linked_at' => now()->subHour(),
        ]);
        Carbon::setTestNow(now()->subMinutes(20));
        try {
            $this->ingestHeartbeat($device);
        } finally {
            Carbon::setTestNow();
        }

        return [$asset, $device];
    }

    private function ingestHeartbeat(Device $device): void
    {
        $result = app(FleetTelemetryIngestService::class)->ingest('queclink', [
            'imei' => $device->imei, 'gps_time' => now()->toISOString(), 'event_type' => 'heartbeat',
        ], (int) $device->id);
        $this->assertTrue($result['ok']);
    }

    private function deliverConcurrently(array $ids): void
    {
        $barrier = $this->availabilityBarrier();
        $workers = [];
        try {
            foreach ($ids as $side => $id) {
                $workers[] = $this->availabilityWorker('deliver', $id, $barrier, $side);
            }
            $this->awaitReady([$barrier.'-0.ready', $barrier.'-1.ready'], $workers);
            touch($barrier.'.release');
            $this->availabilityResults($workers);
        } finally {
            $this->cleanAvailabilityWorkers($workers, $barrier);
        }
    }

    private function availabilityBarrier(): string
    {
        return storage_path('framework/testing/'.getenv('TEST_TOKEN').'-fleet-availability-'.Str::uuid());
    }

    private function availabilityWorker(string $mode, int $id, string $barrier, int $side): Process
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Support/It/fleet-availability-concurrency-worker.php'),
            $mode, (string) $id, $barrier, (string) $side], base_path(), timeout: 45);
        $worker->start();

        return $worker;
    }

    private function awaitReady(array $paths, array $workers): void
    {
        $deadline = microtime(true) + 30;
        while (count(array_filter($paths, 'is_file')) !== count($paths)) {
            foreach ($workers as $worker) {
                if (! $worker->isRunning()) {
                    throw new RuntimeException('Fleet worker stopped before the barrier: '.$worker->getErrorOutput());
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Fleet availability barrier timed out.');
            }
            usleep(10_000);
        }
    }

    private function availabilityResults(array $workers): array
    {
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $result = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(0, $result['transaction_level']);
            $results[] = $result;
        }

        return $results;
    }

    private function cleanAvailabilityWorkers(array $workers, string $barrier): void
    {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        foreach ([$barrier.'-0.ready', $barrier.'-1.ready', $barrier.'.release'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
