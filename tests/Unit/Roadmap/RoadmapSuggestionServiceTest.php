<?php

namespace Tests\Unit\Roadmap;

use App\Domain\Roadmap\Models\InitiativeSuggestion;
use App\Domain\Roadmap\Services\RoadmapSuggestionService;
use App\Models\Asset;
use App\Models\ClientIncident;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RoadmapTestHelpers;
use Tests\TestCase;

class RoadmapSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;
    use RoadmapTestHelpers;

    protected RoadmapSuggestionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoadmapModule();
        $this->service = app(RoadmapSuggestionService::class);
    }

    public function test_upsert_creates_new_suggestion(): void
    {
        $suggestion = $this->service->upsertSuggestion([
            'source' => 'control_room',
            'source_key' => 'site-1',
            'title' => 'Repeated camera offline',
            'summary' => 'Camera offline has repeated above threshold.',
            'dedupe_key' => 'control:camera_offline:site-1:14d',
            'score_hint' => 65,
            'raw_payload' => ['count' => 12],
            'rate_limit_days' => 21,
        ]);

        $this->assertSame('triage_pending', $suggestion->status);
        $this->assertSame(1, $suggestion->hit_count);
        $this->assertDatabaseHas('roadmap_suggestions', [
            'id' => $suggestion->id,
            'source' => 'control_room',
            'dedupe_key' => 'control:camera_offline:site-1:14d',
        ]);
    }

    public function test_upsert_updates_existing_when_not_rate_limited(): void
    {
        $existing = InitiativeSuggestion::create([
            'source' => 'it_health',
            'title' => 'Initial title',
            'summary' => 'Initial summary',
            'dedupe_key' => 'it:unifi:downtime:site-1:14d',
            'status' => InitiativeSuggestion::STATUS_TRIAGE_PENDING,
            'first_seen_at' => now()->subDays(2),
            'last_seen_at' => now()->subDay(),
            'hit_count' => 1,
            'rate_limited_until' => now()->subMinute(),
        ]);

        $result = $this->service->upsertSuggestion([
            'source' => 'it_health',
            'title' => 'Updated title',
            'summary' => 'Updated summary',
            'dedupe_key' => 'it:unifi:downtime:site-1:14d',
            'score_hint' => 70,
            'raw_payload' => ['count' => 19],
            'rate_limit_days' => 21,
        ]);

        $this->assertSame($existing->id, $result->id);
        $result->refresh();

        $this->assertSame(2, $result->hit_count);
        $this->assertSame('Updated summary', $result->summary);
        $this->assertSame('70.00', (string) $result->score_hint);
    }

    public function test_upsert_keeps_existing_unchanged_when_rate_limited(): void
    {
        $existing = InitiativeSuggestion::create([
            'source' => 'fleet',
            'title' => 'Speeding spike',
            'summary' => 'Old summary',
            'dedupe_key' => 'fleet:speeding:30d',
            'status' => InitiativeSuggestion::STATUS_TRIAGE_PENDING,
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
            'hit_count' => 4,
            'rate_limited_until' => now()->addHour(),
        ]);

        $result = $this->service->upsertSuggestion([
            'source' => 'fleet',
            'title' => 'Speeding spike',
            'summary' => 'New summary should not apply yet',
            'dedupe_key' => 'fleet:speeding:30d',
            'score_hint' => 90,
            'raw_payload' => ['count' => 30],
        ]);

        $this->assertSame($existing->id, $result->id);
        $result->refresh();

        $this->assertSame(4, $result->hit_count);
        $this->assertSame('Old summary', $result->summary);
        $this->assertSame(30, (int) (($result->raw_payload ?? [])['count'] ?? 0));
    }

    public function test_incident_ingest_includes_notes_and_examples_in_payload(): void
    {
        ClientIncident::factory()->count(3)->create([
            'type' => 'fall',
            'severity' => 'high',
            'occurred_at' => now()->subDays(5),
            'description' => 'Client slipped near the bathroom doorway.',
            'review_notes' => 'Floor signage and anti-slip checks were incomplete.',
            'closed_notes' => 'Nightly hazard round now includes wet-floor checks.',
        ]);

        $created = $this->service->ingestIncidentClusters();

        $this->assertSame(1, $created);

        $suggestion = InitiativeSuggestion::query()
            ->where('source', 'incidents')
            ->where('source_key', 'fall')
            ->firstOrFail();

        $payload = $suggestion->raw_payload ?? [];
        $this->assertSame('fall', $payload['type'] ?? null);
        $this->assertNotEmpty($payload['incident_notes'] ?? []);
        $this->assertNotEmpty($payload['incident_examples'] ?? []);
    }

    public function test_asset_ingest_includes_notes_and_examples_in_payload(): void
    {
        $site = Site::factory()->create();

        Asset::factory()->count(2)->forSite($site)->create([
            'status' => 'active',
            'category' => 'Safety Equipment',
            'maintenance_due_at' => now()->subDays(40),
            'warranty_expires_at' => now()->addMonths(2),
            'description' => 'Service sticker is expired and unreadable.',
            'notes' => 'Inspection team flagged recurring compliance concerns.',
        ]);

        $created = $this->service->ingestAssetLifecycle();

        $this->assertSame(1, $created);

        $suggestion = InitiativeSuggestion::query()
            ->where('source', 'assets')
            ->where('source_key', (string) $site->id)
            ->firstOrFail();

        $payload = $suggestion->raw_payload ?? [];
        $this->assertSame('Safety Equipment', $payload['category'] ?? null);
        $this->assertGreaterThanOrEqual(2, (int) ($payload['maintenance_overdue_count'] ?? 0));
        $this->assertNotEmpty($payload['asset_notes'] ?? []);
        $this->assertNotEmpty($payload['asset_examples'] ?? []);
    }

    public function test_triage_rejects_invalid_status(): void
    {
        $suggestion = InitiativeSuggestion::create([
            'source' => 'incidents',
            'title' => 'Recurring falls',
            'dedupe_key' => 'incident:fall:high:90d',
            'status' => InitiativeSuggestion::STATUS_TRIAGE_PENDING,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'hit_count' => 1,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->triage($suggestion, 'bad_status');
    }

    public function test_triage_can_set_preserve_and_clear_notes(): void
    {
        $suggestion = InitiativeSuggestion::create([
            'source' => 'incidents',
            'title' => 'Recurring falls',
            'dedupe_key' => 'incident:fall:high:90d:notes',
            'status' => InitiativeSuggestion::STATUS_TRIAGE_PENDING,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'hit_count' => 1,
        ]);

        $this->service->triage(
            $suggestion,
            InitiativeSuggestion::STATUS_ACCEPTED,
            null,
            null,
            'Needs OT review and site hazard walkthrough.',
            true,
        );

        $suggestion->refresh();
        $this->assertSame(
            'Needs OT review and site hazard walkthrough.',
            $suggestion->triage_notes,
        );

        $this->service->triage($suggestion, InitiativeSuggestion::STATUS_REJECTED);
        $suggestion->refresh();
        $this->assertSame(
            'Needs OT review and site hazard walkthrough.',
            $suggestion->triage_notes,
        );

        $this->service->triage(
            $suggestion,
            InitiativeSuggestion::STATUS_TRIAGE_PENDING,
            null,
            null,
            null,
            true,
        );
        $suggestion->refresh();
        $this->assertNull($suggestion->triage_notes);
    }

    public function test_convert_to_initiative_uses_source_defaults_and_marks_converted(): void
    {
        $admin = $this->createAdminUser();

        $suggestion = InitiativeSuggestion::create([
            'source' => 'assets',
            'title' => 'Generator replacements',
            'summary' => 'Aging equipment across homes.',
            'dedupe_key' => 'asset:generator:quarter',
            'status' => InitiativeSuggestion::STATUS_ACCEPTED,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'hit_count' => 1,
        ]);

        $initiative = $this->service->convertToInitiative($suggestion, [], $admin->id);

        $this->assertSame('maintenance', $initiative->stream);
        $this->assertSame('maintenance', $initiative->category->key);

        $suggestion->refresh();
        $this->assertSame(InitiativeSuggestion::STATUS_CONVERTED, $suggestion->status);
        $this->assertSame($initiative->id, $suggestion->converted_initiative_id);
    }
}
