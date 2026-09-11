<?php

namespace Tests\Concurrency\It;

use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Jobs\DetectFleetOfflineDevices;
use App\Jobs\DispatchFleetMonitoringTicket;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\ItTicket;
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
use Tests\Support\It\VerifiesMonitoringHandoffConcurrency;
use Tests\TestCase;

/** Standalone real commits, independent workers and interruption in one wrapper-owned schema. */
final class ItFleetDeliveryConcurrencyTest extends TestCase
{
    use VerifiesMonitoringHandoffConcurrency;

    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || getenv('DB_HOST') !== '127.0.0.1'
            || preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN')) !== 1) {
            throw new RuntimeException('Use the isolated IT wrapper for monitoring concurrency verification.');
        }

        return parent::createApplication();
    }

    public function test_competing_deliveries_interruption_and_retry_preserve_one_canonical_result(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('oblivion_it_support_test_'.getenv('TEST_TOKEN'), DB::connection()->getDatabaseName());
        SignalSource::query()->firstOrCreate(['slug' => 'queclink_fleet'], [
            'name' => 'Queclink Fleet', 'vendor' => 'queclink', 'status' => 'active',
        ]);
        config(['fleet.signals.offline_after_minutes' => 15]);
        SignalRule::query()->create([
            'name' => 'Isolated Fleet worker rule', 'signal_type_code' => 'fleet_device_offline',
            'priority' => 1000, 'is_active' => true, 'output_severity' => 'high',
            'output_tier' => 2, 'output_escalation_level' => 1, 'deduplicate' => true,
        ]);
        // An explicit isolated urgent-routing policy; never changes live rules.
        SignalRule::query()->where('signal_type_code', 'fleet_device_offline')->update(['output_severity' => 'high']);
        Queue::fake();
        Notification::fake();
        Http::preventStrayRequests();

        foreach (['high', 'medium'] as $severity) {
            foreach (['hold_claim', 'hold_ticket', 'hold_commit'] as $mode) {
                $outbox = $this->pendingDelivery($severity);
                $ticketCount = ItTicket::query()->count();
                $alertCount = ControlRoomAlert::query()->count();
                $barrier = $this->barrier();
                $workers = [];
                try {
                    $workers[] = $this->worker($mode, $outbox, $barrier, 0);
                    $this->awaitReady([$barrier.'-0.ready'], $workers);
                    if ($mode === 'hold_commit') {
                        $this->assertSame('applied', $outbox->fresh()->it_status);
                        $workers[0]->stop(1);
                        $this->assertFalse($workers[0]->isRunning());
                        $workers[] = $this->worker('deliver', $outbox, $barrier, 1);
                        $this->workerResult($workers[1]);
                    } else {
                        // This worker reaches the same SELECT FOR UPDATE while the first owns it.
                        $workers[] = $this->worker('deliver', $outbox, $barrier, 1);
                        $this->awaitReady([$barrier.'-1.ready'], $workers);
                        $this->assertTrue($workers[1]->isRunning());
                        $this->assertSame('pending', $outbox->fresh()->it_status);
                        $this->assertSame($ticketCount, ItTicket::query()->count(), 'An uncommitted IT insertion must not be visible.');
                        if ($mode === 'hold_ticket') {
                            $workers[0]->stop(1);
                            $this->assertFalse($workers[0]->isRunning());
                        } else {
                            touch($barrier.'.release');
                            $first = $this->workerResult($workers[0]);
                            $this->assertTrue($first['held']);
                        }
                        $this->workerResult($workers[1]);
                    }
                    $this->assertSame($ticketCount + 1, ItTicket::query()->count());
                    $this->assertSame($alertCount, ControlRoomAlert::query()->count());
                    $this->assertCanonicalResult($outbox);
                } finally {
                    $this->cleanup($workers, $barrier);
                }
            }

            // Two operator retries must grant one allowance and create one retry audit.
            $outbox = $this->pendingDelivery($severity);
            $outbox->update(['it_status' => 'dead_letter', 'it_attempts' => 3, 'it_outcome_code' => 'processing_failed']);
            $barrier = $this->barrier();
            $workers = [];
            try {
                $workers[] = $this->worker('retry', $outbox, $barrier, 0);
                $workers[] = $this->worker('retry', $outbox, $barrier, 1);
                $this->awaitReady([$barrier.'-0.ready', $barrier.'-1.ready'], $workers);
                touch($barrier.'.release');
                $results = [$this->workerResult($workers[0])['result'], $this->workerResult($workers[1])['result']];
                sort($results);
                $this->assertSame(['retry_rejected', 'retry_requested'], $results);
                $this->assertSame('pending', $outbox->fresh()->it_status);
                $this->assertSame(3, $outbox->fresh()->it_attempts);
                $this->assertSame(4, $outbox->fresh()->it_attempt_limit);
                $this->assertSame(1, $this->auditCount($outbox, 'it.fleet.delivery_retry_requested'));
                app()->call([new DispatchFleetMonitoringTicket($outbox->id), 'handle']);
                $this->assertCanonicalResult($outbox, 4);
            } finally {
                $this->cleanup($workers, $barrier);
            }
            $this->assertRecoveryContendsWithCreation($severity);
            if ($severity === 'high') {
                $this->assertHumanHandoffRaces('fleet');
            }
        }
    }

    private function pendingDelivery(string $severity): FleetSignalOutbox
    {
        SignalRule::query()->where('signal_type_code', 'fleet_device_offline')->update(['output_severity' => $severity]);
        $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $asset = Asset::factory()->vehicle()->create(['site_id' => $site->id, 'home_site_id' => null, 'client_id' => null]);
        $identity = 'fleet-worker-'.Str::uuid();
        $device = Device::factory()->tracking()->create(['provider' => 'queclink', 'imei' => $identity, 'device_uid' => $identity]);
        DeviceAssetLink::query()->create([
            'device_id' => $device->id, 'asset_id' => $asset->id,
            'link_type' => LinkType::InstalledIn, 'linked_at' => now()->subHour(),
        ]);
        $previousNow = Carbon::getTestNow();
        try {
            Carbon::setTestNow(now()->subMinutes(20));
            $this->heartbeat($device);
        } finally {
            Carbon::setTestNow($previousNow);
        }
        (new DetectFleetOfflineDevices)->handle(app(FleetSignalService::class));
        $offline = FleetSignal::query()->where('asset_id', $asset->id)->where('signal_type', 'device.offline')->sole();
        $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $offline->id)->sole();
        app()->call([new DispatchFleetSignalOutbox($outbox->id), 'handle']);
        $this->assertSame('sent', $outbox->fresh()->status);
        $this->assertSame('pending', $outbox->fresh()->it_status);

        return $outbox->fresh();
    }

    private function heartbeat(Device $device): void
    {
        $result = app(FleetTelemetryIngestService::class)->ingest('queclink', [
            'imei' => $device->imei, 'gps_time' => now()->toISOString(), 'event_type' => 'heartbeat',
        ], (int) $device->id);
        $this->assertTrue($result['ok']);
    }

    private function assertCanonicalResult(FleetSignalOutbox $outbox, int $attempts = 1): void
    {
        $outbox->refresh();
        $this->assertSame('sent', $outbox->status);
        $this->assertSame('applied', $outbox->it_status);
        $this->assertSame('ticket_created', $outbox->it_outcome_code);
        $this->assertSame($attempts, $outbox->it_attempts);
        $this->assertCount(1, $outbox->it_ticket_ids);
        $ticket = ItTicket::query()->findOrFail($outbox->it_ticket_ids[0]);
        $this->assertSame(1, $ticket->events()->where('type', 'created_from_monitoring')->count());
        $offline = FleetSignal::query()->findOrFail($outbox->fleet_signal_id);
        $this->assertSame($offline->idempotency_key, $ticket->events()->where('type', 'created_from_monitoring')->sole()->payload['availability_episode_key']);
        $snapshot = MonitoringIncidentEvidenceSnapshot::query()->where('it_ticket_id', $ticket->id)->sole();
        $this->assertSame(3, $snapshot->evidence_version);
        $this->assertNull($snapshot->device_event_id);
        $this->assertSame((int) $offline->id, (int) $snapshot->fleet_signal_id);
        $this->assertTrue($snapshot->hasValidChecksum());
        $this->assertSame(1, $ticket->links()->where('relationship', 'affected_asset')->count());
        $this->assertSame(1, $ticket->links()->where('relationship', 'affected_device')->count());
        $this->assertSame(1, MonitoringIncidentEvidenceSnapshot::query()->where('it_ticket_id', $ticket->id)->count());
        $alertId = $outbox->it_scope['alert_id'] ?? $outbox->it_scope['correlated_alert_id'] ?? null;
        $this->assertSame($alertId, MonitoringIncidentEvidenceSnapshot::query()->where('it_ticket_id', $ticket->id)->sole()->control_room_alert_id);
        $this->assertSame($alertId === null ? 0 : 1, $ticket->links()->where('relationship', 'source_alert')->count());
        $this->assertSame(1, $this->auditCount($outbox, 'it.fleet.delivery_completed'));
    }

    private function assertRecoveryContendsWithCreation(string $severity): void
    {
        $offline = $this->pendingDelivery($severity);
        $event = FleetSignal::query()->findOrFail($offline->fleet_signal_id);
        $this->heartbeat($event->device);
        $recovery = FleetSignal::query()->where('asset_id', $event->asset_id)->where('signal_type', 'device.online')->sole();
        $online = FleetSignalOutbox::query()->where('fleet_signal_id', $recovery->id)->sole();
        app()->call([new DispatchFleetSignalOutbox($online->id), 'handle']);
        $this->assertSame('pending', $online->fresh()->it_status);
        $before = ItTicket::query()->count();
        $barrier = $this->barrier();
        $workers = [];
        try {
            $workers[] = $this->worker('hold_ticket', $offline, $barrier, 0);
            $this->awaitReady([$barrier.'-0.ready'], $workers);
            $workers[] = $this->worker('deliver', $online, $barrier, 1);
            $this->awaitReady([$barrier.'-1.ready'], $workers);
            $this->assertTrue($workers[1]->isRunning());
            $this->assertSame($before, ItTicket::query()->count());
            touch($barrier.'.release');
            $this->workerResult($workers[0]);
            $this->workerResult($workers[1]);
            $this->assertSame($before + 1, ItTicket::query()->count());
            $this->assertCanonicalResult($offline);
            $this->assertSame('applied', $online->fresh()->it_status);
            $this->assertSame($offline->fresh()->it_ticket_ids, $online->fresh()->it_ticket_ids);
            $this->assertSame('recovery_recorded', $online->fresh()->it_outcome_code);
            $ticket = ItTicket::query()->findOrFail($offline->fresh()->it_ticket_ids[0]);
            $this->assertSame('open', $ticket->status);
            $this->assertTrue($ticket->monitoring_recovered_at->equalTo($recovery->occurred_at));
            $this->assertSame(1, $ticket->events()->where('type', 'monitoring_recovered')->count());
            $this->assertSame(1, $this->auditCount($online, 'it.fleet.delivery_completed'));
        } finally {
            $this->cleanup($workers, $barrier);
        }
    }

    private function auditCount(FleetSignalOutbox $outbox, string $action): int
    {
        return AuditLog::query()->where('action', $action)->where('auditable_type', $outbox->getMorphClass())
            ->where('auditable_id', $outbox->id)->count();
    }

    private function barrier(): string
    {
        return storage_path('framework/testing/'.getenv('TEST_TOKEN').'-monitoring-delivery-'.Str::uuid());
    }

    private function worker(string $mode, FleetSignalOutbox $outbox, string $barrier, int $side): Process
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Support/It/monitoring-delivery-concurrency-worker.php'),
            $mode, (string) $outbox->id, $barrier, (string) $side, 'fleet'], base_path(), timeout: 45);
        $worker->start();

        return $worker;
    }

    private function awaitReady(array $paths, array $workers): void
    {
        $deadline = microtime(true) + 30;
        while (count(array_filter($paths, 'is_file')) !== count($paths)) {
            foreach ($workers as $worker) {
                if (! $worker->isRunning()) {
                    throw new RuntimeException('Monitoring worker stopped before its barrier: '.$worker->getErrorOutput());
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Monitoring worker barrier timed out.');
            }
            usleep(10_000);
        }
    }

    private function workerResult(Process $worker): array
    {
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
        $result = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $result['transaction_level']);

        return $result;
    }

    private function cleanup(array $workers, string $barrier): void
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
            $this->assertFileDoesNotExist($path);
        }
    }
}
