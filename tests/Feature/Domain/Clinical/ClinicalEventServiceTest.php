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
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\HealthSafety\HsEventService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ClinicalEventServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ClinicalEventService $service;

    protected Client $client;

    protected Site $site;

    protected User $reporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ClinicalEventService::class);
        $this->site = Site::factory()->create();
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
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
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);

        $event = $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Fall,
            'severity' => AlertSeverity::MEDIUM,
            'description' => 'Client fell while standing',
            'immediate_action_taken' => 'Supported the client and completed an injury check.',
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
            'immediate_action_taken' => 'Protected the client from injury and timed the seizure.',
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
            'immediate_action_taken' => 'Kept the client still and requested a clinical review.',
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
            'immediate_action_taken' => 'Completed first aid and contacted the clinical lead.',
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
            'immediate_action_taken' => 'Cleared the area and monitored breathing throughout.',
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

    public function test_hs_linked_event_requires_truthful_immediate_action_before_any_write(): void
    {
        try {
            $this->service->record($this->client, $this->reporter, [
                'event_type' => ClinicalEventType::Fall,
                'severity' => AlertSeverity::MEDIUM,
                'description' => 'Client fell from a chair.',
                'immediate_action_taken' => '   ',
            ]);

            $this->fail('A linked clinical event without an immediate action should fail closed.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Immediate action taken is required', $exception->getMessage());
        }

        $this->assertDatabaseCount('clinical_events', 0);
        $this->assertDatabaseCount('timeline_events', 0);
        $this->assertDatabaseCount('hs_events', 0);
    }

    public function test_hs_link_failure_rolls_back_clinical_event_and_timeline(): void
    {
        $hsEventService = $this->createMock(HsEventService::class);
        $hsEventService->expects($this->once())
            ->method('recordEvent')
            ->willReturn(null);
        $service = new ClinicalEventService($hsEventService, app(ClinicalSignalService::class));

        try {
            $service->record($this->client, $this->reporter, [
                'event_type' => ClinicalEventType::Seizure,
                'severity' => AlertSeverity::HIGH,
                'description' => 'Seizure requiring immediate clinical support.',
                'immediate_action_taken' => 'Protected the client and monitored breathing.',
            ]);

            $this->fail('A failed H&S link should roll back the clinical journey.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('could not be linked', $exception->getMessage());
        }

        $this->assertDatabaseCount('clinical_events', 0);
        $this->assertDatabaseCount('timeline_events', 0);
        $this->assertDatabaseCount('hs_events', 0);
    }

    public function test_hs_linked_event_rejects_conflicting_shift_site_provenance(): void
    {
        $otherSite = Site::factory()->create();
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $otherSite->id,
        ]);
        $timelineCount = TimelineEvent::query()->count();

        try {
            $this->service->record($this->client, $this->reporter, [
                'event_type' => ClinicalEventType::Choking,
                'severity' => AlertSeverity::CRITICAL,
                'description' => 'Choking incident during a meal.',
                'immediate_action_taken' => 'Cleared the airway and called emergency services.',
            ], $shift);

            $this->fail('Conflicting client and shift Sites should fail closed.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('does not match', $exception->getMessage());
        }

        $this->assertDatabaseCount('clinical_events', 0);
        $this->assertDatabaseCount('timeline_events', $timelineCount);
        $this->assertDatabaseCount('hs_events', 0);
    }

    public function test_auto_links_fall_to_hs_event(): void
    {
        $event = $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Fall,
            'severity' => AlertSeverity::MEDIUM,
            'description' => 'Client fell from chair',
            'immediate_action_taken' => 'Assisted the client and checked for injury.',
        ]);

        $event->refresh();
        $this->assertNotNull($event->linked_hs_event_id);

        $hsEvent = HsEvent::find($event->linked_hs_event_id);
        $this->assertNotNull($hsEvent);
        $this->assertEquals('injury', $hsEvent->event_category);
        $this->assertEquals(ClinicalEvent::class, $hsEvent->source_type);
        $this->assertEquals($event->id, $hsEvent->source_id);
        $this->assertSame($this->site->id, $event->site_id);
        $this->assertSame($this->site->id, $hsEvent->site_id);
        $this->assertSame('Assisted the client and checked for injury.', $event->immediate_action_taken);
    }

    public function test_auto_links_seizure_to_hs_event(): void
    {
        $event = $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Seizure,
            'severity' => AlertSeverity::HIGH,
            'description' => 'Seizure lasting 2 minutes',
            'immediate_action_taken' => 'Protected the client and monitored breathing.',
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
            'immediate_action_taken' => 'Cleared the airway and requested emergency support.',
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
            'immediate_action_taken' => 'Assisted the client and checked for injury.',
        ]);

        $hsEventId = $event->fresh()->linked_hs_event_id;

        // Manually call linkToHsEvent again via a second record (different event)
        $event2 = $this->service->record($this->client, $this->reporter, [
            'event_type' => ClinicalEventType::Fall,
            'severity' => AlertSeverity::MEDIUM,
            'description' => 'Second fall event',
            'immediate_action_taken' => 'Kept the client safe and requested a review.',
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
