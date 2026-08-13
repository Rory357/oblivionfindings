<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceEventSignalOutbox;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Jobs\DispatchShiftSignalOutbox;
use App\Models\Asset;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\ShiftSignalOutbox;
use App\Models\Site;
use App\Models\User;
use App\Observers\DeviceEventObserver;
use App\Services\ControlRoom\ControlRoomNotificationService;
use App\Services\ControlRoom\SafetySignalDeliveryRecoveryService;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\Fleet\FleetSignalService;
use App\Services\ShiftSignalService;
use Database\Seeders\SecurityDevicesSignalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SafetySignalDeliveryRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed(SecurityDevicesSignalSeeder::class);

        SignalSource::query()->firstOrCreate(
            ['slug' => 'queclink_fleet'],
            [
                'name' => 'Queclink Fleet',
                'vendor' => 'queclink',
                'status' => 'active',
            ],
        );

        $notifications = $this->mock(ControlRoomNotificationService::class);
        $notifications->shouldReceive('notifyAlert')->andReturnNull();
        $notifications->shouldReceive('stageAlertNotifications')->andReturn(collect());
    }

    public function test_source_and_outbox_intent_roll_back_together_when_outbox_persistence_fails(): void
    {
        $asset = Asset::factory()->vehicle()->create();
        $eventName = 'eloquent.creating: '.FleetSignalOutbox::class;

        Event::listen($eventName, function (): never {
            throw new RuntimeException('injected outbox persistence failure');
        });

        try {
            app(FleetSignalService::class)->emit([
                'asset_id' => $asset->id,
                'signal_type' => 'vehicle.sos',
                'severity_hint' => 'critical',
                'occurred_at' => now(),
                'idempotency_key' => hash('sha256', 'transaction-boundary-test'),
            ]);
            $this->fail('The injected persistence failure should escape the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected outbox persistence failure', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertDatabaseCount('fleet_signals', 0);
        $this->assertDatabaseCount('fleet_signal_outbox', 0);
    }

    public function test_recovery_reconciles_a_missing_outbox_and_retry_converges_on_one_fleet_alert(): void
    {
        $site = Site::factory()->create();
        $asset = Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'home_site_id' => null,
        ]);
        $sourceKey = hash('sha256', 'fleet-recovery-source');
        $fleetSignal = FleetSignal::query()->create([
            'asset_id' => $asset->id,
            'signal_type' => 'vehicle.sos',
            'severity_hint' => 'critical',
            'occurred_at' => now(),
            'idempotency_key' => $sourceKey,
        ]);

        $result = app(SafetySignalDeliveryRecoveryService::class)->recover();

        $this->assertSame(1, $result['reconciled']['fleet']);
        $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $fleetSignal->id)->sole();

        $failedProcessor = Mockery::mock(SignalProcessingService::class);
        $failedProcessor->shouldReceive('ingestFromFleetSignal')
            ->once()
            ->andThrow(new RuntimeException('injected router failure'));

        try {
            (new DispatchFleetSignalOutbox($outbox->id))->handle($failedProcessor);
            $this->fail('The injected routing failure should be retried by the queue.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected router failure', $exception->getMessage());
        }

        $this->assertSame('failed', $outbox->fresh()->status);
        $this->assertDatabaseCount('control_room_alerts', 0);

        $job = new DispatchFleetSignalOutbox($outbox->id);
        $job->handle(app(SignalProcessingService::class));
        $job->handle(app(SignalProcessingService::class));

        $this->assertSame('sent', $outbox->fresh()->status);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertSame(
            hash('sha256', 'safety-signal|fleet|'.$sourceKey),
            Signal::query()->sole()->idempotency_key,
        );
    }

    public function test_shift_null_site_is_visible_unroutable_and_replays_once_after_repair(): void
    {
        $sourceKey = hash('sha256', 'shift-unroutable-source');
        $shiftSignal = app(ShiftSignalService::class)->emit([
            'signal_type' => ShiftSignalService::TYPE_UNCOVERED,
            'severity_hint' => 'high',
            'occurred_at' => now(),
            'idempotency_key' => $sourceKey,
        ]);
        $outbox = ShiftSignalOutbox::query()->where('shift_signal_id', $shiftSignal->id)->sole();

        (new DispatchShiftSignalOutbox($outbox->id))
            ->handle(app(SignalProcessingService::class));

        $this->assertSame('unroutable', $outbox->fresh()->status);
        $this->assertNotNull($outbox->fresh()->last_error);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $report = app(SafetySignalDeliveryRecoveryService::class)->recover(reportOnly: true);
        $this->assertSame('shift', $report['failure_rows'][0]['source']);
        $this->assertSame($outbox->id, $report['failure_rows'][0]['id']);
        $this->assertSame('unroutable', $report['failure_rows'][0]['status']);

        $site = Site::factory()->create();
        $shiftSignal->forceFill(['site_id' => $site->id])->save();
        app(SafetySignalDeliveryRecoveryService::class)->retry('shift', $outbox->id);

        $job = new DispatchShiftSignalOutbox($outbox->id);
        $job->handle(app(SignalProcessingService::class));
        $job->handle(app(SignalProcessingService::class));

        $this->assertSame('sent', $outbox->fresh()->status);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertSame(
            hash('sha256', 'safety-signal|shift|'.$sourceKey),
            Signal::query()->sole()->idempotency_key,
        );
    }

    public function test_duplicate_and_reordered_device_event_jobs_produce_one_correlated_final_alert(): void
    {
        $device = $this->assignedDevice();
        $older = $this->deviceEvent($device, now()->subSecond());
        $newer = $this->deviceEvent($device, now());
        $olderOutbox = DeviceEventSignalOutbox::query()->where('device_event_id', $older->id)->sole();
        $newerOutbox = DeviceEventSignalOutbox::query()->where('device_event_id', $newer->id)->sole();
        $observer = app(DeviceEventObserver::class);

        $newerJob = new DispatchDeviceEventSignalOutbox($newerOutbox->id);
        $olderJob = new DispatchDeviceEventSignalOutbox($olderOutbox->id);
        $newerJob->handle($observer);
        $olderJob->handle($observer);
        $olderJob->handle($observer);
        $newerJob->handle($observer);

        $this->assertDatabaseCount('device_event_signal_outbox', 2);
        $this->assertDatabaseCount('control_room_signals', 2);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertSame('sent', $olderOutbox->fresh()->status);
        $this->assertSame('sent', $newerOutbox->fresh()->status);
        $this->assertNotNull($older->fresh()->processed_at);
        $this->assertNotNull($newer->fresh()->processed_at);
    }

    public function test_device_event_without_site_is_unroutable_then_replays_without_duplicate_alert(): void
    {
        $device = Device::factory()->create();
        $event = $this->deviceEvent($device, now());
        $outbox = DeviceEventSignalOutbox::query()->where('device_event_id', $event->id)->sole();
        $job = new DispatchDeviceEventSignalOutbox($outbox->id);

        $job->handle(app(DeviceEventObserver::class));

        $this->assertSame('unroutable', $outbox->fresh()->status);
        $this->assertNull($event->fresh()->processed_at);
        $this->assertDatabaseCount('control_room_alerts', 0);

        $site = Site::factory()->create();
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'assigned_by_user_id' => User::factory()->create()->id,
        ]);
        app(SafetySignalDeliveryRecoveryService::class)->retry('device', $outbox->id);

        $job->handle(app(DeviceEventObserver::class));
        $job->handle(app(DeviceEventObserver::class));

        $this->assertSame('sent', $outbox->fresh()->status);
        $this->assertNotNull($event->fresh()->processed_at);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertSame(
            hash('sha256', 'safety-signal|device-event|'.$event->id),
            Signal::query()->sole()->idempotency_key,
        );
    }

    public function test_device_dead_letter_is_visible_and_safe_replay_creates_one_final_alert(): void
    {
        $device = $this->assignedDevice();
        $event = $this->deviceEvent($device, now());
        $outbox = DeviceEventSignalOutbox::query()->where('device_event_id', $event->id)->sole();
        $job = new DispatchDeviceEventSignalOutbox($outbox->id);

        $job->failed(new RuntimeException('injected exhausted handler failure'));

        $this->assertSame('dead_letter', $outbox->fresh()->status);
        $this->assertSame('injected exhausted handler failure', $outbox->fresh()->last_error);
        $this->assertDatabaseCount('control_room_alerts', 0);

        app(SafetySignalDeliveryRecoveryService::class)->retry('device', $outbox->id);
        $job->handle(app(DeviceEventObserver::class));
        $job->handle(app(DeviceEventObserver::class));

        $this->assertSame('sent', $outbox->fresh()->status);
        $this->assertDatabaseCount('control_room_alerts', 1);
    }

    private function assignedDevice(): Device
    {
        $device = Device::factory()->create();
        $site = Site::factory()->create();

        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'assigned_by_user_id' => User::factory()->create()->id,
        ]);

        return $device;
    }

    private function deviceEvent(Device $device, \DateTimeInterface $occurredAt): DeviceEvent
    {
        return DeviceEvent::query()->create([
            'device_id' => $device->id,
            'event_type' => 'alarm_trigger',
            'severity' => 'critical',
            'source' => 'safety_signal_delivery_test',
            'occurred_at' => $occurredAt,
            'payload' => ['boundary_test' => true],
        ]);
    }
}
