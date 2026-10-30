<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\MedicationEvent;
use App\Models\MedicationReview;
use App\Models\MedicationReviewEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Support\WorkerClock;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationCareParametersTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Client $client;

    private Site $site;

    private MedicationReview $review;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->travelTo(CarbonImmutable::parse('2026-10-02T11:15:00Z'));
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $this->actor = $this->userAt($this->site, [
            'medications.view', 'medications.orders.manage', 'medications.reviews.manage', 'clients.viewAny',
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id, 'status' => 'active', 'care_level' => 'residential',
            'chart_review_interval_months' => 2, 'medication_review_interval_months' => 2,
            'next_chart_review_date' => '2026-12-03',
        ]);
        $this->review = MedicationReview::query()->create([
            'client_id' => $this->client->id, 'review_type' => 'regular', 'status' => 'scheduled',
            'scheduled_date' => '2026-12-03', 'owner_id' => $this->actor->id, 'requested_by' => $this->actor->id,
        ]);
        MedicationReviewEvent::query()->create([
            'client_id' => $this->client->id, 'review_id' => $this->review->id,
            'actor_id' => $this->actor->id, 'event' => 'booked',
            'details' => ['due_date' => '2026-12-03'], 'created_at' => now(),
        ]);
        $this->client->refresh();
        $this->review->refresh();
    }

    public function test_care_level_only_edit_preserves_both_intervals_booked_date_and_review_history(): void
    {
        $review = $this->review->getRawOriginal();
        $event = MedicationReviewEvent::query()->sole()->getRawOriginal();
        $this->actingAs($this->actor)->post($this->url(), ['care_level' => 'dementia'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('dementia', $this->client->fresh()->care_level);
        $this->assertCadenceUnchanged();
        $this->assertSame($review, $this->review->fresh()->getRawOriginal());
        $this->assertSame($event, MedicationReviewEvent::query()->sole()->getRawOriginal());
        $this->assertDatabaseCount('medication_events', 0);
    }

    public function test_omitted_care_level_and_review_fields_do_not_clear_or_rewrite_existing_data(): void
    {
        $before = $this->client->getRawOriginal();
        $this->actingAs($this->actor)->post($this->url(), [])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($before, $this->client->fresh()->getRawOriginal());
        $this->assertCadenceUnchanged();
        $this->assertDatabaseCount('medication_review_events', 1);
    }

    public function test_explicit_null_care_level_clears_only_the_care_level(): void
    {
        $this->actingAs($this->actor)->post($this->url(), ['care_level' => null])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($this->client->fresh()->care_level);
        $this->assertCadenceUnchanged();
    }

    public function test_direct_review_fields_including_null_are_rejected_without_partial_care_or_review_writes(): void
    {
        $before = $this->client->getRawOriginal();
        foreach ([
            ['chart_review_interval_months', 1], ['chart_review_interval_months', null],
            ['medication_review_interval_months', 6], ['medication_review_interval_months', null],
            ['next_chart_review_date', '2027-01-03'], ['next_chart_review_date', null],
        ] as [$field, $value]) {
            $response = $this->actingAs($this->actor)->postJson($this->url(), [
                'care_level' => 'dementia', $field => $value,
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertStringContainsString('Medication reviews', $response->json('errors.'.$field.'.0'));
            $this->assertSame($before, $this->client->fresh()->getRawOriginal());
        }
        $this->assertCadenceUnchanged();
        $this->assertDatabaseCount('medication_review_events', 1);
        $this->assertDatabaseCount('medication_events', 0);
    }

    public function test_foreign_house_is_denied_before_care_payload_validation(): void
    {
        $otherSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $foreign = $this->userAt($otherSite, ['medications.view', 'medications.orders.manage', 'clients.viewAny']);
        $before = $this->client->getRawOriginal();
        $this->actingAs($foreign)->postJson($this->url(), [
            'care_level' => ['invalid'], 'next_chart_review_date' => null,
        ])->assertNotFound();

        $this->assertSame($before, $this->client->fresh()->getRawOriginal());
    }

    public function test_same_house_order_management_does_not_replace_person_read_access(): void
    {
        $unassigned = $this->userAt($this->site, ['medications.view', 'medications.orders.manage']);
        $before = $this->client->getRawOriginal();
        $this->actingAs($unassigned)->postJson($this->url(), ['care_level' => 'dementia'])
            ->assertNotFound();

        $this->assertSame($before, $this->client->fresh()->getRawOriginal());
    }

    public function test_order_management_does_not_grant_cadence_permission_on_either_endpoint(): void
    {
        $ordersOnly = $this->userAt($this->site, ['medications.view', 'medications.orders.manage', 'clients.viewAny']);
        $this->actingAs($ordersOnly)->postJson($this->url(), ['chart_review_interval_months' => 6])
            ->assertUnprocessable()->assertJsonValidationErrors('chart_review_interval_months');
        $this->actingAs($ordersOnly)->putJson("/emar/clients/{$this->client->id}/review-interval", [
            'months' => 6, 'reason' => 'Synthetic interval decision.',
        ])->assertForbidden();

        $this->assertCadenceUnchanged();
        $this->assertDatabaseCount('medication_review_events', 1);
        $this->assertDatabaseCount('medication_events', 0);
    }

    public function test_canonical_review_interval_requires_a_reason_and_records_evidence_without_moving_booked_dates(): void
    {
        $url = "/emar/clients/{$this->client->id}/review-interval";
        $this->actingAs($this->actor)->putJson($url, ['months' => 6, 'reason' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($this->actor)->putJson($url, ['months' => 6, 'reason' => 'Synthetic recorded clinician advice.'])
            ->assertOk()->assertJsonPath('saved', true);

        $this->assertSame(6, $this->client->fresh()->medication_review_interval_months);
        $this->assertSame(2, $this->client->fresh()->chart_review_interval_months);
        $this->assertSame('2026-12-03', $this->client->fresh()->next_chart_review_date->toDateString());
        $this->assertSame('2026-12-03', $this->review->fresh()->scheduled_date->toDateString());
        $event = MedicationReviewEvent::query()->where('client_id', $this->client->id)->where('event', 'interval_changed')->sole();
        $this->assertSame($this->actor->id, $event->actor_id);
        $this->assertSame(6, $event->details['to_months']);
        $this->assertTrue(MedicationEvent::query()->where('client_id', $this->client->id)->where('kind', 'review.interval_changed')->exists());
    }

    private function assertCadenceUnchanged(): void
    {
        $this->assertSame(2, $this->client->fresh()->chart_review_interval_months);
        $this->assertSame(2, $this->client->fresh()->medication_review_interval_months);
        $this->assertSame('2026-12-03', $this->client->fresh()->next_chart_review_date->toDateString());
        $this->assertSame('2026-12-03', $this->review->fresh()->scheduled_date->toDateString());
    }

    private function url(): string
    {
        return "/emar/clients/{$this->client->id}/medication-settings";
    }

    private function userAt(Site $site, array $allowed): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $keys = [
            'medications.view', 'medications.orders.manage', 'medications.reviews.manage', 'clients.viewAny',
            'clinical.accessAllSites', 'sites.viewAll', 'medications.stock.update', 'medications.audit.view',
            'medications.reports.export', 'reports.viewAny', 'medications.breakglass',
        ];
        $rows = Permission::query()->whereIn('key', $keys)->get();
        $this->assertCount(count($keys), $rows);
        $user->permissionOverrides()->sync($rows->mapWithKeys(
            fn (Permission $permission) => [$permission->id => ['allowed' => in_array($permission->key, $allowed, true)]],
        )->all());
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => WorkerClock::today()->subYear()->toDateString(), 'end_date' => null, 'is_active' => true,
        ]);

        return $user;
    }
}
