<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Domain\Clinical\Services\ClinicalHealthSummaryService;
use App\Enums\AlertSeverity;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClinicalHealthSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ClinicalHealthSummaryService $service;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ClinicalHealthSummaryService::class);
        $this->client = Client::factory()->create();
    }

    // ── getSummary() ─────────────────────────────────────────────────────

    public function test_returns_complete_summary_structure(): void
    {
        $summary = $this->service->getSummary($this->client);

        $this->assertArrayHasKey('latest_observations', $summary);
        $this->assertArrayHasKey('recent_events', $summary);
        $this->assertArrayHasKey('protocol_compliance', $summary);
    }

    // ── getLatestObservations() ──────────────────────────────────────────

    public function test_returns_latest_observation_per_type(): void
    {
        ClinicalObservation::factory()->vitals()->create([
            'client_id' => $this->client->id,
            'recorded_at' => now()->subDays(2),
            'data' => ['systolic' => 110, 'diastolic' => 70, 'pulse' => 65],
        ]);
        ClinicalObservation::factory()->vitals()->create([
            'client_id' => $this->client->id,
            'recorded_at' => now()->subHour(),
            'data' => ['systolic' => 120, 'diastolic' => 80, 'pulse' => 72],
        ]);
        ClinicalObservation::factory()->weight()->create([
            'client_id' => $this->client->id,
            'data' => ['weight_kg' => 72.5],
        ]);

        $latest = $this->service->getLatestObservations($this->client);

        // Vitals should be the most recent one
        $this->assertNotNull($latest['vitals']);
        $this->assertEquals(120, $latest['vitals']['data']['systolic']);

        // Weight present
        $this->assertNotNull($latest['weight']);
        $this->assertEquals(72.5, $latest['weight']['data']['weight_kg']);

        // Bowel not recorded, should be null
        $this->assertNull($latest['bowel']);
    }

    public function test_returns_null_for_all_types_when_no_observations(): void
    {
        $latest = $this->service->getLatestObservations($this->client);

        foreach (ObservationType::cases() as $type) {
            $this->assertNull($latest[$type->value], "Expected null for {$type->value}");
        }
    }

    public function test_excludes_other_client_observations(): void
    {
        $otherClient = Client::factory()->create();
        ClinicalObservation::factory()->weight()->create([
            'client_id' => $otherClient->id,
        ]);

        $latest = $this->service->getLatestObservations($this->client);
        $this->assertNull($latest['weight']);
    }

    // ── getRecentEvents() ────────────────────────────────────────────────

    public function test_returns_recent_events_within_30_days(): void
    {
        ClinicalEvent::factory()->fall()->create([
            'client_id' => $this->client->id,
            'occurred_at' => now()->subDays(5),
        ]);
        ClinicalEvent::factory()->seizure()->create([
            'client_id' => $this->client->id,
            'occurred_at' => now()->subDays(10),
        ]);
        ClinicalEvent::factory()->create([
            'client_id' => $this->client->id,
            'occurred_at' => now()->subDays(40), // outside 30-day window
        ]);

        $events = $this->service->getRecentEvents($this->client);

        $this->assertEquals(2, $events['count']);
        $this->assertCount(2, $events['items']);
    }

    public function test_counts_high_severity_events(): void
    {
        ClinicalEvent::factory()->create([
            'client_id' => $this->client->id,
            'severity' => AlertSeverity::HIGH,
            'occurred_at' => now()->subDay(),
        ]);
        ClinicalEvent::factory()->create([
            'client_id' => $this->client->id,
            'severity' => AlertSeverity::LOW,
            'occurred_at' => now()->subDays(2),
        ]);

        $events = $this->service->getRecentEvents($this->client);

        $this->assertEquals(2, $events['count']);
        $this->assertEquals(1, $events['high_severity_count']);
    }

    public function test_returns_empty_events_when_none(): void
    {
        $events = $this->service->getRecentEvents($this->client);

        $this->assertEquals(0, $events['count']);
        $this->assertEquals(0, $events['high_severity_count']);
        $this->assertEmpty($events['items']);
    }

    public function test_event_items_include_expected_fields(): void
    {
        ClinicalEvent::factory()->fall()->create([
            'client_id' => $this->client->id,
            'occurred_at' => now()->subHour(),
        ]);

        $events = $this->service->getRecentEvents($this->client);

        $item = $events['items'][0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('event_type', $item);
        $this->assertArrayHasKey('event_type_label', $item);
        $this->assertArrayHasKey('severity', $item);
        $this->assertArrayHasKey('occurred_at', $item);
        $this->assertArrayHasKey('status', $item);
    }

    // ── getProtocolCompliance() ──────────────────────────────────────────

    public function test_returns_compliance_snapshot(): void
    {
        $protocol = ClinicalProtocol::factory()->create([
            'client_id' => $this->client->id,
        ]);

        ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => now()->addHour(),
            'status' => 'pending',
        ]);
        ClinicalProtocolSchedule::factory()->overdue()->create([
            'clinical_protocol_id' => $protocol->id,
        ]);

        $compliance = $this->service->getProtocolCompliance($this->client);

        $this->assertArrayHasKey('rate', $compliance);
        $this->assertArrayHasKey('due_count', $compliance);
        $this->assertArrayHasKey('overdue_count', $compliance);
        $this->assertEquals(2, $compliance['due_count']);
        $this->assertEquals(1, $compliance['overdue_count']);
    }

    public function test_returns_zero_percent_when_no_protocol_schedules_exist(): void
    {
        $compliance = $this->service->getProtocolCompliance($this->client);

        $this->assertEquals(0.0, $compliance['rate']);
        $this->assertEquals(0, $compliance['due_count']);
        $this->assertEquals(0, $compliance['overdue_count']);
    }
}
