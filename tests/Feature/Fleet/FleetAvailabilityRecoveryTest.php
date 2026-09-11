<?php

namespace Tests\Feature\Fleet;

use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Events\FleetSignalEmitted;
use App\Jobs\DetectFleetOfflineDevices;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\Site;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\Fleet\FleetSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FleetAvailabilityRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    private Device $device;

    private DeviceAssetLink $link;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['services.telemetry.ingest_token' => 'test-token', 'fleet.signals.offline_after_minutes' => 15]);
        Queue::fake();
        Notification::fake();
        Http::preventStrayRequests();
        Event::fake([FleetSignalEmitted::class]);
        SignalSource::query()->firstOrCreate(['slug' => 'queclink_fleet'], [
            'name' => 'Queclink Fleet', 'vendor' => 'queclink', 'status' => 'active',
        ]);
        $this->asset = Asset::factory()->vehicle()->create([
            'site_id' => Site::factory()->create(['is_active' => true, 'archived' => false])->id,
            'home_site_id' => null, 'client_id' => null,
        ]);
        $this->device = Device::factory()->tracking()->create([
            'provider' => 'queclink', 'imei' => 'IT-FLEET-RECOVERY', 'device_uid' => 'IT-FLEET-RECOVERY',
        ]);
        $this->link = DeviceAssetLink::query()->create([
            'device_id' => $this->device->id, 'asset_id' => $this->asset->id,
            'link_type' => LinkType::InstalledIn, 'linked_at' => now(),
        ]);
    }

    public function test_recovery_records_one_safe_episode_without_closing_the_alert(): void
    {
        $offline = $this->offlineEpisode();
        $this->deliver($offline);
        $alert = ControlRoomAlert::query()->sole();
        $this->heartbeat();
        $recovery = $this->recovery();
        $this->assertSame($offline->id, $recovery->payload['availability']['offline_signal_id']);
        $this->assertSame($this->link->id, $recovery->payload['availability']['scope']['asset_link_id']);
        $this->deliver($recovery);
        $this->deliver($recovery);
        $this->heartbeat(); // Duplicate source frame cannot publish another recovery.
        $this->travel(1)->seconds();
        $this->heartbeat(); // A further online heartbeat is not a new recovery.

        $this->assertSame('online', FleetVehicleStateSnapshot::query()->sole()->status);
        $this->assertSame(1, FleetSignal::query()->where('signal_type', 'device.online')->count());
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertSame('open', $alert->fresh()->status);
        $evidence = $alert->fresh()->context['fleet_recoveries'][(string) $offline->id];
        $this->assertTrue($evidence['verification_required']);
        $this->assertSame($offline->id, $evidence['offline_fleet_signal_id']);
        $this->assertSame(1, AuditLog::query()->where('action', 'fleet.availability.recovery_recorded')->count());
        foreach (Signal::query()->get() as $signal) {
            $this->assertSame([], $signal->payload['fleet_context']);
            $this->assertNull($signal->normalized_data['trip_id']);
            $this->assertStringNotContainsString('private-provider-context', json_encode($signal->payload));
        }
    }

    public function test_recovery_delivered_first_does_not_raise_a_stale_offline_or_recovery_alarm(): void
    {
        $offline = $this->offlineEpisode();
        $this->heartbeat();
        $this->deliver($this->recovery());
        $this->deliver($offline);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertSame(2, Signal::query()->where('status', 'processed')->count());
        $this->assertStringContainsString('Recovery preceded', Signal::query()->where('external_ref', 'fleet_signal_'.$offline->id)->sole()->processing_notes);
    }

    public function test_delayed_old_recovery_does_not_modify_a_later_offline_episode(): void
    {
        $first = $this->offlineEpisode();
        $this->deliver($first);
        $firstAlert = ControlRoomAlert::query()->sole();
        $this->heartbeat();
        $oldRecovery = $this->recovery();
        $this->travel(16)->minutes();
        (new DetectFleetOfflineDevices)->handle(app(FleetSignalService::class));
        $second = FleetSignal::query()->where('signal_type', 'device.offline')->latest('id')->firstOrFail();
        $this->assertNotSame($first->idempotency_key, $second->idempotency_key);
        $this->deliver($second);
        $secondAlert = ControlRoomAlert::query()->where('id', '!=', $firstAlert->id)->sole();
        $this->deliver($oldRecovery);
        $this->assertArrayHasKey('fleet_recoveries', $firstAlert->fresh()->context);
        $this->assertArrayNotHasKey('fleet_recoveries', $secondAlert->fresh()->context);
        $this->assertSame('open', $secondAlert->fresh()->status);
        $this->assertSame('offline', FleetVehicleStateSnapshot::query()->sole()->status);
    }

    #[DataProvider('scopeChanges')]
    public function test_delayed_recovery_rechecks_current_scope(string $change): void
    {
        $offline = $this->offlineEpisode();
        $this->deliver($offline);
        $alert = ControlRoomAlert::query()->sole();
        $this->heartbeat();
        $recovery = $this->recovery();
        if ($change === 'site') {
            $this->asset->update(['site_id' => Site::factory()->create()->id]);
        } elseif ($change === 'inactive site') {
            Site::query()->whereKey($this->asset->site_id)->update(['is_active' => false]);
        } else {
            $this->link->update(['unlinked_at' => now()]);
            DeviceAssetLink::query()->create([
                'device_id' => $this->device->id, 'asset_id' => $this->asset->id,
                'link_type' => LinkType::InstalledIn, 'linked_at' => now(),
            ]);
        }
        if (in_array($change, ['site', 'inactive site'], true)) {
            $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $recovery->id)->sole();
            (new DispatchFleetSignalOutbox($outbox->id))->handle(app(SignalProcessingService::class));
            $this->assertSame('unroutable', $outbox->fresh()->status);
            $this->assertFalse(Signal::query()->where('external_ref', 'fleet_signal_'.$recovery->id)->exists());
        } else {
            $this->deliver($recovery);
            $control = Signal::query()->where('external_ref', 'fleet_signal_'.$recovery->id)->sole();
            $this->assertStringContainsString('manual verification', $control->processing_notes);
        }
        $this->assertArrayNotHasKey('fleet_recoveries', $alert->fresh()->context);
        $this->assertSame('open', $alert->fresh()->status);
        $this->assertDatabaseCount('control_room_alerts', 1);
    }

    public static function scopeChanges(): array
    {
        return ['moved Site' => ['site'], 'inactive Site' => ['inactive site'], 're-paired device' => ['link']];
    }

    public function test_a_replacement_device_does_not_recover_the_previous_device(): void
    {
        $offline = $this->offlineEpisode();
        $this->deliver($offline);
        $alert = ControlRoomAlert::query()->sole();
        $this->link->update(['unlinked_at' => now()]);
        $this->device = Device::factory()->tracking()->create([
            'provider' => 'queclink', 'imei' => 'IT-FLEET-REPLACEMENT', 'device_uid' => 'IT-FLEET-REPLACEMENT',
        ]);
        DeviceAssetLink::query()->create([
            'device_id' => $this->device->id, 'asset_id' => $this->asset->id,
            'link_type' => LinkType::InstalledIn, 'linked_at' => now(),
        ]);
        $this->heartbeat();
        $recovery = $this->recovery();
        $this->assertNotSame($offline->device_id, $recovery->device_id);
        $this->deliver($recovery);
        $this->assertArrayNotHasKey('fleet_recoveries', $alert->fresh()->context);
        $this->assertStringContainsString('manual verification', Signal::query()->where('external_ref', 'fleet_signal_'.$recovery->id)->sole()->processing_notes);
    }

    public function test_changed_source_evidence_cannot_reuse_an_offline_episode_key(): void
    {
        $offline = $this->offlineEpisode();
        $this->deliver($offline);
        $alert = ControlRoomAlert::query()->sole();
        $this->heartbeat();
        $payload = $offline->payload;
        $payload['last_seen_at'] = now()->toISOString();
        $offline->update(['payload' => $payload]);
        $recovery = $this->recovery();
        $this->deliver($recovery);
        $this->assertArrayNotHasKey('fleet_recoveries', $alert->fresh()->context);
        $this->assertStringContainsString('manual verification', Signal::query()->where('external_ref', 'fleet_signal_'.$recovery->id)->sole()->processing_notes);
    }

    public function test_recovery_outbox_failure_rolls_back_the_heartbeat_and_can_retry(): void
    {
        $this->offlineEpisode();
        $eventCount = FleetTelemetryEvent::query()->count();
        $inject = true;
        FleetSignalOutbox::creating(function (FleetSignalOutbox $outbox) use (&$inject): void {
            if ($inject && $outbox->signal()->first()?->signal_type === 'device.online') {
                throw new RuntimeException('Injected recovery outbox failure.');
            }
        });
        try {
            $this->heartbeat(500);
        } finally {
            $inject = false;
        }
        $this->assertSame('offline', FleetVehicleStateSnapshot::query()->sole()->status);
        $this->assertSame($eventCount, FleetTelemetryEvent::query()->count());
        $this->assertSame(0, FleetSignal::query()->where('signal_type', 'device.online')->count());
        $this->heartbeat();
        $this->assertSame('online', FleetVehicleStateSnapshot::query()->sole()->status);
        $this->assertSame(1, FleetSignal::query()->where('signal_type', 'device.online')->count());
    }

    public function test_legacy_offline_state_without_episode_evidence_records_unmatched_recovery(): void
    {
        $this->heartbeat();
        FleetVehicleStateSnapshot::query()->sole()->update(['status' => 'offline']);
        $this->travel(1)->minutes();
        $this->heartbeat();
        $recovery = $this->recovery();
        $this->assertNull($recovery->payload['availability']['offline_signal_id']);
        $this->deliver($recovery);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertStringContainsString('manual verification', Signal::query()->sole()->processing_notes);
    }

    private function heartbeat(int $expectedStatus = 200): void
    {
        $this->withHeader('X-Telemetry-Token', 'test-token')->postJson('/telemetry/ingest/queclink', [
            'imei' => $this->device->imei, 'gps_time' => now()->toISOString(),
            'event_type' => 'heartbeat', 'private_context' => 'private-provider-context',
        ])->assertStatus($expectedStatus);
    }

    private function offlineEpisode(): FleetSignal
    {
        $this->heartbeat();
        $this->travel(16)->minutes();
        (new DetectFleetOfflineDevices)->handle(app(FleetSignalService::class));

        return FleetSignal::query()->where('signal_type', 'device.offline')->latest('id')->firstOrFail();
    }

    private function recovery(): FleetSignal
    {
        return FleetSignal::query()->where('signal_type', 'device.online')->sole();
    }

    private function deliver(FleetSignal $signal): void
    {
        $outbox = FleetSignalOutbox::query()->where('fleet_signal_id', $signal->id)->sole();
        (new DispatchFleetSignalOutbox($outbox->id))->handle(app(SignalProcessingService::class));
        $this->assertSame('sent', $outbox->fresh()->status, $outbox->fresh()->last_error ?? '');
    }
}
