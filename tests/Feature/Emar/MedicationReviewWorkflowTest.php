<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationEvent;
use App\Models\MedicationFollowup;
use App\Models\MedicationFollowupEvent;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationReview;
use App\Models\MedicationReviewEvent;
use App\Models\MedicationReviewItem;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\Reviews\MedicationReviewCadence;
use App\Services\Medication\Reviews\MedicationReviewFollowupAdapter;
use App\Services\Medication\Reviews\MedicationReviewWorkflow;
use App\Services\Tasks\Providers\MedicationFollowupProvider;
use App\Services\Tasks\Providers\MedicationReviewChangeProvider;
use App\Services\Tasks\Providers\MedicationReviewProvider;
use App\Support\WorkerClock;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MedicationReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->travelTo(CarbonImmutable::parse('2026-10-02T11:15:00Z'));
        $this->seed(RbacSeeder::class);
    }

    public function test_one_open_regular_review_is_kept_while_triggered_reviews_are_independent(): void
    {
        ['actor' => $actor, 'client' => $client] = $this->context();
        $regular = $this->book($actor, $client);

        $this->actingAs($actor)->postJson('/emar/reviews', $this->bookingPayload($actor, $client))
            ->assertUnprocessable();

        $this->book($actor, $client, 'triggered');
        $this->book($actor, $client, 'triggered', ['trigger_code' => 'asked']);

        $this->assertSame(1, MedicationReview::query()->where('client_id', $client->id)
            ->where('review_type', 'regular')->where('status', 'scheduled')->count());
        $this->assertSame(2, MedicationReview::query()->where('client_id', $client->id)
            ->where('review_type', 'triggered')->where('status', 'scheduled')->count());
        $this->assertSame(1, $regular->fresh()->revision);
        $this->assertDatabaseHas('medication_review_events', [
            'review_id' => $regular->id, 'actor_id' => $actor->id, 'event' => 'booked',
        ]);
    }

    public function test_booking_retains_each_exact_person_read_permission_in_locked_authorization_evidence(): void
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        foreach (['clients.viewAny', 'medications.reports.export', 'reports.viewAny'] as $readPermission) {
            $actor = $this->userAt($site, ['medications.view', 'medications.reviews.manage', $readPermission], personScoped: true);
            $review = $this->book($actor, $client, 'triggered');
            $this->assertSame($actor->id, $review->owner_id);
            $this->assertDatabaseHas('medication_review_events', [
                'review_id' => $review->id, 'actor_id' => $actor->id, 'event' => 'booked',
            ]);
        }
        $unassigned = $this->userAt($site, ['medications.view', 'medications.reviews.manage'], personScoped: true);
        $this->actingAs($unassigned)->postJson('/emar/reviews', $this->bookingPayload($unassigned, $client, 'triggered'))
            ->assertNotFound();
        $this->assertSame(3, MedicationReview::query()->where('client_id', $client->id)->count());
    }

    public function test_booking_owner_requires_current_review_permission_and_membership_of_the_persons_house(): void
    {
        ['actor' => $actor, 'site' => $site, 'client' => $client] = $this->context();
        $withoutReviewPermission = $this->userAt($site, ['medications.view']);
        $this->actingAs($actor)->postJson('/emar/reviews', [
            ...$this->bookingPayload($actor, $client), 'owner_id' => $withoutReviewPermission->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('owner_id');

        $otherSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $otherHouseOwner = $this->userAt($otherSite, ['medications.view', 'medications.reviews.manage']);
        $this->actingAs($actor)->postJson('/emar/reviews', [
            ...$this->bookingPayload($actor, $client), 'owner_id' => $otherHouseOwner->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('owner_id');
        $this->assertDatabaseCount('medication_reviews', 0);
        $this->assertDatabaseCount('medication_review_events', 0);

        $owner = $this->userAt($site, ['medications.view', 'medications.reviews.manage']);
        $review = $this->book($actor, $client, 'regular', ['owner_id' => $owner->id]);
        $this->assertSame($owner->id, $review->owner_id);
    }

    public function test_booking_uses_the_nz_day_and_requires_an_other_trigger_reason(): void
    {
        ['actor' => $actor, 'client' => $client] = $this->context();

        $this->assertSame('2026-10-03', WorkerClock::today()->toDateString());
        $this->actingAs($actor)->postJson('/emar/reviews', [
            ...$this->bookingPayload($actor, $client), 'scheduled_date' => '2026-10-02',
        ])->assertUnprocessable()->assertJsonValidationErrors('scheduled_date');
        $this->actingAs($actor)->postJson('/emar/reviews', [
            ...$this->bookingPayload($actor, $client, 'triggered'),
            'trigger_code' => 'other', 'trigger_reason' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('trigger_reason');

        $review = $this->book($actor, $client, 'triggered', [
            'scheduled_date' => '2026-10-03', 'trigger_code' => 'other',
            'trigger_reason' => 'Synthetic clinician request after a change in health.',
        ]);
        $this->assertSame('2026-10-03', $review->scheduled_date->toDateString());
    }

    public function test_booking_retry_returns_the_same_record_without_duplicate_evidence_and_is_payload_bound(): void
    {
        ['actor' => $actor, 'site' => $site, 'client' => $client] = $this->context();
        $requestId = (string) Str::uuid();
        $payload = [...$this->bookingPayload($actor, $client, 'triggered'), 'request_uuid' => $requestId];
        $review = $this->book($actor, $client, 'triggered', ['request_uuid' => $requestId]);
        $eventCount = MedicationReviewEvent::query()->count();
        $chainCount = MedicationEvent::query()->count();

        $this->actingAs($actor)->post('/emar/reviews', $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([$review->id], MedicationReview::query()->where('booking_request_uuid', $requestId)->pluck('id')->all());
        $this->assertSame($eventCount, MedicationReviewEvent::query()->count());
        $this->assertSame($chainCount, MedicationEvent::query()->count());
        $this->actingAs($actor)->postJson('/emar/reviews', [
            ...$payload, 'scheduled_date' => WorkerClock::today()->addWeeks(2)->toDateString(),
        ])->assertConflict();
        $this->actingAs($actor)->postJson('/emar/reviews', [
            ...$payload, 'trigger_code' => 'asked', 'trigger_reason' => 'A different reason cannot reuse the booking request.',
        ])->assertConflict();

        $otherClient = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $this->actingAs($actor)->postJson('/emar/reviews', [...$payload, 'client_id' => $otherClient->id])
            ->assertNotFound();
        $unauthorised = $this->userAt($site, ['medications.view']);
        $this->actingAs($unauthorised)->postJson('/emar/reviews', $payload)->assertForbidden();
        $this->assertSame(1, MedicationReview::query()->where('booking_request_uuid', $requestId)->count());
    }

    public function test_moving_appends_both_dates_and_reasons_and_rejects_stale_or_incomplete_writes(): void
    {
        ['actor' => $actor, 'client' => $client] = $this->context();
        $review = $this->book($actor, $client);
        $originalDate = $review->scheduled_date->toDateString();
        $firstDate = WorkerClock::today()->addWeeks(2)->toDateString();
        $secondDate = WorkerClock::today()->addWeeks(3)->toDateString();

        $this->actingAs($actor)->putJson("/emar/reviews/{$review->id}", [
            'revision' => 1, 'scheduled_date' => $firstDate, 'reason_code' => 'other', 'reason' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->move($actor, $review, $firstDate, 'clinician', 'Clinician appointment changed.');

        $firstEvent = MedicationReviewEvent::query()->where('review_id', $review->id)
            ->where('event', 'moved')->sole();
        $firstEvidence = $this->persistedEvidence($firstEvent);
        $this->assertStringContainsString($originalDate, json_encode($firstEvent->details));
        $this->assertStringContainsString($firstDate, json_encode($firstEvent->details));
        $this->assertStringContainsString('Clinician appointment changed.', json_encode($firstEvent->details));

        $this->actingAs($actor)->putJson("/emar/reviews/{$review->id}", [
            'revision' => 1, 'scheduled_date' => $secondDate,
            'reason_code' => 'unwell', 'reason' => 'Stale change must not overwrite the first move.',
        ])->assertConflict();
        $this->assertSame($firstDate, $review->fresh()->scheduled_date->toDateString());

        $this->move($actor, $review, $secondDate, 'unwell', 'Person was unwell.');
        $this->assertSame($firstEvidence, $this->persistedEvidence($firstEvent));
        $this->assertSame(2, MedicationReviewEvent::query()->where('review_id', $review->id)
            ->where('event', 'moved')->count());
        $this->assertSame(3, $review->fresh()->revision);
    }

    public function test_regular_cannot_be_cancelled_and_triggered_cancellation_keeps_reason_and_history(): void
    {
        ['actor' => $actor, 'client' => $client] = $this->context();
        $regular = $this->book($actor, $client);
        $triggered = $this->book($actor, $client, 'triggered');

        $this->actingAs($actor)->deleteJson("/emar/reviews/{$regular->id}", [
            'revision' => 1, 'reason' => 'Cannot cancel the regular cycle.',
        ])->assertUnprocessable();
        $this->actingAs($actor)->deleteJson("/emar/reviews/{$triggered->id}", [
            'revision' => 1, 'reason' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->actingAs($actor)->delete("/emar/reviews/{$triggered->id}", [
            'revision' => 1, 'reason' => 'Clinician confirmed a separate triggered review is no longer needed.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('scheduled', $regular->fresh()->status);
        $this->assertSame('cancelled', $triggered->fresh()->status);
        $event = MedicationReviewEvent::query()->where('review_id', $triggered->id)
            ->where('event', 'cancelled')->sole();
        $this->assertStringContainsString('no longer needed', json_encode($event->details));
        $this->assertDatabaseHas('medication_reviews', ['id' => $triggered->id]);
        $this->assertDatabaseMissing('medication_review_events', [
            'review_id' => $regular->id, 'event' => 'cancelled',
        ]);
    }

    public function test_order_management_does_not_substitute_for_review_management_on_direct_writes(): void
    {
        ['actor' => $actor, 'site' => $site, 'client' => $client] = $this->context();
        $review = $this->book($actor, $client, 'triggered');
        $ordersOnly = $this->userAt($site, ['medications.view', 'medications.orders.manage']);
        $before = $this->persistedEvidence($review);
        $eventCount = MedicationReviewEvent::query()->count();

        $this->actingAs($ordersOnly)->postJson('/emar/reviews', $this->bookingPayload($actor, $client))
            ->assertForbidden();
        $this->actingAs($ordersOnly)->putJson("/emar/reviews/{$review->id}", [
            'revision' => 1, 'scheduled_date' => WorkerClock::today()->addWeek()->toDateString(),
            'reason_code' => 'clinician', 'reason' => 'Not authorised.',
        ])->assertForbidden();
        $this->actingAs($ordersOnly)->deleteJson("/emar/reviews/{$review->id}", [
            'revision' => 1, 'reason' => 'Not authorised.',
        ])->assertForbidden();
        $this->actingAs($ordersOnly)->postJson("/emar/reviews/{$review->id}/complete", $this->completionPayload($review))
            ->assertForbidden();
        $this->actingAs($ordersOnly)->putJson("/emar/clients/{$client->id}/review-interval", [
            'months' => 6, 'reason' => 'Not authorised.',
        ])->assertForbidden();

        $this->assertSame($before, $this->persistedEvidence($review));
        $this->assertSame($eventCount, MedicationReviewEvent::query()->count());
        $this->assertDatabaseCount('medication_review_items', 0);
    }

    public function test_foreign_site_direct_ids_are_concealed_before_payload_validation(): void
    {
        ['actor' => $actor, 'site' => $localSite] = $this->context();
        $foreignSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id, 'status' => 'active']);
        $foreignActor = $this->userAt($foreignSite, ['medications.view', 'medications.reviews.manage']);
        $foreignReview = $this->book($foreignActor, $foreignClient, 'triggered');
        $before = $this->persistedEvidence($foreignReview);

        $this->actingAs($actor)->postJson('/emar/reviews', ['client_id' => $foreignClient->id])
            ->assertNotFound();
        $this->actingAs($actor)->putJson("/emar/reviews/{$foreignReview->id}", [])
            ->assertNotFound();
        $this->actingAs($actor)->postJson("/emar/reviews/{$foreignReview->id}/complete", [])
            ->assertNotFound();
        $this->actingAs($actor)->deleteJson("/emar/reviews/{$foreignReview->id}", [])
            ->assertNotFound();
        $this->actingAs($actor)->putJson("/emar/clients/{$foreignClient->id}/review-interval", [])
            ->assertNotFound();
        $this->actingAs($actor)->get("/emar/reviews?site_id={$foreignSite->id}")->assertNotFound();
        $this->actingAs($actor)->get("/emar/reviews?client_id={$foreignClient->id}")->assertNotFound();

        $this->assertSame($before, $this->persistedEvidence($foreignReview));
        $this->assertNotSame($localSite->id, $foreignClient->site_id);
    }

    public function test_regular_completion_uses_nz_completed_day_and_clamps_month_end(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-30T11:05:00Z'));
        ['actor' => $actor, 'client' => $client] = $this->context();
        $client->forceFill(['medication_review_interval_months' => 1])->save();
        $review = $this->book($actor, $client, 'regular', ['scheduled_date' => '2026-01-31']);

        $this->complete($actor, $review, ['completed_date' => '2026-01-31']);

        $next = MedicationReview::query()->where('client_id', $client->id)
            ->where('review_type', 'regular')->where('status', 'scheduled')->sole();
        $this->assertSame('2026-02-28', $next->scheduled_date->toDateString());
        $this->assertSame($actor->id, $next->owner_id);
        $this->assertSame('2026-01-31', $review->fresh()->completed_date->toDateString());
        $this->assertSame($actor->id, $review->fresh()->completed_by);
        $recorded = MedicationReviewEvent::query()->where('review_id', $review->id)->where('event', 'completed')->sole();
        $this->assertSame($actor->id, $recorded->actor_id);
        $this->assertNotNull($recorded->created_at);

        $this->actingAs($actor)->postJson("/emar/reviews/{$review->id}/complete", $this->completionPayload($review, [
            'revision' => 1, 'completed_date' => '2026-01-31',
        ]))->assertConflict();
        $this->assertSame(1, MedicationReview::query()->where('client_id', $client->id)
            ->where('review_type', 'regular')->where('status', 'scheduled')->count());
    }

    public function test_completed_minute_is_stored_as_exact_utc_evidence_and_future_local_minutes_are_rejected(): void
    {
        ['actor' => $actor, 'client' => $client] = $this->context();
        $this->medication($client, 'Synthetic timed review medicine');
        $review = $this->book($actor, $client, 'triggered');

        $this->actingAs($actor)->postJson("/emar/reviews/{$review->id}/complete", $this->completionPayload($review, [
            'completed_date' => '2026-10-03', 'completed_time' => '00:16',
        ]))->assertUnprocessable()->assertJsonValidationErrors('completed_time');
        $this->assertSame('scheduled', $review->fresh()->status);
        $this->assertDatabaseCount('medication_review_items', 0);

        $this->complete($actor, $review, [
            'completed_date' => '2026-10-03', 'completed_time' => '00:14',
            'clinician_practice' => 'Synthetic practice', 'reviewer_registration_number' => 'SYNTHETIC-123',
        ]);

        $this->assertSame('00:14', $review->fresh()->completed_time);
        $this->assertSame('2026-10-02 11:14:00', $review->fresh()->happened_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('Synthetic practice', $review->fresh()->clinician_practice);
        $this->assertSame('SYNTHETIC-123', $review->fresh()->reviewer_registration_number);
        $response = $this->actingAs($actor)->get("/emar/reviews?review={$review->id}&view=recorded")->assertOk();
        $this->assertSame('00:14', $response->inertiaProps('selected.completed_time'));
        $this->assertSame('2026-10-02 11:14:00', CarbonImmutable::parse($response->inertiaProps('selected.happened_at'))->utc()->format('Y-m-d H:i:s'));
    }

    public function test_canonical_audit_append_failure_rolls_back_completion_next_review_and_all_evidence(): void
    {
        Storage::fake('local');
        ['actor' => $actor, 'site' => $site, 'client' => $client] = $this->context();
        $this->medication($client, 'Synthetic rollback medicine');
        $review = $this->book($actor, $client);
        $before = $this->persistedEvidence($review);
        $reviewIds = MedicationReview::query()->where('client_id', $client->id)->pluck('id')->all();
        $eventCount = MedicationReviewEvent::query()->count();
        $chainCount = MedicationEvent::query()->count();
        $head = DB::table('medication_event_heads')->where('site_id', $site->id)->first();
        $nextDate = $client->fresh()->next_chart_review_date->toDateString();
        $this->mock(MedicationEventRecorder::class)->shouldReceive('append')->once()
            ->with(Mockery::on(fn ($event) => $event instanceof MedicationEventData
                && $event->kind === 'review.completed' && $event->clientId === $client->id))
            ->andReturnUsing(function () use ($client, $review): void {
                $this->assertSame('completed', $review->fresh()->status);
                $this->assertSame(1, MedicationReviewItem::query()->where('review_id', $review->id)->count());
                $this->assertSame(1, MedicationReview::query()->where('client_id', $client->id)
                    ->where('review_type', 'regular')->where('status', 'scheduled')->count());
                $this->assertDatabaseHas('medication_review_events', ['review_id' => $review->id, 'event' => 'completed']);
                throw new RuntimeException('Injected canonical medication audit failure.');
            });

        try {
            app(MedicationReviewWorkflow::class)->complete($actor, $review, $this->completionPayload($review),
                UploadedFile::fake()->create('rollback-source.pdf', 8, 'application/pdf'));
            $this->fail('A failed canonical append must abort review completion.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected canonical medication audit failure.', $exception->getMessage());
        }

        $this->assertSame($before, $this->persistedEvidence($review));
        $this->assertSame($reviewIds, MedicationReview::query()->where('client_id', $client->id)->pluck('id')->all());
        $this->assertDatabaseCount('medication_review_items', 0);
        $this->assertSame($eventCount, MedicationReviewEvent::query()->count());
        $this->assertSame($chainCount, MedicationEvent::query()->count());
        $this->assertEquals($head, DB::table('medication_event_heads')->where('site_id', $site->id)->first());
        $this->assertSame($nextDate, $client->fresh()->next_chart_review_date->toDateString());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_triggered_completion_does_not_replace_or_move_the_regular_cycle(): void
    {
        ['actor' => $actor, 'client' => $client] = $this->context();
        $regular = $this->book($actor, $client, 'regular', [
            'scheduled_date' => WorkerClock::today()->addMonths(2)->toDateString(),
        ]);
        $before = $this->persistedEvidence($regular);
        $triggered = $this->book($actor, $client, 'triggered');

        $this->complete($actor, $triggered);

        $this->assertSame($before, $this->persistedEvidence($regular));
        $this->assertSame(1, MedicationReview::query()->where('client_id', $client->id)
            ->where('review_type', 'regular')->where('status', 'scheduled')->count());
        $this->assertSame('completed', $triggered->fresh()->status);
    }

    public function test_regular_completion_with_clinician_requested_sooner_date_books_one_next_regular(): void
    {
        ['actor' => $actor, 'client' => $client] = $this->context();
        $review = $this->book($actor, $client);

        $this->complete($actor, $review, ['earlier_review_date' => '2026-11-03']);

        $next = MedicationReview::query()->where('client_id', $client->id)
            ->where('status', 'scheduled')->sole();
        $this->assertSame('regular', $next->review_type);
        $this->assertSame('2026-11-03', $next->scheduled_date->toDateString());
        $this->assertSame('2026-11-03', $review->fresh()->next_review_date->toDateString());
        $this->assertSame('2026-11-03', $client->fresh()->next_chart_review_date->toDateString());
        $booked = MedicationReviewEvent::query()->where('review_id', $next->id)->where('event', 'booked')->sole();
        $this->assertSame($review->id, $booked->details['from_review_id']);
        $this->assertTrue($booked->details['automatic']);
    }

    public function test_triggered_sooner_date_moves_existing_regular_with_append_only_clinician_reason(): void
    {
        ['actor' => $actor, 'client' => $client] = $this->context();
        $regular = $this->book($actor, $client, 'regular', ['scheduled_date' => '2026-12-03']);
        $booked = MedicationReviewEvent::query()->where('review_id', $regular->id)->where('event', 'booked')->sole();
        $bookedEvidence = $this->persistedEvidence($booked);
        $triggered = $this->book($actor, $client, 'triggered');

        $this->complete($actor, $triggered, ['earlier_review_date' => '2026-11-03']);

        $this->assertSame('2026-11-03', $regular->fresh()->scheduled_date->toDateString());
        $this->assertSame(2, $regular->fresh()->revision);
        $this->assertSame($bookedEvidence, $this->persistedEvidence($booked));
        $move = MedicationReviewEvent::query()->where('review_id', $regular->id)->where('event', 'moved')->sole();
        $this->assertSame('2026-12-03', $move->details['from_date']);
        $this->assertSame('2026-11-03', $move->details['to_date']);
        $this->assertStringContainsString('clinician', strtolower($move->details['reason']));
        $this->assertStringContainsString((string) $triggered->id, $move->details['reason']);
        $this->assertSame(1, MedicationReview::query()->where('client_id', $client->id)->where('status', 'scheduled')->count());
        $this->assertSame('2026-11-03', $client->fresh()->next_chart_review_date->toDateString());
    }

    public function test_triggered_sooner_date_creates_missing_regular_without_creating_another_triggered_review(): void
    {
        ['actor' => $actor, 'client' => $client] = $this->context();
        $triggered = $this->book($actor, $client, 'triggered');

        $this->complete($actor, $triggered, ['earlier_review_date' => '2026-11-03']);

        $next = MedicationReview::query()->where('client_id', $client->id)->where('status', 'scheduled')->sole();
        $this->assertSame('regular', $next->review_type);
        $this->assertSame('2026-11-03', $next->scheduled_date->toDateString());
        $this->assertSame($actor->id, $next->owner_id);
        $this->assertSame(1, MedicationReview::query()->where('client_id', $client->id)->where('review_type', 'triggered')->count());
    }

    public function test_person_interval_requires_a_reason_keeps_booked_date_and_applies_to_next_review(): void
    {
        ['actor' => $actor, 'client' => $client] = $this->context();
        $review = $this->book($actor, $client);
        $bookedDate = $review->scheduled_date->toDateString();

        $this->actingAs($actor)->putJson("/emar/clients/{$client->id}/review-interval", [
            'months' => 6, 'reason' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($actor)->putJson("/emar/clients/{$client->id}/review-interval", [
            'months' => 13, 'reason' => 'Outside the supported range.',
        ])->assertUnprocessable()->assertJsonValidationErrors('months');
        $this->actingAs($actor)->put("/emar/clients/{$client->id}/review-interval", [
            'months' => 6, 'reason' => 'Synthetic clinician advice for this person.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(6, $client->fresh()->medication_review_interval_months);
        $this->assertSame($bookedDate, $review->fresh()->scheduled_date->toDateString());
        $this->complete($actor, $review);
        $next = MedicationReview::query()->where('client_id', $client->id)
            ->where('review_type', 'regular')->where('status', 'scheduled')->sole();
        $this->assertSame('2027-04-03', $next->scheduled_date->toDateString());

        $this->actingAs($actor)->put("/emar/clients/{$client->id}/review-interval", [
            'months' => null, 'reason' => 'Use the organisation default again.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($client->fresh()->medication_review_interval_months);
        $this->assertSame('2027-04-03', $next->fresh()->scheduled_date->toDateString());
        $this->assertSame(2, MedicationReviewEvent::query()->where('client_id', $client->id)
            ->where('event', 'interval_changed')->count());
    }

    public function test_unreviewed_three_month_default_is_not_turned_into_a_persons_clinical_policy(): void
    {
        ['client' => $client] = $this->context();
        $client->forceFill(['chart_review_interval_months' => 12, 'medication_review_interval_months' => null])->save();
        $cadence = app(MedicationReviewCadence::class);

        $this->assertSame(['months' => 3, 'reviewed' => false], $cadence->organisation());
        $this->assertSame(['months' => 3, 'own' => false, 'reviewed' => false], $cadence->forClient($client));
    }

    public function test_completion_requires_participation_answers_and_clinician_watch_details(): void
    {
        ['actor' => $actor, 'client' => $client] = $this->context();
        $medicine = $this->medication($client, 'Synthetic watch medicine');
        $prescription = $this->persistedEvidence($medicine);
        $review = $this->book($actor, $client, 'triggered');

        $this->actingAs($actor)->postJson("/emar/reviews/{$review->id}/complete", $this->completionPayload($review, [
            'participants' => ['person' => 'not', 'person_reason' => '', 'whanau' => 'none', 'whanau_detail' => ''],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['participants.person_reason', 'participants.whanau_detail']);
        $this->actingAs($actor)->postJson("/emar/reviews/{$review->id}/complete", $this->completionPayload($review, [
            'completed_date' => WorkerClock::today()->addDay()->toDateString(),
        ]))->assertUnprocessable()->assertJsonValidationErrors('completed_date');
        $this->actingAs($actor)->postJson("/emar/reviews/{$review->id}/complete", $this->completionPayload($review, [
            'items' => [['client_medication_id' => $medicine->id, 'outcome' => 'watch']],
        ]))->assertUnprocessable();

        $this->complete($actor, $review, [
            'items' => [[
                'client_medication_id' => $medicine->id, 'outcome' => 'watch',
                'watch_text' => 'Synthetic clinician asks the house lead to record dizziness.',
                'watch_until' => '2026-10-10',
            ]],
        ]);
        $item = MedicationReviewItem::query()->where('review_id', $review->id)->sole();
        $this->assertSame('watch', $item->outcome);
        $this->assertSame('2026-10-10', $item->watch_until->toDateString());
        $this->assertStringContainsString('record dizziness', $item->watch_text);
        $this->assertSame($prescription, $this->persistedEvidence($medicine));
    }

    public function test_completion_requires_an_outcome_for_each_current_order_and_rejects_foreign_order_ids(): void
    {
        ['actor' => $actor, 'site' => $site, 'client' => $client] = $this->context();
        $first = $this->medication($client, 'Synthetic first medicine');
        $second = $this->medication($client, 'Synthetic second medicine');
        $otherPerson = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $otherMedicine = $this->medication($otherPerson, 'Other person medicine');
        $review = $this->book($actor, $client, 'triggered');

        $this->actingAs($actor)->postJson("/emar/reviews/{$review->id}/complete", $this->completionPayload($review, [
            'items' => [['client_medication_id' => $first->id, 'outcome' => 'continue']],
        ]))->assertUnprocessable();
        $this->actingAs($actor)->postJson("/emar/reviews/{$review->id}/complete", $this->completionPayload($review, [
            'items' => [
                ['client_medication_id' => $first->id, 'outcome' => 'continue'],
                ['client_medication_id' => $otherMedicine->id, 'outcome' => 'continue'],
            ],
        ]))->assertNotFound();
        $this->actingAs($actor)->postJson("/emar/reviews/{$review->id}/complete", $this->completionPayload($review, [
            'items' => [
                ['client_medication_id' => $first->id, 'outcome' => 'continue'],
                ['client_medication_id' => $first->id, 'outcome' => 'continue'],
                ['client_medication_id' => $second->id, 'outcome' => 'continue'],
            ],
        ]))->assertUnprocessable();

        $this->assertSame('scheduled', $review->fresh()->status);
        $this->assertDatabaseCount('medication_review_items', 0);
        $this->complete($actor, $review);
        $this->assertSame(2, MedicationReviewItem::query()->where('review_id', $review->id)->count());
    }

    public function test_review_links_effective_canonical_version_despite_waiting_or_rejected_later_evidence(): void
    {
        foreach (['pending_verification', 'rejected'] as $draftStatus) {
            ['actor' => $actor, 'client' => $client] = $this->context();
            $medicine = $this->medication($client, 'Synthetic effective version one');
            $effective = $this->orderVersion($actor, $medicine, 1, '10 mg', 'Synthetic checked effective baseline.');
            $draft = $this->orderVersion($actor, $medicine, 2, '20 mg', 'Synthetic '.$draftStatus.' change that is not published.');
            $medicine->forceFill(['version' => 1, 'approval_status' => $draftStatus])->saveQuietly();
            $before = $this->persistedEvidence($medicine);
            $review = $this->book($actor, $client, 'triggered');

            $this->complete($actor, $review);

            $item = MedicationReviewItem::query()->where('review_id', $review->id)->sole();
            $this->assertSame($effective->id, $item->order_version_id);
            $this->assertNotSame($draft->id, $item->order_version_id);
            $this->assertSame($before, $this->persistedEvidence($medicine));
            $this->assertSame('10 mg', $effective->fresh()->dosage);
            $this->assertSame('20 mg', $draft->fresh()->dosage);
        }
    }

    public function test_review_links_published_version_two_and_never_guesses_a_later_draft_when_evidence_is_missing(): void
    {
        ['actor' => $actor, 'client' => $client] = $this->context();
        $published = $this->medication($client, 'Synthetic published medicine');
        $this->orderVersion($actor, $published, 1, '10 mg', 'Synthetic previous baseline.');
        $publishedVersion = $this->orderVersion($actor, $published, 2, '20 mg', 'Synthetic independently checked version.');
        // Synthetic publication fixture, isolated from any live medication data.
        $published->forceFill(['version' => 2, 'dosage' => '20 mg', 'approval_status' => 'verified'])->saveQuietly();
        $missing = $this->medication($client, 'Synthetic missing version evidence');
        $missing->forceFill(['version' => 3])->saveQuietly();
        $laterDraft = $this->orderVersion($actor, $missing, 4, '40 mg', 'Synthetic later draft, not the canonical version.');
        $review = $this->book($actor, $client, 'triggered');

        $this->complete($actor, $review);

        $publishedItem = MedicationReviewItem::query()->where('review_id', $review->id)->where('client_medication_id', $published->id)->sole();
        $missingItem = MedicationReviewItem::query()->where('review_id', $review->id)->where('client_medication_id', $missing->id)->sole();
        $this->assertSame($publishedVersion->id, $publishedItem->order_version_id);
        $this->assertNull($missingItem->order_version_id);
        $this->assertNotSame($laterDraft->id, $missingItem->order_version_id);
    }

    public function test_review_watch_uses_one_real_followup_ledger_task_and_completion_returns_to_the_review(): void
    {
        ['site' => $site, 'client' => $client] = $this->context();
        $owner = $this->userAt($site, [
            'medications.view', 'medications.reviews.manage', 'medications.controlled.view',
            'medications.administer.record', 'medications.followups.manage',
        ]);
        $medicine = $this->medication($client, 'Synthetic watched medicine');
        $prescription = $this->persistedEvidence($medicine);
        $review = $this->book($owner, $client, 'triggered');
        $this->complete($owner, $review, ['items' => [[
            'client_medication_id' => $medicine->id, 'outcome' => 'watch',
            'watch_text' => 'Synthetic clinician asks for a dizziness check.', 'watch_until' => '2026-10-10',
        ]]]);
        $item = MedicationReviewItem::query()->where('review_id', $review->id)->sole();
        $followup = MedicationFollowup::query()->where('source_key', 'review-watch:'.$item->id)->sole();

        $this->assertSame('review_watch', $followup->type);
        $this->assertSame($followup->id, $item->followup_id);
        $this->assertSame($owner->id, $followup->owner_id);
        $this->assertSame($owner->id, $followup->original_owner_id);
        $this->assertSame($client->id, $followup->client_id);
        $this->assertSame($medicine->id, $followup->client_medication_id);
        $this->assertSame('2026-10-10 10:59:59', $followup->due_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($review->id, $followup->context['review_id']);
        $this->assertSame($item->id, $followup->context['review_item_id']);
        $this->assertSame($item->watch_text, $followup->context['what_to_watch']);
        DB::transaction(function () use ($client, $review, $item): void {
            app(MedicationReviewFollowupAdapter::class)->ensure($client, $review, $item);
            app(MedicationReviewFollowupAdapter::class)->ensure($client, $review, $item);
        });
        $this->assertSame(1, MedicationFollowup::query()->where('source_key', 'review-watch:'.$item->id)->count());
        $this->assertSame(1, MedicationFollowupEvent::query()->where('medication_followup_id', $followup->id)->where('action', 'created')->count());

        $provider = app(MedicationFollowupProvider::class);
        $tasks = $provider->authorizedTasks($owner, ['id' => $followup->id]);
        $this->assertCount(1, $tasks);
        $this->assertSame('medication-followup-'.$followup->id, $tasks[0]->id);
        $this->assertSame($owner->id, $tasks[0]->assignee['id']);
        $this->assertSame('/medication-followups?open='.$followup->id, $tasks[0]->link);
        $this->assertSame($followup->due_at->toIso8601String(), $tasks[0]->dueAt);
        $before = $this->actingAs($owner)->get('/emar/reviews?review='.$review->id.'&view=recorded')->assertOk();
        $this->assertFalse($before->inertiaProps('selected.items.0.watch_completed'));
        $this->assertSame('/medication-followups?open='.$followup->id, $before->inertiaProps('selected.items.0.followup_url'));

        $completion = ['request_uuid' => (string) Str::uuid(), 'revision' => $followup->revision,
            'action' => 'complete', 'outcome' => 'Synthetic check recorded no dizziness.'];
        $this->actingAs($owner)->postJson('/medication-followups/'.$followup->id.'/transition', $completion)
            ->assertOk()->assertJsonPath('sync.status', 'processed');
        $this->actingAs($owner)->postJson('/medication-followups/'.$followup->id.'/transition', $completion)
            ->assertOk()->assertJsonPath('sync.status', 'duplicate');
        $this->assertNotNull($followup->fresh()->completed_at);
        $this->assertSame($owner->id, $followup->fresh()->completed_by);
        $this->assertSame([], $provider->authorizedTasks($owner, ['id' => $followup->id]));
        $doneTasks = $provider->authorizedTasks($owner, ['id' => $followup->id, 'include_done' => true]);
        $this->assertSame('medication-followup-'.$followup->id, $doneTasks[0]->id);
        $this->assertSame('done', $doneTasks[0]->bucket);
        $after = $this->actingAs($owner)->get('/emar/reviews?review='.$review->id.'&view=recorded')->assertOk();
        $this->assertTrue($after->inertiaProps('selected.items.0.watch_completed'));
        $this->assertSame($prescription, $this->persistedEvidence($medicine));
    }

    public function test_legacy_audit_review_summary_requires_review_and_controlled_access_and_names_the_recorder(): void
    {
        ['actor' => $actor, 'site' => $site, 'client' => $client] = $this->context();
        $historicalReviewer = $this->userAt($site, ['medications.view']);
        $legacy = MedicationReview::query()->create([
            'client_id' => $client->id, 'review_type' => 'routine', 'status' => 'completed',
            'scheduled_date' => '2026-09-25', 'completed_date' => '2026-10-03',
            'happened_at' => WorkerClock::toUtc('2026-10-03 00:14'), 'completed_by' => $actor->id,
            'reviewer_user_id' => $historicalReviewer->id, 'reviewer_name' => 'Dr Synthetic Legacy Clinician',
            'clinical_summary' => 'Sensitive legacy clinician summary.',
        ]);
        $auditWithoutManage = $this->userAt($site, ['medications.view', 'medications.audit.view', 'medications.controlled.view']);
        $managerWithoutControlled = $this->userAt($site, ['medications.view', 'medications.audit.view', 'medications.reviews.manage']);

        foreach ([$auditWithoutManage, $managerWithoutControlled] as $restricted) {
            $response = $this->actingAs($restricted)->get('/emar/audit?event_types=review_completed')->assertOk();
            $event = collect($response->inertiaProps('events'))->firstWhere('id', 'review_'.$legacy->id);
            $this->assertNotNull($event);
            $this->assertNull($event['details']['summary']);
            $this->assertSame($actor->name, $event['performed_by']);
            $this->assertStringNotContainsString('Sensitive legacy clinician summary.', json_encode($response->inertiaProps()));
        }
        $authorised = $this->userAt($site, [
            'medications.view', 'medications.audit.view', 'medications.reviews.manage', 'medications.controlled.view',
        ]);
        $response = $this->actingAs($authorised)->get('/emar/audit?event_types=review_completed')->assertOk();
        $event = collect($response->inertiaProps('events'))->firstWhere('id', 'review_'.$legacy->id);
        $this->assertSame('Sensitive legacy clinician summary.', $event['details']['summary']);
        $this->assertSame($actor->name, $event['performed_by']);
        $this->assertSame('2026-10-02 11:14:00', CarbonImmutable::parse($event['timestamp'])->utc()->format('Y-m-d H:i:s'));
    }

    public function test_controlled_outcome_is_pending_instead_of_continue_and_requires_controlled_access_to_add(): void
    {
        ['actor' => $actor, 'site' => $site, 'client' => $client] = $this->context();
        $controlled = $this->medication($client, 'Hidden synthetic controlled medicine', true);
        $managerWithoutControlled = $this->userAt($site, ['medications.view', 'medications.reviews.manage']);
        $review = $this->book($managerWithoutControlled, $client, 'triggered');
        $prescription = $this->persistedEvidence($controlled);

        $this->complete($managerWithoutControlled, $review, ['items' => []]);

        $item = MedicationReviewItem::query()->where('review_id', $review->id)->sole();
        $this->assertSame('pending_controlled', $item->outcome);
        $this->assertNull($item->recommendation);
        $this->assertSame($prescription, $this->persistedEvidence($controlled));

        $this->actingAs($managerWithoutControlled)->postJson("/emar/reviews/{$review->id}/items/{$item->id}/outcome", [
            'revision' => $review->fresh()->revision, 'outcome' => 'continue',
        ])->assertNotFound();
        $this->actingAs($actor)->post("/emar/reviews/{$review->id}/items/{$item->id}/outcome", [
            'revision' => $review->fresh()->revision,
            'outcome' => 'change', 'recommendation' => 'Synthetic clinician recommends a lower dose.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('change', $item->fresh()->outcome);
        $this->assertSame($prescription, $this->persistedEvidence($controlled));
        $this->assertDatabaseHas('medication_review_events', [
            'review_id' => $review->id, 'actor_id' => $actor->id, 'event' => 'outcome_added',
        ]);
    }

    public function test_recommendation_and_prescriber_decision_leave_the_checked_prescription_unchanged(): void
    {
        ['actor' => $actor, 'site' => $site, 'client' => $client] = $this->context();
        $medication = $this->medication($client, 'Synthetic unchanged medicine');
        $prescription = $this->persistedEvidence($medication);
        $review = $this->book($actor, $client, 'triggered');
        $this->complete($actor, $review, [
            'items' => [[
                'client_medication_id' => $medication->id, 'outcome' => 'stop',
                'recommendation' => 'Synthetic clinician recommends stopping after prescriber agreement.',
            ]],
        ]);
        $item = MedicationReviewItem::query()->where('review_id', $review->id)->sole();
        $manager = $this->userAt($site, ['medications.view', 'medications.reviews.manage', 'medications.orders.manage']);
        $revision = $review->fresh()->revision;

        $this->actingAs($manager)->post("/emar/reviews/{$review->id}/items/{$item->id}/decision", [
            'revision' => $revision, 'state' => 'agreed', 'prescriber_name' => 'Dr Synthetic Reviewer',
            'decision_date' => WorkerClock::today()->toDateString(), 'method' => 'phone',
            'note' => 'Agreed by phone; order entry still needs the independent check.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('agreed', $item->fresh()->decision);
        $this->assertSame('phone', $item->fresh()->decision_method);
        $this->assertSame($prescription, $this->persistedEvidence($medication));
        $this->assertNull($item->fresh()->linked_order_version_id);

        $this->actingAs($manager)->postJson("/emar/reviews/{$review->id}/items/{$item->id}/decision", [
            'revision' => $revision, 'state' => 'not_agreed', 'prescriber_name' => 'Stale prescriber',
            'decision_date' => WorkerClock::today()->toDateString(), 'method' => 'phone',
            'note' => 'Must not overwrite the recorded agreement.',
        ])->assertConflict();
        $this->assertSame('agreed', $item->fresh()->decision);
        $this->assertSame(1, MedicationReviewEvent::query()->where('review_id', $review->id)
            ->where('event', 'prescriber_decision')->count());
    }

    public function test_nested_item_ids_are_bound_to_the_review_and_manage_permission_is_required(): void
    {
        ['actor' => $actor, 'site' => $site, 'client' => $client] = $this->context();
        $this->medication($client, 'Synthetic nested medicine');
        $first = $this->book($actor, $client, 'triggered');
        $second = $this->book($actor, $client, 'triggered');
        $this->complete($actor, $first, [
            'items' => [[
                'client_medication_id' => ClientMedication::query()->where('client_id', $client->id)->sole()->id,
                'outcome' => 'change', 'recommendation' => 'Synthetic recommendation.',
            ]],
        ]);
        $item = MedicationReviewItem::query()->where('review_id', $first->id)->sole();
        $this->complete($actor, $second);
        $before = $this->persistedEvidence($item);
        $ordersOnly = $this->userAt($site, ['medications.view', 'medications.orders.manage']);

        $this->actingAs($actor)->postJson("/emar/reviews/{$second->id}/items/{$item->id}/decision", [
            'revision' => $second->fresh()->revision,
        ])
            ->assertNotFound();
        $this->actingAs($actor)->postJson("/emar/reviews/{$second->id}/items/{$item->id}/outcome", [
            'revision' => $second->fresh()->revision,
        ])
            ->assertNotFound();
        $this->actingAs($ordersOnly)->postJson("/emar/reviews/{$first->id}/items/{$item->id}/decision", [])
            ->assertForbidden();
        $this->actingAs($ordersOnly)->postJson("/emar/reviews/{$first->id}/items/{$item->id}/outcome", [])
            ->assertForbidden();

        $this->assertSame($before, $this->persistedEvidence($item));
    }

    public function test_reader_pagination_meters_and_direct_selection_follow_the_same_person_boundary(): void
    {
        ['site' => $site, 'client' => $client] = $this->context();
        $reader = $this->userAt($site, ['medications.view'], personScoped: true);
        $client->supportWorkers()->attach($reader->id);
        $otherPerson = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $foreignSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $foreignPerson = Client::factory()->create(['site_id' => $foreignSite->id, 'status' => 'active']);
        $reviews = collect(range(0, 25))->map(fn ($index) => MedicationReview::query()->create([
            'client_id' => $client->id, 'review_type' => 'triggered', 'status' => 'scheduled',
            'scheduled_date' => WorkerClock::today()->subDays($index === 0 ? 1 : 0)->toDateString(),
        ]));
        foreach ([$otherPerson, $foreignPerson] as $excludedPerson) {
            MedicationReview::query()->create([
                'client_id' => $excludedPerson->id, 'review_type' => 'triggered', 'status' => 'scheduled',
                'scheduled_date' => WorkerClock::today()->subWeek()->toDateString(),
            ]);
        }

        $firstPage = $this->actingAs($reader)->get('/emar/reviews?view=due')->assertOk();
        $this->assertSame(26, $firstPage->inertiaProps('reviews.total'));
        $this->assertCount(25, $firstPage->inertiaProps('reviews.data'));
        $this->assertSame([$client->id], collect($firstPage->inertiaProps('reviews.data'))->pluck('client_id')->unique()->values()->all());
        $this->assertSame(1, $firstPage->inertiaProps('meters.overdue'));
        $this->assertSame(25, $firstPage->inertiaProps('meters.due_30'));
        $this->assertFalse($firstPage->inertiaProps('can.manage'));
        $secondPage = $this->actingAs($reader)->get('/emar/reviews?view=due&page=2')->assertOk();
        $this->assertCount(1, $secondPage->inertiaProps('reviews.data'));
        $this->assertSame($reviews->last()->id, $secondPage->inertiaProps('reviews.data.0.id'));

        foreach ([$otherPerson, $foreignPerson] as $excludedPerson) {
            $excludedReview = MedicationReview::query()->where('client_id', $excludedPerson->id)->sole();
            $this->actingAs($reader)->get('/emar/reviews?review='.$excludedReview->id)->assertNotFound();
            $this->actingAs($reader)->get('/emar/reviews?client_id='.$excludedPerson->id)->assertNotFound();
        }
        $noModuleRead = $this->userAt($site, ['medications.reviews.manage']);
        $this->actingAs($noModuleRead)->get('/emar/reviews')->assertForbidden();
    }

    public function test_tasks_providers_apply_current_site_and_person_access_even_to_direct_task_ids(): void
    {
        ['actor' => $actor, 'site' => $site, 'client' => $client] = $this->context();
        $otherPerson = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $foreignSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $foreignPerson = Client::factory()->create(['site_id' => $foreignSite->id, 'status' => 'active']);
        $foreignActor = $this->userAt($foreignSite, ['medications.view', 'medications.reviews.manage']);
        $records = [];
        foreach ([[$client, $actor], [$otherPerson, $actor], [$foreignPerson, $foreignActor]] as [$person, $owner]) {
            $medicine = $this->medication($person, 'Synthetic task medicine '.$person->id);
            $recorded = $this->book($owner, $person, 'triggered');
            $this->complete($owner, $recorded, ['items' => [[
                'client_medication_id' => $medicine->id, 'outcome' => 'change',
                'recommendation' => 'Synthetic clinician recommendation for task scope.',
            ]]]);
            $records[$person->id] = [
                'review' => $this->book($owner, $person),
                'item' => MedicationReviewItem::query()->where('review_id', $recorded->id)->sole(),
            ];
        }
        $scopedManager = $this->userAt($site, ['medications.view', 'medications.reviews.manage'], personScoped: true);
        $client->supportWorkers()->attach($scopedManager->id);
        $reviewProvider = app(MedicationReviewProvider::class);
        $changeProvider = app(MedicationReviewChangeProvider::class);

        $tasks = $reviewProvider->authorizedTasks($scopedManager);
        $changes = $changeProvider->authorizedTasks($scopedManager);
        $this->assertSame(['medication_review-'.$records[$client->id]['review']->id], collect($tasks)->pluck('id')->all());
        $this->assertSame(['medication_review_change-'.$records[$client->id]['item']->id], collect($changes)->pluck('id')->all());
        $this->assertSame($actor->id, $tasks[0]->assignee['id']);
        $this->assertSame('/emar/reviews?review='.$records[$client->id]['review']->id, $tasks[0]->link);
        foreach ([$otherPerson, $foreignPerson] as $excludedPerson) {
            $this->assertSame([], $reviewProvider->authorizedTasks($scopedManager, ['id' => $records[$excludedPerson->id]['review']->id]));
            $this->assertSame([], $changeProvider->authorizedTasks($scopedManager, ['id' => $records[$excludedPerson->id]['item']->id]));
        }
        $readOnly = $this->userAt($site, ['medications.view']);
        $this->assertSame([], $reviewProvider->authorizedTasks($readOnly));
        $this->assertSame([], $changeProvider->authorizedTasks($readOnly));

        $client->forceFill(['site_id' => $foreignSite->id])->save();
        $this->assertSame([], $reviewProvider->authorizedTasks($scopedManager));
        $this->assertSame([], $changeProvider->authorizedTasks($scopedManager));
    }

    public function test_changes_tasks_exclude_controlled_snapshot_and_current_identity_without_controlled_access(): void
    {
        ['actor' => $actor, 'site' => $site, 'client' => $client] = $this->context();
        $ordinary = $this->medication($client, 'Visible ordinary task medicine');
        $controlled = $this->medication($client, 'Secret controlled task medicine', true);
        $laterControlled = $this->medication($client, 'Secret newly controlled task medicine');
        $review = $this->book($actor, $client, 'triggered');
        $this->complete($actor, $review, ['items' => collect([$ordinary, $controlled, $laterControlled])->map(fn ($medicine) => [
            'client_medication_id' => $medicine->id, 'outcome' => 'change', 'recommendation' => 'Synthetic task recommendation.',
        ])->all()]);
        $laterControlled->forceFill(['controlled_drug' => true])->save();
        $ordinaryItem = MedicationReviewItem::query()->where('review_id', $review->id)->where('client_medication_id', $ordinary->id)->sole();
        $restricted = $this->userAt($site, ['medications.view', 'medications.reviews.manage']);
        $provider = app(MedicationReviewChangeProvider::class);

        $tasks = $provider->authorizedTasks($restricted);
        $this->assertSame(['medication_review_change-'.$ordinaryItem->id], collect($tasks)->pluck('id')->all());
        $this->assertStringNotContainsString('Secret', json_encode(collect($tasks)->map->toArray()->all()));
        $this->assertCount(3, $provider->authorizedTasks($actor));

        $this->actingAs($actor)->post("/emar/reviews/{$review->id}/items/{$ordinaryItem->id}/decision", [
            'revision' => $review->fresh()->revision, 'state' => 'agreed', 'prescriber_name' => 'Dr Synthetic Reviewer',
            'decision_date' => WorkerClock::today()->toDateString(), 'method' => 'phone', 'note' => 'Synthetic agreement.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $ordersOnly = $this->userAt($site, ['medications.view', 'medications.orders.manage']);
        $orderTasks = $provider->authorizedTasks($ordersOnly);
        $this->assertSame(['medication_review_change-'.$ordinaryItem->id], collect($orderTasks)->pluck('id')->all());
        $this->assertSame('Agreed — to enter in Orders', $orderTasks[0]->status);
    }

    public function test_leaving_the_service_closes_open_reviews_once_and_keeps_recorded_history(): void
    {
        foreach (['inactive', 'discharged', 'deceased'] as $departureStatus) {
            ['actor' => $actor, 'client' => $client] = $this->context();
            $recorded = $this->book($actor, $client, 'triggered');
            $this->complete($actor, $recorded);
            $recordedEvidence = $this->persistedEvidence($recorded);
            $regular = $this->book($actor, $client);
            $triggered = $this->book($actor, $client, 'triggered');

            $client->forceFill(['status' => $departureStatus])->save();

            foreach ([$regular, $triggered] as $openReview) {
                $this->assertSame('closed', $openReview->fresh()->status);
                $this->assertSame(2, $openReview->fresh()->revision);
                $closed = MedicationReviewEvent::query()->where('review_id', $openReview->id)->where('event', 'closed')->sole();
                $this->assertNull($closed->actor_id);
                $this->assertSame($departureStatus, $closed->details['service_status']);
                $this->assertStringContainsString('left the service', $closed->details['reason']);
            }
            $this->assertSame($recordedEvidence, $this->persistedEvidence($recorded));
            $this->assertNull($client->fresh()->next_chart_review_date);
            $client->forceFill(['first_name' => 'Synthetic departed person'])->save();
            $this->assertSame(2, MedicationReviewEvent::query()->where('client_id', $client->id)->where('event', 'closed')->count());
            $this->actingAs($actor)->postJson('/emar/reviews', $this->bookingPayload($actor, $client))
                ->assertUnprocessable()->assertJsonValidationErrors('client_id');
        }
    }

    public function test_review_summary_controlled_identity_and_source_are_redacted_from_reader_payloads(): void
    {
        Storage::fake('local');
        ['actor' => $actor, 'site' => $site, 'client' => $client] = $this->context();
        $ordinary = $this->medication($client, 'Visible synthetic medicine');
        $controlled = $this->medication($client, 'Secret synthetic controlled name', true);
        $review = $this->book($actor, $client, 'triggered');
        $this->complete($actor, $review, [
            'clinical_summary' => 'Private synthetic clinician summary mentions Secret synthetic controlled name.',
            'source' => UploadedFile::fake()->create('private-clinician-letter.pdf', 12, 'application/pdf'),
            'items' => [
                ['client_medication_id' => $ordinary->id, 'outcome' => 'continue'],
                ['client_medication_id' => $controlled->id, 'outcome' => 'change',
                    'recommendation' => 'Secret controlled recommendation.'],
            ],
        ]);
        $reader = $this->userAt($site, ['medications.view']);
        $managerWithoutControlled = $this->userAt($site, ['medications.view', 'medications.reviews.manage']);

        foreach ([$reader, $managerWithoutControlled] as $restrictedActor) {
            $response = $this->actingAs($restrictedActor)
                ->get("/emar/reviews?client_id={$client->id}&review={$review->id}&view=recorded")->assertOk();
            $props = json_encode($response->inertiaProps(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString('Visible synthetic medicine', $props);
            $this->assertStringContainsString('Controlled medicine', $props);
            $this->assertStringNotContainsString('Secret synthetic controlled name', $props);
            $this->assertStringNotContainsString('Secret controlled recommendation.', $props);
            $this->assertStringNotContainsString('Private synthetic clinician summary', $props);
            $this->assertStringNotContainsString('private-clinician-letter.pdf', $props);
            $this->assertStringNotContainsString($review->fresh()->source_path, $props);
            $this->actingAs($restrictedActor)->get("/emar/reviews/{$review->id}/source?download=1")
                ->assertNotFound();
        }
    }

    public function test_clinician_source_is_private_and_rechecks_exact_access_on_download(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        ['actor' => $actor, 'client' => $client] = $this->context();
        $this->medication($client, 'Synthetic controlled source medicine', true);
        $review = $this->book($actor, $client, 'triggered');
        $this->complete($actor, $review, [
            'source' => UploadedFile::fake()->create('synthetic-review-letter.pdf', 8, 'application/pdf'),
        ]);
        $review->refresh();

        $this->assertNotEmpty($review->source_path);
        $this->assertSame('synthetic-review-letter.pdf', $review->source_name);
        Storage::disk('local')->assertExists($review->source_path);
        Storage::disk('public')->assertMissing($review->source_path);
        $this->actingAs($actor)->get("/emar/reviews/{$review->id}/source?download=1")
            ->assertOk()->assertDownload('synthetic-review-letter.pdf');

        $otherSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $otherActor = $this->userAt($otherSite, [
            'medications.view', 'medications.reviews.manage', 'medications.controlled.view',
        ]);
        $this->actingAs($otherActor)->get("/emar/reviews/{$review->id}/source?download=1")
            ->assertNotFound();
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

    /** @return array{actor: User, site: Site, client: Client} */
    private function context(): array
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $actor = $this->userAt($site, [
            'medications.view', 'medications.reviews.manage', 'medications.controlled.view',
        ]);

        return compact('actor', 'site', 'client');
    }

    /** @param array<int, string> $permissions */
    private function userAt(Site $site, array $permissions, bool $personScoped = false): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        if (! $personScoped) {
            $permissions[] = 'clients.viewAny';
        }
        $keys = array_values(array_unique([
            ...$permissions, 'medications.view', 'medications.reviews.manage', 'medications.controlled.view',
            'medications.orders.manage', 'medications.orders.verify', 'clinical.accessAllSites', 'sites.viewAll',
            'clients.viewAny', 'medications.stock.update', 'medications.audit.view', 'medications.reports.export', 'reports.viewAny',
        ]));
        $permissionRows = Permission::query()->whereIn('key', $keys)->get();
        $this->assertCount(count($keys), $permissionRows, 'The review permission migration must be available.');
        $user->permissionOverrides()->sync($permissionRows->mapWithKeys(
            fn (Permission $permission) => [$permission->id => ['allowed' => in_array($permission->key, $permissions, true)]],
        )->all());
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => WorkerClock::today()->subYear()->toDateString(), 'end_date' => null, 'is_active' => true,
        ]);

        return $user;
    }

    private function medication(Client $client, string $name, bool $controlled = false): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => $name, 'dosage' => '10 mg',
            'frequency' => 'Once daily', 'frequency_code' => 'daily', 'dose_times' => ['08:00'],
            'route' => 'oral', 'start_date' => WorkerClock::today()->subMonth()->toDateString(),
            'end_date' => null, 'controlled_drug' => $controlled, 'is_prn' => false,
            'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'version' => 1,
        ]);
    }

    private function orderVersion(User $actor, ClientMedication $medicine, int $number, string $dosage, string $reason): MedicationOrderVersion
    {
        return MedicationOrderVersion::query()->create([
            'client_medication_id' => $medicine->id, 'client_id' => $medicine->client_id, 'version_number' => $number,
            'name' => $medicine->name, 'dosage' => $dosage, 'frequency' => $medicine->frequency,
            'dose_times' => $medicine->dose_times, 'route' => $medicine->route,
            'controlled_drug' => $medicine->controlled_drug, 'active' => true, 'state' => 'active',
            'change_reason' => $reason, 'changed_by' => $actor->id, 'changed_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function bookingPayload(User $actor, Client $client, string $type = 'regular'): array
    {
        return [
            'client_id' => $client->id, 'review_type' => $type,
            'scheduled_date' => WorkerClock::today()->addWeek()->toDateString(), 'owner_id' => $actor->id,
            ...($type === 'triggered' ? ['trigger_code' => 'hospital', 'trigger_reason' => 'Synthetic discharge review.'] : []),
        ];
    }

    private function book(User $actor, Client $client, string $type = 'regular', array $overrides = []): MedicationReview
    {
        $response = $this->actingAs($actor)->postJson('/emar/reviews', [
            ...$this->bookingPayload($actor, $client, $type), ...$overrides,
        ])->assertOk()->assertJsonPath('saved', true)->assertJsonStructure(['message', 'review_id']);

        $review = MedicationReview::query()->findOrFail($response->json('review_id'));
        $this->assertSame($client->id, $review->client_id);
        $this->assertSame($type, $review->review_type);

        return $review;
    }

    private function move(User $actor, MedicationReview $review, string $date, string $code, string $reason): void
    {
        $this->actingAs($actor)->putJson("/emar/reviews/{$review->id}", [
            'revision' => $review->fresh()->revision, 'scheduled_date' => $date,
            'reason_code' => $code, 'reason' => $reason,
        ])->assertOk()->assertJsonPath('saved', true)->assertJsonPath('review_id', $review->id);
    }

    /** @return array<string, mixed> */
    private function completionPayload(MedicationReview $review, array $overrides = []): array
    {
        return [
            'revision' => $review->fresh()->revision, 'completed_date' => WorkerClock::today()->toDateString(),
            'reviewer_name' => 'Dr Synthetic Reviewer', 'reviewer_role' => 'GP', 'review_location' => 'house',
            'participants' => ['person' => 'took', 'whanau' => 'told', 'whanau_detail' => 'Synthetic guardian informed.'],
            'clinical_summary' => 'Synthetic clinician summary.',
            'items' => ClientMedication::query()->where('client_id', $review->client_id)->where('active', true)
                ->get()->map(fn (ClientMedication $medication) => [
                    'client_medication_id' => $medication->id, 'outcome' => 'continue',
                ])->all(),
            ...$overrides,
        ];
    }

    private function complete(User $actor, MedicationReview $review, array $overrides = []): void
    {
        $payload = $this->completionPayload($review, $overrides);
        if (isset($overrides['source'])) {
            $this->actingAs($actor)->post("/emar/reviews/{$review->id}/complete", $payload)
                ->assertRedirect()->assertSessionHasNoErrors();

            return;
        }
        $this->actingAs($actor)->postJson("/emar/reviews/{$review->id}/complete", $payload)
            ->assertOk()->assertJsonPath('saved', true)->assertJsonPath('review_id', $review->id);
    }
}
