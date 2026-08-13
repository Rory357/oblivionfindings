<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Enums\News2Band;
use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Services\ClinicalDashboardService;
use App\Domain\Clinical\Services\ClinicalObservationService;
use App\Domain\Clinical\Services\ClinicalSignalService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NEWS2 is computed on write for vitals and stored on the observation, and a
 * Medium/High band raises a deterioration signal + lands the client on the watch.
 */
class News2ObservationTest extends TestCase
{
    use RefreshDatabase;

    protected ClinicalObservationService $service;

    protected Client $client;

    protected User $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ClinicalObservationService::class);
        $site = Site::factory()->create(['is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $site->id]);
        $this->recorder = User::factory()->create();
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->recorder->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function vitals(array $overrides = []): array
    {
        return array_merge([
            'systolic' => 120,
            'diastolic' => 80,
            'pulse' => 70,
            'respiration_rate' => 16,
            'o2_saturation' => 98,
            'temperature' => 36.5,
            'consciousness' => 'A',
        ], $overrides);
    }

    public function test_vitals_records_news2_score_and_band(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => $this->vitals(['respiration_rate' => 22, 'o2_saturation' => 93, 'pulse' => 95]),
        ]);

        $observation->refresh();

        $this->assertSame(5, $observation->news2_score);
        $this->assertSame(News2Band::Medium, $observation->news2_band);

        $this->assertDatabaseHas('clinical_observations', [
            'id' => $observation->id,
            'news2_score' => 5,
            'news2_band' => 'medium',
        ]);
    }

    public function test_non_vitals_observation_has_no_news2(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Weight,
            'data' => ['weight_kg' => 80],
        ]);

        $this->assertNull($observation->news2_score);
        $this->assertNull($observation->news2_band);
    }

    public function test_incomplete_vitals_store_no_news2(): void
    {
        // systolic/diastolic/pulse are the only required keys; without resp rate /
        // SpO2 / temperature NEWS2 cannot be computed, so no score is stored.
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => ['systolic' => 120, 'diastolic' => 80, 'pulse' => 70],
        ]);

        $this->assertNull($observation->news2_score);
        $this->assertNull($observation->news2_band);
    }

    public function test_high_news2_emits_deterioration_signal(): void
    {
        $signals = $this->mock(ClinicalSignalService::class);
        $signals->shouldReceive('emitForDeterioration')->once();

        app(ClinicalObservationService::class)->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => $this->vitals(['respiration_rate' => 26, 'o2_saturation' => 90, 'systolic' => 88]),
        ]);
    }

    public function test_low_news2_does_not_emit_deterioration_signal(): void
    {
        $signals = $this->mock(ClinicalSignalService::class);
        $signals->shouldNotReceive('emitForDeterioration');

        app(ClinicalObservationService::class)->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => $this->vitals(),
        ]);
    }

    public function test_clients_on_watch_counts_latest_band(): void
    {
        // High band → on watch.
        $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => $this->vitals(['respiration_rate' => 26, 'o2_saturation' => 90, 'systolic' => 88]),
        ]);

        // A second client recording normal vitals is NOT on watch.
        $stable = Client::factory()->create(['site_id' => $this->client->site_id]);
        $this->service->record($stable, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => $this->vitals(),
        ]);

        $kpis = app(ClinicalDashboardService::class)->getKpis($this->recorder);
        $this->assertSame(1, $kpis['clients_on_watch']);
    }

    public function test_clients_on_watch_uses_only_the_latest_observation(): void
    {
        // An earlier high reading followed by a later normal reading → not on watch.
        $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => $this->vitals(['respiration_rate' => 26, 'o2_saturation' => 90, 'systolic' => 88]),
            'recorded_at' => now()->subHours(4)->toDateTimeString(),
        ]);
        $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => $this->vitals(),
            'recorded_at' => now()->toDateTimeString(),
        ]);

        $kpis = app(ClinicalDashboardService::class)->getKpis($this->recorder);
        $this->assertSame(0, $kpis['clients_on_watch']);
    }
}
