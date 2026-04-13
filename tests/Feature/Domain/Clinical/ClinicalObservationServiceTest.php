<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Events\ObservationRecorded;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Domain\Clinical\Services\ClinicalObservationService;
use App\Models\Client;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClinicalObservationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ClinicalObservationService $service;
    protected Client $client;
    protected User $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ClinicalObservationService::class);
        $this->client = Client::factory()->create();
        $this->recorder = User::factory()->create();
    }

    // ── record() ─────────────────────────────────────────────────────────

    public function test_records_vitals_observation(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => ['systolic' => 120, 'diastolic' => 80, 'pulse' => 72, 'temperature' => 36.8],
        ]);

        $this->assertDatabaseHas('clinical_observations', [
            'id' => $observation->id,
            'client_id' => $this->client->id,
            'recorded_by' => $this->recorder->id,
            'observation_type' => 'vitals',
        ]);
        $this->assertEquals(120, $observation->data['systolic']);
    }

    public function test_records_weight_observation(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Weight,
            'data' => ['weight_kg' => 72.5],
            'notes' => 'Before breakfast',
        ]);

        $this->assertEquals(ObservationType::Weight, $observation->observation_type);
        $this->assertEquals(72.5, $observation->data['weight_kg']);
        $this->assertEquals('Before breakfast', $observation->notes);
    }

    public function test_records_bowel_observation(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => 'bowel',
            'data' => ['bristol_type' => 4],
        ]);

        $this->assertEquals(ObservationType::Bowel, $observation->observation_type);
    }

    public function test_records_sleep_observation(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Sleep,
            'data' => ['bed_time' => '22:00', 'wake_time' => '07:00', 'quality' => 'good'],
        ]);

        $this->assertEquals('good', $observation->data['quality']);
    }

    public function test_records_fluid_intake_observation(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::FluidIntake,
            'data' => ['amount_ml' => 250, 'fluid_type' => 'water'],
        ]);

        $this->assertEquals(250, $observation->data['amount_ml']);
    }

    public function test_records_pain_observation(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Pain,
            'data' => ['score' => 6, 'location' => 'lower back'],
        ]);

        $this->assertEquals(6, $observation->data['score']);
    }

    public function test_records_general_observation(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::General,
            'data' => [],
            'notes' => 'Client appeared well today',
        ]);

        $this->assertEquals(ObservationType::General, $observation->observation_type);
    }

    public function test_accepts_string_observation_type(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => 'weight',
            'data' => ['weight_kg' => 70],
        ]);

        $this->assertEquals(ObservationType::Weight, $observation->observation_type);
    }

    // ── Shift context ────────────────────────────────────────────────────

    public function test_records_with_shift_context(): void
    {
        $shift = Shift::factory()->create(['client_id' => $this->client->id]);

        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => ['systolic' => 120, 'diastolic' => 80, 'pulse' => 72],
        ], $shift);

        $this->assertEquals($shift->id, $observation->shift_id);
        $this->assertEquals($shift->site_id, $observation->site_id);
    }

    public function test_records_without_shift_uses_client_site(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Weight,
            'data' => ['weight_kg' => 70],
        ]);

        $this->assertNull($observation->shift_id);
        $this->assertEquals($this->client->site_id, $observation->site_id);
    }

    // ── Timeline event ───────────────────────────────────────────────────

    public function test_creates_timeline_event(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => ['systolic' => 130, 'diastolic' => 85, 'pulse' => 80],
        ]);

        $this->assertDatabaseHas('timeline_events', [
            'type' => ClinicalObservationService::TIMELINE_TYPE_OBSERVATION,
            'source_type' => ClinicalObservation::class,
            'source_id' => $observation->id,
            'client_id' => $this->client->id,
            'actor_user_id' => $this->recorder->id,
        ]);
    }

    public function test_timeline_body_includes_vital_signs_summary(): void
    {
        $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => ['systolic' => 130, 'diastolic' => 85, 'pulse' => 80, 'o2_saturation' => 98],
        ]);

        $timeline = TimelineEvent::where('type', ClinicalObservationService::TIMELINE_TYPE_OBSERVATION)->first();
        $this->assertStringContainsString('BP 130/85', $timeline->body);
        $this->assertStringContainsString('Pulse 80', $timeline->body);
    }

    public function test_timeline_body_includes_weight_summary(): void
    {
        $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Weight,
            'data' => ['weight_kg' => 72.5],
        ]);

        $timeline = TimelineEvent::where('type', ClinicalObservationService::TIMELINE_TYPE_OBSERVATION)->first();
        $this->assertStringContainsString('72.5 kg', $timeline->body);
    }

    // ── Protocol schedule completion ─────────────────────────────────────

    public function test_completes_protocol_schedule_when_provided(): void
    {
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->client->id,
        ]);
        $schedule = ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => now(),
            'status' => 'pending',
        ]);

        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Weight,
            'data' => ['weight_kg' => 70],
            'protocol_schedule_id' => $schedule->id,
        ]);

        $schedule->refresh();
        $this->assertEquals('completed', $schedule->status);
        $this->assertEquals($this->recorder->id, $schedule->completed_by);
        $this->assertEquals($observation->id, $schedule->clinical_observation_id);
    }

    public function test_does_not_complete_already_completed_schedule(): void
    {
        $schedule = ClinicalProtocolSchedule::factory()->completed()->create();
        $originalCompletedBy = $schedule->completed_by;

        $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Weight,
            'data' => ['weight_kg' => 70],
            'protocol_schedule_id' => $schedule->id,
        ]);

        $schedule->refresh();
        $this->assertEquals($originalCompletedBy, $schedule->completed_by);
    }

    // ── Domain event ─────────────────────────────────────────────────────

    public function test_dispatches_observation_recorded_event(): void
    {
        Event::fake([ObservationRecorded::class]);

        $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Weight,
            'data' => ['weight_kg' => 70],
        ]);

        Event::assertDispatched(ObservationRecorded::class, function ($event) {
            return $event->observation->client_id === $this->client->id;
        });
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_rejects_vitals_missing_required_fields(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => ['systolic' => 120], // missing diastolic and pulse
        ]);
    }

    public function test_rejects_weight_missing_weight_kg(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Weight,
            'data' => ['notes' => 'forgot to weigh'],
        ]);
    }

    public function test_allows_general_observation_with_empty_data(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::General,
            'data' => [],
            'notes' => 'Client settled well',
        ]);

        $this->assertNotNull($observation->id);
    }

    // ── getLatest() ──────────────────────────────────────────────────────

    public function test_get_latest_returns_recent_observations(): void
    {
        ClinicalObservation::factory()->count(5)->create([
            'client_id' => $this->client->id,
        ]);
        ClinicalObservation::factory()->create(); // different client

        $results = $this->service->getLatest($this->client);
        $this->assertCount(5, $results);
    }

    public function test_get_latest_filters_by_type(): void
    {
        ClinicalObservation::factory()->vitals()->create(['client_id' => $this->client->id]);
        ClinicalObservation::factory()->weight()->create(['client_id' => $this->client->id]);
        ClinicalObservation::factory()->vitals()->create(['client_id' => $this->client->id]);

        $results = $this->service->getLatest($this->client, ObservationType::Vitals);
        $this->assertCount(2, $results);
    }

    // ── getTrends() ──────────────────────────────────────────────────────

    public function test_get_trends_returns_data_within_range(): void
    {
        ClinicalObservation::factory()->weight()->create([
            'client_id' => $this->client->id,
            'recorded_at' => now()->subDays(3),
        ]);
        ClinicalObservation::factory()->weight()->create([
            'client_id' => $this->client->id,
            'recorded_at' => now()->subDays(1),
        ]);
        ClinicalObservation::factory()->weight()->create([
            'client_id' => $this->client->id,
            'recorded_at' => now()->subDays(10),
        ]);

        $results = $this->service->getTrends(
            $this->client,
            ObservationType::Weight,
            now()->subDays(7),
            now(),
        );

        $this->assertCount(2, $results);
    }
}
