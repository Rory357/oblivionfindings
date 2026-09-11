<?php

namespace Tests\Concurrency\It;

use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Services\MonitoringAvailabilityEpisode;
use App\Domain\Monitoring\Services\MonitoringObservationIngestor;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceMonitoringTicket;
use App\Models\AuditLog;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\SecurityDevicesSignalSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Standalone real commits, independent workers and interruption in one wrapper-owned schema. */
final class ItMonitoringDeliveryConcurrencyTest extends TestCase
{
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
        $this->seed(SecurityDevicesSignalSeeder::class);
        // An explicit isolated urgent-routing policy; never changes live rules.
        SignalRule::query()->where('signal_type_code', 'device_offline')->update(['output_severity' => 'high']);
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
                $this->assertSame(1, $this->auditCount($outbox, 'it.monitoring.delivery_retry_requested'));
                app()->call([new DispatchDeviceMonitoringTicket($outbox->id), 'handle']);
                $this->assertCanonicalResult($outbox, 4);
            } finally {
                $this->cleanup($workers, $barrier);
            }
            $this->assertDistinctOutboxesShareOneTicket($severity);
        }
    }

    private function pendingDelivery(string $severity): DeviceEventSignalOutbox
    {
        SignalRule::query()->where('signal_type_code', 'device_offline')->update(['output_severity' => $severity]);
        $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $device = Device::factory()->itInfrastructure()->create();
        DeviceAssignment::query()->create([
            'device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subMinute(),
            'assigned_by_user_id' => User::factory()->create()->id,
        ]);
        $profile = MonitoringProfile::factory()->create([
            'failure_confirmations' => 1, 'recovery_confirmations' => 1,
            'failure_duration_seconds' => 0, 'recovery_duration_seconds' => 0,
        ]);
        $monitor = Monitor::factory()->create([
            'device_id' => $device->id, 'profile_id' => $profile->id, 'collector_id' => null,
            'current_state' => MonitorState::Healthy, 'effective_state' => MonitorState::Healthy,
            'affects_availability' => true,
        ]);
        $event = app(MonitoringObservationIngestor::class)->ingest($monitor,
            new ObservationInput('isolated-worker-'.$monitor->id, MonitorState::Failed, CarbonImmutable::now()),
            (int) $site->id, (int) $device->id, null)->deviceEvent;
        $this->assertNotNull($event);
        $this->assertNotNull(MonitoringAvailabilityEpisode::fromEvent($event, (int) $site->id));
        $outbox = $event->signalOutbox()->sole();
        app()->call([new DispatchDeviceEventSignalOutbox($outbox->id), 'handle']);
        $this->assertSame('sent', $outbox->fresh()->status);
        $this->assertSame('pending', $outbox->fresh()->it_status);

        return $outbox->fresh();
    }

    private function assertCanonicalResult(DeviceEventSignalOutbox $outbox, int $attempts = 1): void
    {
        $outbox->refresh();
        $this->assertSame('sent', $outbox->status);
        $this->assertSame('applied', $outbox->it_status);
        $this->assertSame('ticket_created', $outbox->it_outcome_code);
        $this->assertSame($attempts, $outbox->it_attempts);
        $this->assertCount(1, $outbox->it_ticket_ids);
        $ticket = ItTicket::query()->findOrFail($outbox->it_ticket_ids[0]);
        $this->assertSame(1, $ticket->events()->where('type', 'created_from_monitoring')->count());
        $this->assertSame(MonitoringAvailabilityEpisode::fromEvent($outbox->event, (int) $ticket->site_id)['key'],
            $ticket->events()->where('type', 'created_from_monitoring')->sole()->payload['availability_episode_key']);
        $this->assertSame(1, MonitoringIncidentEvidenceSnapshot::query()->where('it_ticket_id', $ticket->id)->count());
        $alertId = $outbox->it_scope['alert_id'] ?? $outbox->it_scope['correlated_alert_id'] ?? null;
        $this->assertSame($alertId, MonitoringIncidentEvidenceSnapshot::query()->where('it_ticket_id', $ticket->id)->sole()->control_room_alert_id);
        $this->assertSame($alertId === null ? 0 : 1, $ticket->links()->where('relationship', 'source_alert')->count());
        $this->assertSame(1, $this->auditCount($outbox, 'it.monitoring.delivery_completed'));
    }

    private function assertDistinctOutboxesShareOneTicket(string $severity): void
    {
        $first = $this->pendingDelivery($severity);
        $event = $first->event;
        $monitor = Monitor::query()->findOrFail($event->payload['monitor_id']);
        $siteId = (int) $first->it_scope['site_id'];
        $start = CarbonImmutable::instance($event->occurred_at);
        MonitoringMaintenanceWindow::query()->create([
            'site_id' => $siteId, 'monitor_id' => $monitor->id, 'name' => 'Isolated competing episode evidence',
            'reason' => 'Verify two accepted outboxes share one technical work item',
            'starts_at' => $start->addSecond(), 'ends_at' => $start->addSeconds(3),
            'timezone' => 'UTC', 'policy' => 'suppress_notifications_and_ticketing', 'status' => 'active',
        ]);
        $ingestor = app(MonitoringObservationIngestor::class);
        $ingestor->ingest($monitor, new ObservationInput('worker-suppressed-'.$monitor->id, MonitorState::Failed, $start->addSeconds(2)), $siteId, (int) $monitor->device_id, null);
        $resumed = $ingestor->ingest($monitor, new ObservationInput('worker-resumed-'.$monitor->id, MonitorState::Failed, $start->addSeconds(4)), $siteId, (int) $monitor->device_id, null)->deviceEvent;
        $this->assertNotNull($resumed);
        $second = $resumed->signalOutbox()->sole();
        app()->call([new DispatchDeviceEventSignalOutbox($second->id), 'handle']);
        $this->assertSame('pending', $second->fresh()->it_status);
        $before = ItTicket::query()->count();
        $barrier = $this->barrier();
        $workers = [];
        try {
            $workers[] = $this->worker('hold_ticket', $first, $barrier, 0);
            $this->awaitReady([$barrier.'-0.ready'], $workers);
            $workers[] = $this->worker('deliver', $second, $barrier, 1);
            $this->awaitReady([$barrier.'-1.ready'], $workers);
            $this->assertTrue($workers[1]->isRunning());
            $this->assertSame($before, ItTicket::query()->count());
            touch($barrier.'.release');
            $this->workerResult($workers[0]);
            $this->workerResult($workers[1]);
            $this->assertSame($before + 1, ItTicket::query()->count());
            $this->assertCanonicalResult($first);
            $this->assertSame($first->fresh()->it_ticket_ids, $second->fresh()->it_ticket_ids);
            $this->assertSame('ticket_updated', $second->fresh()->it_outcome_code);
            $ticket = ItTicket::query()->findOrFail($first->fresh()->it_ticket_ids[0]);
            $this->assertSame(1, $ticket->events()->where('type', 'monitoring_evidence_added')->count());
        } finally {
            $this->cleanup($workers, $barrier);
        }
    }

    private function auditCount(DeviceEventSignalOutbox $outbox, string $action): int
    {
        return AuditLog::query()->where('action', $action)->where('auditable_type', $outbox->getMorphClass())
            ->where('auditable_id', $outbox->id)->count();
    }

    private function barrier(): string
    {
        return storage_path('framework/testing/'.getenv('TEST_TOKEN').'-monitoring-delivery-'.Str::uuid());
    }

    private function worker(string $mode, DeviceEventSignalOutbox $outbox, string $barrier, int $side): Process
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Support/It/monitoring-delivery-concurrency-worker.php'),
            $mode, (string) $outbox->id, $barrier, (string) $side], base_path(), timeout: 45);
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
