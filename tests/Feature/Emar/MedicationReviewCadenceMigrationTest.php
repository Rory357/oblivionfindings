<?php

namespace Tests\Feature\Emar;

use App\Models\AppSetting;
use App\Models\Client;
use App\Models\MedicationReview;
use App\Models\MedicationReviewEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Reviews\MedicationReviewCadence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationReviewCadenceMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->travelTo(CarbonImmutable::parse('2026-10-02T11:15:00Z'));
    }

    public function test_legacy_default_three_stays_null_and_follows_the_organisation_review_state(): void
    {
        ['client' => $client, 'review' => $review] = $this->legacyCadence(3);
        $bookedEvidence = $this->persistedEvidence($review);

        $this->migration()->up();

        $this->assertNull($client->fresh()->medication_review_interval_months);
        $this->assertSame(['months' => 3, 'own' => false, 'reviewed' => false], $this->cadence($client));
        AppSetting::query()->create(['key' => MedicationReviewCadence::STORAGE_KEY, 'value' => '6']);
        $this->assertSame(['months' => 6, 'own' => false, 'reviewed' => true], $this->cadence($client));
        $this->assertSame(3, $client->fresh()->chart_review_interval_months);
        $this->assertSame('2026-12-30', $client->fresh()->next_chart_review_date->toDateString());
        $this->assertSame($bookedEvidence, $this->persistedEvidence($review));
    }

    public function test_valid_non_default_legacy_intervals_are_preserved_without_claiming_reviewed_policy_or_moving_dates(): void
    {
        AppSetting::query()->create(['key' => MedicationReviewCadence::STORAGE_KEY, 'value' => '6']);
        $fixtures = collect([1, 2, 12])->map(fn (int $months) => [
            ...$this->legacyCadence($months), 'months' => $months,
        ]);
        $bookedEvidence = $fixtures->mapWithKeys(fn (array $fixture) => [
            $fixture['review']->id => $this->persistedEvidence($fixture['review']),
        ])->all();

        $this->migration()->up();

        foreach ($fixtures as ['client' => $client, 'review' => $review, 'months' => $months]) {
            $this->assertSame($months, $client->fresh()->medication_review_interval_months);
            $this->assertSame($months, $client->fresh()->chart_review_interval_months);
            $this->assertSame(['months' => $months, 'own' => true, 'reviewed' => false], $this->cadence($client));
            $this->assertSame('2026-12-30', $client->fresh()->next_chart_review_date->toDateString());
            $this->assertSame($bookedEvidence[$review->id], $this->persistedEvidence($review));
        }
        $this->assertDatabaseCount('medication_review_events', 0);
    }

    public function test_an_existing_canonical_override_is_never_replaced_by_legacy_data(): void
    {
        ['client' => $client, 'review' => $review] = $this->legacyCadence(2, 5);
        $before = $this->persistedEvidence($client);
        $bookedEvidence = $this->persistedEvidence($review);

        $this->migration()->up();

        $this->assertSame($before, $this->persistedEvidence($client));
        $this->assertSame(['months' => 5, 'own' => true, 'reviewed' => false], $this->cadence($client));
        $this->assertSame($bookedEvidence, $this->persistedEvidence($review));
    }

    public function test_an_explicit_clear_event_keeps_a_null_override_despite_a_non_default_legacy_value(): void
    {
        ['client' => $client, 'review' => $review] = $this->legacyCadence(2);
        $actor = User::factory()->create(['approved_at' => now()]);
        $clear = $this->intervalEvent($client, $actor, 2, null);
        $clearEvidence = $this->persistedEvidence($clear);
        $bookedEvidence = $this->persistedEvidence($review);

        $this->migration()->up();

        $this->assertNull($client->fresh()->medication_review_interval_months);
        $this->assertSame(2, $client->fresh()->chart_review_interval_months);
        $this->assertSame(['months' => 3, 'own' => false, 'reviewed' => false], $this->cadence($client));
        $this->assertSame($clearEvidence, $this->persistedEvidence($clear));
        $this->assertSame($bookedEvidence, $this->persistedEvidence($review));
    }

    public function test_backfill_retries_are_idempotent_and_do_not_resurrect_a_subsequently_cleared_override(): void
    {
        ['client' => $client, 'review' => $review] = $this->legacyCadence(2);
        $migration = $this->migration();
        $migration->up();
        $preserved = $this->persistedEvidence($client);
        $bookedEvidence = $this->persistedEvidence($review);

        $migration->up();

        $this->assertSame($preserved, $this->persistedEvidence($client));
        // The query-builder backfill does not update this model's original null.
        // Reload it so the deliberate clear is a dirty change from the saved two.
        $client->refresh();
        $this->assertSame(2, $client->medication_review_interval_months);
        $client->forceFill(['medication_review_interval_months' => null]);
        $this->assertTrue($client->isDirty('medication_review_interval_months'));
        $client->save();
        $this->assertNull($client->fresh()->medication_review_interval_months);
        $clearedEvidence = $this->persistedEvidence($client);
        $actor = User::factory()->create(['approved_at' => now()]);
        $clear = $this->intervalEvent($client, $actor, 2, null);
        $clearEvidence = $this->persistedEvidence($clear);
        $migration->up();
        $migration->up();

        $this->assertSame($clearedEvidence, $this->persistedEvidence($client));
        $this->assertSame($clearEvidence, $this->persistedEvidence($clear));

        $this->assertNull($client->fresh()->medication_review_interval_months);
        $this->assertSame(2, $client->fresh()->chart_review_interval_months);
        $this->assertSame('2026-12-30', $client->fresh()->next_chart_review_date->toDateString());
        $this->assertSame($bookedEvidence, $this->persistedEvidence($review));
        $this->assertSame([$clear->id], MedicationReviewEvent::query()->where('client_id', $client->id)->pluck('id')->all());
    }

    public function test_a_person_override_is_reviewed_only_when_latest_real_actor_evidence_matches_it(): void
    {
        ['client' => $client] = $this->legacyCadence(2, 5);
        $actor = User::factory()->create(['approved_at' => now()]);

        $this->assertSame(['months' => 5, 'own' => true, 'reviewed' => false], $this->cadence($client));
        $this->intervalEvent($client, null, 2, 5);
        $this->assertFalse($this->cadence($client)['reviewed']);
        $this->intervalEvent($client, $actor, 2, 5);
        $this->assertTrue($this->cadence($client)['reviewed']);
        $this->intervalEvent($client, $actor, 5, 6);
        $this->assertFalse($this->cadence($client)['reviewed']);
        $this->intervalEvent($client, $actor, 6, 5);
        $this->assertTrue($this->cadence($client)['reviewed']);

        $this->migration()->up();
        $this->assertSame(5, $client->fresh()->medication_review_interval_months);
        $this->assertTrue($this->cadence($client)['reviewed']);
    }

    public function test_invalid_legacy_intervals_do_not_become_new_person_overrides(): void
    {
        $clients = collect([0, 13])->map(fn (int $months) => $this->legacyCadence($months)['client']);

        $this->migration()->up();

        foreach ($clients as $client) {
            $this->assertNull($client->fresh()->medication_review_interval_months);
            $this->assertSame(['months' => 3, 'own' => false, 'reviewed' => false], $this->cadence($client));
        }
    }

    public function test_down_is_a_no_op_and_cannot_erase_preserved_cadence_or_review_evidence(): void
    {
        ['client' => $client, 'review' => $review] = $this->legacyCadence(12);
        $migration = $this->migration();
        $migration->up();
        $preserved = $this->persistedEvidence($client);
        $bookedEvidence = $this->persistedEvidence($review);

        $migration->down();

        $this->assertSame($preserved, $this->persistedEvidence($client));
        $this->assertSame($bookedEvidence, $this->persistedEvidence($review));
        $this->assertSame(12, $client->fresh()->medication_review_interval_months);
    }

    /** @return array{client: Client, review: MedicationReview} */
    private function legacyCadence(int $legacyMonths, ?int $canonicalMonths = null): array
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $client = Client::factory()->create([
            'site_id' => $site->id, 'status' => 'active', 'chart_review_interval_months' => $legacyMonths,
            'medication_review_interval_months' => $canonicalMonths, 'next_chart_review_date' => '2026-12-30',
        ]);
        $review = MedicationReview::query()->create([
            'client_id' => $client->id, 'review_type' => 'routine', 'status' => 'scheduled',
            'scheduled_date' => '2026-11-19', 'next_review_date' => '2026-12-30',
        ]);

        return compact('client', 'review');
    }

    private function intervalEvent(Client $client, ?User $actor, ?int $from, ?int $to): MedicationReviewEvent
    {
        return MedicationReviewEvent::query()->create([
            'review_id' => null, 'client_id' => $client->id, 'actor_id' => $actor?->id, 'event' => 'interval_changed',
            'details' => ['from_months' => $from, 'to_months' => $to, 'reason' => 'Synthetic recorded interval decision.'],
            'created_at' => now(),
        ]);
    }

    /** @return array{months: int, own: bool, reviewed: bool} */
    private function cadence(Client $client): array
    {
        return app(MedicationReviewCadence::class)->forClient($client->fresh());
    }

    /**
     * Capture every persisted column, including defaults and private evidence.
     * JSON object key order is storage formatting; its values and list order count.
     *
     * @return array<string, mixed>
     */
    private function persistedEvidence(Model $record): array
    {
        $record = $record->fresh();
        $this->assertNotNull($record);
        $attributes = $record->getRawOriginal();
        foreach ($attributes as $key => $value) {
            if ($value !== null && $record->hasCast($key, ['array', 'json', 'object', 'collection'])) {
                $attributes[$key] = json_encode(
                    $this->canonicalJson(json_decode($value, false, 512, JSON_THROW_ON_ERROR)),
                    JSON_THROW_ON_ERROR,
                );
            }
        }
        ksort($attributes);

        return $attributes;
    }

    private function canonicalJson(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);
            foreach ($properties as $key => $property) {
                $properties[$key] = $this->canonicalJson($property);
            }

            return (object) $properties;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item) => $this->canonicalJson($item), $value);
        }

        return $value;
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_03_210500_preserve_existing_medication_review_intervals.php');
    }
}
