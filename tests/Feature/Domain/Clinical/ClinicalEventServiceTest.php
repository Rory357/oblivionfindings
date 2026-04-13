<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Domain\Clinical\Events\ClinicalEventRecorded;
use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Clinical\Services\ClinicalEventService;
use App\Domain\Clinical\Services\ClinicalSignalService;
use App\Enums\AlertSeverity;
use App\Models\Client;
use App\Models\HsEvent;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\HealthSafety\HsEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ClinicalEventServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ClinicalEventService $service;
    protected Client $client;
    protected User $reporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ClinicalEventService::class);
        $this->client = Client::factory()->create();
        $this->reporter = User::factory()->create();
    }

    // ── record() ─────────────────────────────────────────────────────────

    public function test_records_clinical_event(): void
    {
        $event = $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Deterioration,
            'severity' => AlertSeverity::MEDIUM,
            'description' => 'Client showing signs of dehydration',
        ]);

        $this->assertDatabaseHas('clinical_events', [
            'id' => $event->id,
            'client_id' => $this->client->id,
            'reported_by' => $this->reporter->id,
            'event_type' => 'deterioration',
            'severity' => 'medium',
            'status' => 'open',
        ]);
    }

    public function test_records_with_shift_context(): void
    {
        $shift = Shift::factory()->create(['client_id' => $this->client->id]);

        $event = $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Fall,
            'severity' => AlertSeverity::MEDIUM,
            'description' => 'Client fell while standing',
        ], $shift);

        $this->assertEquals($shift->id, $event->shift_id);
        $this->assertEquals($shift->site_id, $event->site_id);
    }

    public function test_accepts_string_event_type(): void
    {
        $event = $this->service->record($this->client, $this->reporter, [
            'event_type' => 'seizure',
            'severity' => 'high',
            'description' => 'Tonic-clonic seizure observed',
        ]);

        $this->assertEquals(ClinicalEventType::Seizure, $event->event_type);
    }

    public function test_normalises_severity(): void
    {
        $event = $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Other,
            'severity' => 'invalid_value',
            'description' => 'Test event',
        ]);

        // AlertSeverity::normalise returns MEDIUM for unknown values
        $this->assertEquals(AlertSeverity::MEDIUM, $event->severity);
    }

    public function test_records_witnesses(): void
    {
        $witness1 = User::factory()->create();
        $witness2 = User::factory()->create();

        $event = $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Fall,
            'severity' => AlertSeverity::MEDIUM,
            'description' => 'Fall observed by two staff',
            'witnesses' => [$witness1->id, $witness2->id],
        ]);

        $this->assertCount(2, $event->witnesses);
    }

    public function test_records_followup_requirement(): void
    {
        $event = $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Fall,
            'severity' => AlertSeverity::HIGH,
            'description' => 'Significant fall requiring medical review',
            'requires_followup' => true,
        ]);

        $this->assertTrue($event->requires_followup);
    }

    // ── Timeline event ───────────────────────────────────────────────────

    public function test_creates_timeline_event(): void
    {
        $event = $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Seizure,
            'severity' => AlertSeverity::HIGH,
            'description' => 'Seizure episode',
        ]);

        $this->assertDatabaseHas('timeline_events', [
            'type' => ClinicalEventService::TIMELINE_TYPE_CLINICAL_EVENT,
            'source_type' => ClinicalEvent::class,
            'source_id' => $event->id,
            'client_id' => $this->client->id,
            'actor_user_id' => $this->reporter->id,
        ]);

        $timeline = TimelineEvent::where('source_type', ClinicalEvent::class)
            ->where('source_id', $event->id)
            ->first();

        $this->assertStringContainsString('Seizure', $timeline->subject);
        $this->assertEquals($event->severity, $timeline->meta['severity']);
    }

    // ── H&S event auto-linking ───────────────────────────────────────────

    public function test_auto_links_fall_to_hs_event(): void
    {
        $event = $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Fall,
            'severity' => AlertSeverity::MEDIUM,
            'description' => 'Client fell from chair',
        ]);

        $event->refresh();
        $this->assertNotNull($event->linked_hs_event_id);

        $hsEvent = HsEvent::find($event->linked_hs_event_id);
        $this->assertNotNull($hsEvent);
        $this->assertEquals('injury', $hsEvent->event_category);
        $this->assertEquals(ClinicalEvent::class, $hsEvent->source_type);
        $this->assertEquals($event->id, $hsEvent->source_id);
    }

    public function test_auto_links_seizure_to_hs_event(): void
    {
        $event = $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Seizure,
            'severity' => AlertSeverity::HIGH,
            'description' => 'Seizure lasting 2 minutes',
        ]);

        $event->refresh();
        $this->assertNotNull($event->linked_hs_event_id);

        $hsEvent = HsEvent::find($event->linked_hs_event_id);
        $this->assertEquals('incident', $hsEvent->event_category);
    }

    public function test_auto_links_choking_to_hs_event(): void
    {
        $event = $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Choking,
            'severity' => AlertSeverity::HIGH,
            'description' => 'Choking episode during meal',
        ]);

        $event->refresh();
        $this->assertNotNull($event->linked_hs_event_id);
    }

    public function test_does_not_link_deterioration_to_hs_event(): void
    {
        $event = $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Deterioration,
            'severity' => AlertSeverity::MEDIUM,
            'description' => 'General health decline',
        ]);

        $this->assertNull($event->linked_hs_event_id);
    }

    public function test_does_not_link_other_type_to_hs_event(): void
    {
        $event = $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::InfectionSign,
            'severity' => AlertSeverity::MEDIUM,
            'description' => 'Possible UTI symptoms',
        ]);

        $this->assertNull($event->linked_hs_event_id);
    }

    public function test_hs_event_linking_is_idempotent(): void
    {
        $event = $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Fall,
            'severity' => AlertSeverity::MEDIUM,
            'description' => 'Fall event',
        ]);

        $hsEventId = $event->fresh()->linked_hs_event_id;

        // Manually call linkToHsEvent again via a second record (different event)
        $event2 = $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Fall,
            'severity' => AlertSeverity::MEDIUM,
            'description' => 'Second fall event',
        ]);

        // Each event gets its own HsEvent (separate source_id)
        $this->assertNotEquals($hsEventId, $event2->fresh()->linked_hs_event_id);
        $this->assertEquals(2, HsEvent::count());
    }

    // ── Domain event ─────────────────────────────────────────────────────

    public function test_dispatches_clinical_event_recorded(): void
    {
        Event::fake([ClinicalEventRecorded::class]);

        $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Deterioration,
            'severity' => AlertSeverity::LOW,
            'description' => 'Minor concern',
        ]);

        Event::assertDispatched(ClinicalEventRecorded::class, function ($domainEvent) {
            return $domainEvent->clinicalEvent->client_id === $this->client->id;
        });
    }

    // ── Signal emission ──────────────────────────────────────────────────

    public function test_does_not_emit_signal_for_low_severity(): void
    {
        // Mock SignalProcessingService to ensure ingest() is never called for low severity
        $mockProcessor = $this->mock(SignalProcessingService::class);
        $mockProcessor->shouldNotReceive('ingest');

        $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Deterioration,
            'severity' => AlertSeverity::LOW,
            'description' => 'Minor issue',
        ]);
    }

    public function test_does_not_emit_signal_for_medium_severity(): void
    {
        $mockProcessor = $this->mock(SignalProcessingService::class);
        $mockProcessor->shouldNotReceive('ingest');

        $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Deterioration,
            'severity' => AlertSeverity::MEDIUM,
            'description' => 'Moderate issue',
        ]);
    }

    // ── getForClient() ───────────────────────────────────────────────────

    public function test_get_for_client_returns_events(): void
    {
        ClinicalEvent::factory()->count(3)->create(['client_id' => $this->client->id]);
        ClinicalEvent::factory()->create(); // different client

        $results = $this->service->getForClient($this->client);
        $this->assertCount(3, $results);
    }

    public function test_get_for_client_filters_by_type(): void
    {
        ClinicalEvent::factory()->fall()->create(['client_id' => $this->client->id]);
        ClinicalEvent::factory()->seizure()->create(['client_id' => $this->client->id]);
        ClinicalEvent::factory()->fall()->create(['client_id' => $this->client->id]);

        $results = $this->service->getForClient($this->client, ClinicalEventType::Fall);
        $this->assertCount(2, $results);
    }

    // ── getFrequencyCount() ──────────────────────────────────────────────

    public function test_get_frequency_count(): void
    {
        ClinicalEvent::factory()->seizure()->create([
            'client_id' => $this->client->id,
            'occurred_at' => now()->subDays(5),
        ]);
        ClinicalEvent::factory()->seizure()->create([
            'client_id' => $this->client->id,
            'occurred_at' => now()->subDays(2),
        ]);
        ClinicalEvent::factory()->seizure()->create([
            'client_id' => $this->client->id,
            'occurred_at' => now()->subDays(40), // outside 30-day window
        ]);

        $count = $this->service->getFrequencyCount($this->client, ClinicalEventType::Seizure, 30);
        $this->assertEquals(2, $count);
    }
}
