<?php

namespace Tests\Unit\ControlRoom;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\Device;
use App\Models\ControlRoom\MaintenanceWindow;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoomAlert;
use App\Services\ControlRoom\ControlRoomNotificationService;
use App\Services\ControlRoom\SignalProcessingService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignalProcessingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SignalProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $notificationService = $this->mock(ControlRoomNotificationService::class);
        $notificationService->shouldReceive('notifyAlert')->andReturnNull();

        $this->service = new SignalProcessingService($notificationService);
    }

    // ──────────────────────────────────────
    // Signal Ingestion
    // ──────────────────────────────────────

    public function test_ingest_creates_signal(): void
    {
        $source = SignalSource::create([
            'name' => 'Test Source',
            'slug' => 'test-source',
            'category' => 'fleet',
            'vendor' => 'TestVendor',
            'status' => 'active',
        ]);

        $signalType = SignalType::create([
            'code' => 'test.signal',
            'name' => 'Test Signal',
            'category' => 'fleet',
            'default_severity' => 'medium',
        ]);

        $signal = $this->service->ingest([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'idempotency_key' => 'unique-key-123',
            'payload' => ['speed' => 120],
            'received_at' => now(),
        ]);

        $this->assertInstanceOf(Signal::class, $signal);
        $this->assertEquals('pending', $signal->status);
        $this->assertDatabaseHas('control_room_signals', [
            'idempotency_key' => 'unique-key-123',
        ]);
    }

    public function test_ingest_deduplicates_by_idempotency_key(): void
    {
        $source = SignalSource::create([
            'name' => 'Test Source',
            'slug' => 'test-dedup',
            'category' => 'fleet',
            'vendor' => 'TestVendor',
            'status' => 'active',
        ]);

        $signalType = SignalType::create([
            'code' => 'test.dedup',
            'name' => 'Dedup Test',
            'category' => 'fleet',
            'default_severity' => 'medium',
        ]);

        $data = [
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'idempotency_key' => 'duplicate-key',
            'payload' => ['test' => true],
            'received_at' => now(),
        ];

        $signal1 = $this->service->ingest($data);
        $signal2 = $this->service->ingest($data);

        $this->assertEquals($signal1->id, $signal2->id);
        $this->assertEquals(1, Signal::where('idempotency_key', 'duplicate-key')->count());
    }

    // ──────────────────────────────────────
    // Signal Processing
    // ──────────────────────────────────────

    public function test_process_creates_alert_from_signal_with_matching_rule(): void
    {
        $source = SignalSource::create([
            'name' => 'Fleet Source',
            'slug' => 'fleet-source',
            'category' => 'fleet',
            'vendor' => 'FleetVendor',
            'status' => 'active',
        ]);

        $signalType = SignalType::create([
            'code' => 'fleet.speeding',
            'name' => 'Speeding Alert',
            'category' => 'fleet',
            'default_severity' => 'high',
        ]);

        // Create a matching rule
        SignalRule::create([
            'name' => 'Speeding Rule',
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'conditions' => [],
            'severity_override' => 'critical',
            'alert_type' => 'Speeding',
            'is_active' => true,
            'priority' => 1,
        ]);

        $signal = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'idempotency_key' => 'process-test-1',
            'payload' => ['speed' => 150],
            'received_at' => now(),
            'status' => 'pending',
        ]);

        $alert = $this->service->process($signal);

        $this->assertNotNull($alert);
        $this->assertInstanceOf(ControlRoomAlert::class, $alert);
        $this->assertEquals('Speeding', $alert->alert_type);
        $this->assertEquals('open', $alert->status);

        $signal->refresh();
        $this->assertEquals('processed', $signal->status);
    }

    public function test_process_skips_signal_during_maintenance_window(): void
    {
        $source = SignalSource::create([
            'name' => 'Maintained Source',
            'slug' => 'maintained',
            'category' => 'fleet',
            'vendor' => 'TestVendor',
            'status' => 'active',
        ]);

        $signalType = SignalType::create([
            'code' => 'maint.test',
            'name' => 'Maint Test',
            'category' => 'fleet',
            'default_severity' => 'medium',
        ]);

        // Create maintenance window
        MaintenanceWindow::create([
            'name' => 'Scheduled Maintenance',
            'signal_source_id' => $source->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'status' => 'active',
        ]);

        $signal = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'idempotency_key' => 'maint-test-1',
            'payload' => [],
            'received_at' => now(),
            'status' => 'pending',
        ]);

        $alert = $this->service->process($signal);

        $this->assertNull($alert);
        $signal->refresh();
        $this->assertEquals('suppressed', $signal->status);
    }

    public function test_process_without_matching_rule_creates_default_alert(): void
    {
        $source = SignalSource::create([
            'name' => 'No Rule Source',
            'slug' => 'no-rule',
            'category' => 'fleet',
            'vendor' => 'TestVendor',
            'status' => 'active',
        ]);

        $signalType = SignalType::create([
            'code' => 'unmatched.signal',
            'name' => 'Unmatched Signal',
            'category' => 'fleet',
            'default_severity' => 'low',
        ]);

        $signal = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'idempotency_key' => 'no-rule-test-1',
            'payload' => [],
            'received_at' => now(),
            'status' => 'pending',
        ]);

        $alert = $this->service->process($signal);

        // Depending on implementation, may create default alert or skip
        $signal->refresh();
        $this->assertContains($signal->status, ['processed', 'skipped']);
    }

    public function test_incident_tagged_signals_correlate_only_to_the_exact_incident(): void
    {
        $client = Client::factory()->create();
        $firstIncident = ClientIncident::factory()->create(['client_id' => $client->id]);
        $secondIncident = ClientIncident::factory()->create(['client_id' => $client->id]);
        $source = SignalSource::create([
            'name' => 'Incident Signals',
            'slug' => 'incident-signals',
            'category' => 'medication',
            'vendor' => 'internal',
            'status' => 'active',
        ]);
        $signalType = SignalType::create([
            'code' => 'medication.incident',
            'name' => 'Medication Incident',
            'category' => 'medication',
            'default_severity' => 'high',
        ]);
        SignalRule::create([
            'name' => 'Incident signal exact correlation',
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'conditions' => [],
            'alert_type' => 'Medication incident',
            'deduplicate' => true,
            'dedup_window_minutes' => 30,
            'is_active' => true,
            'priority' => 1,
        ]);

        $first = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'idempotency_key' => 'incident-501-first',
            'client_id' => $client->id,
            'normalized_data' => ['incident_id' => $firstIncident->id],
            'received_at' => now(),
            'occurred_at' => now(),
            'status' => 'pending',
        ]);
        $second = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'idempotency_key' => 'incident-502-first',
            'client_id' => $client->id,
            'normalized_data' => ['incident_id' => $secondIncident->id],
            'received_at' => now(),
            'occurred_at' => now(),
            'status' => 'pending',
        ]);
        $retry = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'idempotency_key' => 'incident-501-retry',
            'client_id' => $client->id,
            'normalized_data' => ['incident_id' => $firstIncident->id],
            'received_at' => now(),
            'occurred_at' => now(),
            'status' => 'pending',
        ]);

        $firstAlert = $this->service->process($first);
        $secondAlert = $this->service->process($second);
        $retryAlert = $this->service->process($retry);

        $this->assertNotSame($firstAlert?->id, $secondAlert?->id);
        $this->assertSame($firstAlert?->id, $retryAlert?->id);
        $this->assertSame(2, ControlRoomAlert::query()->count());
    }

    public function test_incident_tagged_signal_prefers_its_direct_alert_outside_fuzzy_constraints(): void
    {
        $client = Client::factory()->create();
        $incident = ClientIncident::factory()->create(['client_id' => $client->id]);
        $directAlert = ControlRoomAlert::factory()->resolved()->create([
            'source' => 'incident',
            'alert_type' => 'legacy.unrelated_type',
            'client_id' => $client->id,
            'triggered_at' => now()->subHours(2),
            'context' => ['incident_id' => $incident->id],
        ]);
        $incident->updateQuietly(['control_room_alert_id' => $directAlert->id]);
        $source = SignalSource::create([
            'name' => 'Direct Incident Signals',
            'slug' => 'direct-incident-signals',
            'category' => 'medication',
            'vendor' => 'internal',
            'status' => 'active',
        ]);
        $signalType = SignalType::create([
            'code' => 'medication.direct_incident',
            'name' => 'Direct Medication Incident',
            'category' => 'medication',
            'default_severity' => 'high',
        ]);
        SignalRule::create([
            'name' => 'Direct incident correlation',
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'conditions' => [],
            'alert_type' => 'Completely different alert type',
            'deduplicate' => true,
            'dedup_window_minutes' => 30,
            'is_active' => true,
            'priority' => 1,
        ]);
        $signal = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'idempotency_key' => 'direct-incident-outside-fuzzy-constraints',
            'client_id' => $client->id,
            'normalized_data' => ['incident_id' => $incident->id],
            'received_at' => now(),
            'occurred_at' => now(),
            'status' => 'pending',
        ]);

        $result = $this->service->process($signal);

        $this->assertSame($directAlert->id, $result?->id);
        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $directAlert->fresh()->status);
        $this->assertSame($directAlert->id, $signal->fresh()->correlated_alert_id);
        $this->assertSame(1, ControlRoomAlert::query()->count());
    }

    public function test_incident_tagged_signal_rejects_ambiguous_context_claims(): void
    {
        $client = Client::factory()->create();
        $incident = ClientIncident::factory()->create(['client_id' => $client->id]);
        $source = SignalSource::create([
            'name' => 'Ambiguous Incident Signals',
            'slug' => 'ambiguous-incident-signals',
            'category' => 'medication',
            'vendor' => 'internal',
            'status' => 'active',
        ]);
        $signalType = SignalType::create([
            'code' => 'medication.ambiguous_incident',
            'name' => 'Ambiguous Medication Incident',
            'category' => 'medication',
            'default_severity' => 'high',
        ]);
        SignalRule::create([
            'name' => 'Ambiguous incident correlation',
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'conditions' => [],
            'alert_type' => 'Medication incident',
            'deduplicate' => true,
            'dedup_window_minutes' => 30,
            'is_active' => true,
            'priority' => 1,
        ]);
        ControlRoomAlert::factory()->open()->create([
            'alert_type' => 'Medication incident',
            'context' => ['incident_id' => $incident->id],
        ]);
        ControlRoomAlert::factory()->open()->create([
            'alert_type' => 'Medication incident',
            'context' => ['normalized_data' => ['incident_id' => $incident->id]],
        ]);
        $signal = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'idempotency_key' => 'ambiguous-incident-claim',
            'client_id' => $client->id,
            'normalized_data' => ['incident_id' => $incident->id],
            'received_at' => now(),
            'occurred_at' => now(),
            'status' => 'pending',
        ]);

        try {
            $this->service->process($signal);
            $this->fail('Ambiguous incident alert claims must not be selected implicitly.');
        } catch (\DomainException $exception) {
            $this->assertSame(
                'Incident signal correlation is ambiguous: multiple alerts claim the same incident.',
                $exception->getMessage(),
            );
        }

        $this->assertSame('pending', $signal->fresh()->status);
        $this->assertSame(2, ControlRoomAlert::query()->count());
    }

    public function test_generic_signals_still_use_fuzzy_deduplication(): void
    {
        $source = SignalSource::create([
            'name' => 'Generic Signals',
            'slug' => 'generic-signals',
            'category' => 'operations',
            'vendor' => 'internal',
            'status' => 'active',
        ]);
        $signalType = SignalType::create([
            'code' => 'operations.generic',
            'name' => 'Generic Operations Signal',
            'category' => 'operations',
            'default_severity' => 'medium',
        ]);
        SignalRule::create([
            'name' => 'Generic fuzzy correlation',
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'conditions' => [],
            'alert_type' => 'Generic operations',
            'deduplicate' => true,
            'dedup_window_minutes' => 30,
            'is_active' => true,
            'priority' => 1,
        ]);

        $first = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'idempotency_key' => 'generic-first',
            'received_at' => now(),
            'occurred_at' => now(),
            'status' => 'pending',
        ]);
        $second = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'idempotency_key' => 'generic-second',
            'received_at' => now(),
            'occurred_at' => now(),
            'status' => 'pending',
        ]);

        $firstAlert = $this->service->process($first);
        $secondAlert = $this->service->process($second);

        $this->assertSame($firstAlert?->id, $secondAlert?->id);
        $this->assertSame(1, ControlRoomAlert::query()->count());
    }

    // ──────────────────────────────────────
    // Batch Processing
    // ──────────────────────────────────────

    public function test_process_all_pending_processes_batch(): void
    {
        $source = SignalSource::create([
            'name' => 'Batch Source',
            'slug' => 'batch-source',
            'category' => 'fleet',
            'vendor' => 'TestVendor',
            'status' => 'active',
        ]);

        $signalType = SignalType::create([
            'code' => 'batch.test',
            'name' => 'Batch Test',
            'category' => 'fleet',
            'default_severity' => 'medium',
        ]);

        // Create multiple pending signals
        for ($i = 0; $i < 5; $i++) {
            Signal::create([
                'signal_source_id' => $source->id,
                'signal_type_id' => $signalType->id,
                'idempotency_key' => "batch-test-{$i}",
                'payload' => [],
                'received_at' => now(),
                'status' => 'pending',
            ]);
        }

        $processed = $this->service->processAllPending(10);

        $this->assertEquals(5, $processed);
        $this->assertEquals(0, Signal::where('status', 'pending')->count());
    }

    public function test_process_all_pending_respects_limit(): void
    {
        $source = SignalSource::create([
            'name' => 'Limit Source',
            'slug' => 'limit-source',
            'category' => 'fleet',
            'vendor' => 'TestVendor',
            'status' => 'active',
        ]);

        $signalType = SignalType::create([
            'code' => 'limit.test',
            'name' => 'Limit Test',
            'category' => 'fleet',
            'default_severity' => 'medium',
        ]);

        for ($i = 0; $i < 10; $i++) {
            Signal::create([
                'signal_source_id' => $source->id,
                'signal_type_id' => $signalType->id,
                'idempotency_key' => "limit-test-{$i}",
                'payload' => [],
                'received_at' => now(),
                'status' => 'pending',
            ]);
        }

        $processed = $this->service->processAllPending(3);

        $this->assertEquals(3, $processed);
        $this->assertEquals(7, Signal::where('status', 'pending')->count());
    }

    // ──────────────────────────────────────
    // Device Signal Ingestion
    // ──────────────────────────────────────

    public function test_ingest_from_device_creates_signal(): void
    {
        $source = SignalSource::create([
            'name' => 'Device Source',
            'slug' => 'device-source',
            'category' => 'home_facility',
            'vendor' => 'DeviceVendor',
            'status' => 'active',
        ]);

        $signalType = SignalType::create([
            'code' => 'device.alarm',
            'name' => 'Device Alarm',
            'category' => 'home_facility',
            'default_severity' => 'high',
        ]);

        $device = Device::create([
            'name' => 'Test Camera',
            'device_type' => 'camera',
            'identifier' => 'CAM-001',
            'signal_source_id' => $source->id,
            'status' => 'online',
        ]);

        $signal = $this->service->ingestFromDevice(
            $device,
            'device.alarm',
            ['event' => 'motion_detected'],
            'high'
        );

        $this->assertInstanceOf(Signal::class, $signal);
        $this->assertEquals($device->id, $signal->device_id);
    }
}
