<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationEvent;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationReview;
use App\Models\MedicationReviewEvent;
use App\Models\MedicationReviewItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\WorkerClock;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Review evidence, prescriber decisions and cadence never publish an order change. */
class ReviewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->travelTo(CarbonImmutable::parse('2026-10-02T11:15:00Z'));
        $this->seed(RbacSeeder::class);
    }

    private function seedReviews(): array
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $user = $this->currentReviewStaffAt($site, roleName: 'admin');

        return compact('user', 'site', 'client');
    }

    public function test_page_serves_the_scoped_house_and_structured_deprescribing_pipeline(): void
    {
        ['user' => $user, 'site' => $site, 'client' => $client] = $this->seedReviews();
        $diazepam = $this->checkedMedication($client, $user, 'Diazepam', controlled: true);
        $paracetamol = $this->checkedMedication($client, $user, 'Paracetamol');
        $review = $this->review($client, [
            'status' => 'completed', 'scheduled_date' => WorkerClock::today()->subWeek(),
            'completed_date' => WorkerClock::today(), 'clinical_summary' => 'Polypharmacy reduced.',
        ]);
        $stop = $this->item($review, $diazepam, 'stop', 'Falls risk; prescriber asked to stop.');
        $this->item($review, $paracetamol, 'continue');

        $this->actingAs($user)->get(route('emar.reviews', ['site_id' => $site->id, 'view' => 'changes']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('emar/reviews/index')
                ->where('filters.site_id', $site->id)
                ->where('sites', fn ($sites) => collect($sites)->contains(fn ($row) => $row['id'] === $site->id && $row['name'] === $site->name))
                ->has('reviews.data', 1)
                ->where('reviews.data.0.id', $review->id)
                ->where('reviews.data.0.site_id', $site->id)
                ->has('reviews.data.0.items', 2)
                ->where('reviews.data.0.items.0.id', $stop->id)
                ->where('reviews.data.0.items.0.name', 'Diazepam')
                ->where('reviews.data.0.items.0.outcome', 'stop')
                ->where('reviews.data.0.items.0.decision', 'waiting')
                ->where('reviews.data.0.items.0.order_url', null)
                ->where('reviews.data.0.items.1.outcome', 'continue')
                ->where('meters.changes', 1)
                ->where('meters.waiting_prescriber', 1)
                ->where('meters.changes_to_make', 0)
                ->where('can.manage', true)
                ->where('default_interval.months', 3)
                ->where('default_interval.reviewed', false)
                ->missing('deprescribing')->missing('kpis')
            );
    }

    public function test_review_payload_identifies_the_person_for_scoped_mar_navigation(): void
    {
        // The page builds the MAR/clinical/reviews link from client_id. Its row,
        // selected review and person adapter must agree on that identity.
        ['user' => $user, 'site' => $site, 'client' => $client] = $this->seedReviews();
        $review = $this->review($client);
        $this->actingAs($user)->get(route('emar.reviews', [
            'site_id' => $site->id, 'client_id' => $client->id, 'review' => $review->id,
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('emar/reviews/index')->has('reviews.data', 1)
            ->where('reviews.data.0.client_id', $client->id)
            ->where('selected.id', $review->id)->where('selected.client_id', $client->id)
            ->where('selected.site_id', $site->id)->where('person.id', $client->id)
            ->where('person.name', trim($client->first_name.' '.$client->last_name))
            ->where('person.next_review_date', $review->scheduled_date->toDateString())
        );
    }

    public function test_complete_review_stores_dbi_outcomes_actor_and_next_regular_date_without_changing_orders(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedReviews();
        $medicine = $this->checkedMedication($client, $user, 'Zopiclone', controlled: true);
        $orderBefore = $this->persistedEvidence($medicine);
        $version = MedicationOrderVersion::query()->where('client_medication_id', $medicine->id)->sole();
        $versionBefore = $this->persistedEvidence($version);
        $review = $this->review($client, ['owner_id' => $user->id]);
        $recommendation = 'Reduce to 5 mg, subject to the prescriber decision and checked Orders.';
        $this->actingAs($user)->postJson(route('emar.reviews.complete', $review), $this->completionPayload($review, [
            'clinical_summary' => 'Reviewed all medicines.', 'drug_burden_index' => 1.5, 'falls_last_quarter' => 2,
            'items' => [['client_medication_id' => $medicine->id, 'outcome' => 'change', 'recommendation' => $recommendation]],
        ]))->assertOk()->assertJsonPath('saved', true)->assertJsonPath('review_id', $review->id);

        $review->refresh();
        $this->assertSame('completed', $review->status);
        $this->assertSame('1.50', (string) $review->drug_burden_index);
        $this->assertSame(2, $review->falls_last_quarter);
        $this->assertSame($user->id, $review->completed_by);
        $this->assertSame(2, $review->revision);
        $this->assertSame('2026-10-02T11:10:00+00:00', $review->happened_at->toIso8601String());
        $item = $review->items()->sole();
        $this->assertSame('change', $item->outcome);
        $this->assertSame($recommendation, $item->recommendation);
        $this->assertSame('waiting', $item->decision);
        $this->assertTrue($item->controlled_snapshot);
        $this->assertSame($version->id, $item->order_version_id);
        $this->assertNull($item->linked_order_version_id);
        $this->assertSame($orderBefore, $this->persistedEvidence($medicine));
        $this->assertSame($versionBefore, $this->persistedEvidence($version));
        $this->assertSame(1, MedicationOrderVersion::query()->where('client_medication_id', $medicine->id)->count());
        $nextDate = WorkerClock::today()->addMonthsNoOverflow(3)->toDateString();
        $next = MedicationReview::query()->where('client_id', $client->id)->where('status', 'scheduled')->sole();
        $this->assertSame('regular', $next->review_type);
        $this->assertSame($user->id, $next->owner_id);
        $this->assertSame($nextDate, $next->scheduled_date->toDateString());
        $this->assertSame($nextDate, $review->next_review_date->toDateString());
        $this->assertSame($nextDate, $client->fresh()->next_chart_review_date->toDateString());
        $event = $review->events()->where('event', 'completed')->sole();
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame($recommendation, data_get($event->details, 'items.0.recommendation'));
        $this->assertDatabaseHas('medication_events', [
            'kind' => 'review.completed', 'actor_id' => $user->id,
            'subject_id' => (string) $review->id, 'client_id' => $client->id,
        ]);
    }

    public function test_legacy_action_advance_truthfully_refuses_and_keeps_all_clinical_evidence(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedReviews();
        $actions = [['drug' => 'Diazepam', 'action' => 'Stop', 'rationale' => 'Falls risk', 'gp_status' => 'pending', 'stage' => 'gp']];
        $review = $this->review($client, [
            'review_type' => 'comprehensive', 'status' => 'completed', 'completed_date' => WorkerClock::today(),
            'clinical_summary' => 'Original clinical evidence.', 'medications_reviewed' => ['Diazepam'],
            'recommendations' => 'The prescriber must decide.', 'actions' => $actions,
            'whanau_involved' => true, 'whanau_notes' => 'Original discussion.',
        ]);
        $before = $this->reviewEvidence($review);
        $auditCount = AuditLog::query()->count();
        $chainCount = MedicationEvent::query()->count();
        $this->actingAs($user)->postJson(route('emar.reviews.actions.advance', $review), ['index' => 0])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Record the prescriber’s decision on the recommendation, then enter and check the change through Orders.')
            ->assertJsonMissing(['saved' => true]);
        $this->assertSame($before, $this->reviewEvidence($review));
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertSame($chainCount, MedicationEvent::query()->count());
        $this->actingAs($user)->get(route('emar.reviews', ['review' => $review->id, 'view' => 'recorded']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('selected.legacy_outcomes', $actions)
                ->where('selected.legacy_medications_reviewed', ['Diazepam'])
                ->where('selected.legacy_recommendations', 'The prescriber must decide.')
                ->where('selected.clinical_summary', 'Original clinical evidence.')
            );
    }

    public function test_prescriber_agreement_is_audited_separately_and_leaves_checked_orders_unchanged(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedReviews();
        $medicine = $this->checkedMedication($client, $user, 'Synthetic medicine');
        $orderBefore = $this->persistedEvidence($medicine);
        $review = $this->review($client, [
            'status' => 'completed', 'completed_date' => WorkerClock::today(),
            'reviewer_name' => 'Dr Synthetic Reviewer', 'reviewer_role' => 'GP',
        ]);
        $item = $this->item($review, $medicine, 'change', 'Reduce to 5 mg.');
        $this->actingAs($user)->postJson(route('emar.reviews.decision', [$review, $item->id]), [
            'revision' => 1, 'state' => 'agreed', 'prescriber_name' => 'Dr Synthetic Reviewer',
            'decision_date' => WorkerClock::today()->toDateString().'T00:11', 'method' => 'review',
            'note' => 'Agreed at the review; enter and check through Orders.',
        ])->assertOk()->assertJsonPath('saved', true);

        $this->assertSame('agreed', $item->fresh()->decision);
        $this->assertSame(2, $review->fresh()->revision);
        $this->assertNull($item->fresh()->linked_order_version_id);
        $this->assertSame($orderBefore, $this->persistedEvidence($medicine));
        $this->assertSame(1, MedicationOrderVersion::query()->where('client_medication_id', $medicine->id)->count());
        $event = $review->events()->where('event', 'prescriber_decision')->sole();
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame('waiting', data_get($event->details, 'before.decision'));
        $this->assertSame('agreed', data_get($event->details, 'after.decision'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'medications.review.prescriber_decision', 'user_id' => $user->id,
            'auditable_type' => MedicationReview::class, 'auditable_id' => $review->id,
        ]);
        $this->actingAs($user)->get(route('emar.reviews', ['view' => 'changes', 'review' => $review->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('meters.changes_to_make', 1)
                ->where('selected.items.0.order_url', '/emar/prescriptions?client_id='.$client->id.'&review_item='.$item->id)
                ->where('selected.items.0.linked_order_version_id', null)
            );
    }

    public function test_booking_owners_are_current_review_staff_at_the_exact_persons_house(): void
    {
        ['user' => $user, 'site' => $site, 'client' => $client] = $this->seedReviews();
        $owner = $this->currentReviewStaffAt($site);
        $foreignSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $foreignOwner = $this->currentReviewStaffAt($foreignSite);
        $endedOwner = $this->currentReviewStaffAt($site, ['end_date' => WorkerClock::today()->subDay()]);
        $missingOwnerId = (int) User::query()->max('id') + 1000;
        $withoutReviewAuthority = $this->currentReviewStaffAt($site, roleName: 'support_worker');
        $createPayload = $this->bookingPayload($owner, $client);
        foreach ([$missingOwnerId, $endedOwner->id] as $concealedOwnerId) {
            $this->actingAs($user)->postJson(route('emar.reviews.store'), [
                ...$createPayload, 'owner_id' => $concealedOwnerId,
            ])->assertNotFound();
        }
        foreach ([$foreignOwner, $withoutReviewAuthority] as $ineligibleOwner) {
            $this->actingAs($user)->postJson(route('emar.reviews.store'), [
                ...$createPayload, 'owner_id' => $ineligibleOwner->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('owner_id');
        }
        $this->assertDatabaseCount('medication_reviews', 0);
        $this->assertDatabaseCount('medication_review_events', 0);
        $response = $this->actingAs($user)->postJson(route('emar.reviews.store'), $createPayload)
            ->assertOk()->assertJsonPath('saved', true);
        $review = MedicationReview::query()->findOrFail($response->json('review_id'));
        $this->assertSame($owner->id, $review->owner_id);
        $this->assertSame($user->id, $review->requested_by);
        $this->assertNull($review->reviewer_user_id);
        $booked = $review->events()->where('event', 'booked')->sole();
        $this->assertSame($user->id, $booked->actor_id);
        $this->assertSame($owner->id, data_get($booked->details, 'owner_id'));

        // A move cannot resurrect the retired reviewer-user/owner reassignment writer.
        $this->actingAs($user)->putJson(route('emar.reviews.update', $review), [
            'revision' => 1, 'scheduled_date' => WorkerClock::today()->addWeeks(2)->toDateString(),
            'reason_code' => 'clinician', 'reason' => 'Clinician appointment changed.',
            'owner_id' => $foreignOwner->id, 'reviewer_user_id' => $foreignOwner->id,
        ])->assertOk()->assertJsonPath('saved', true);
        $this->assertSame($owner->id, $review->fresh()->owner_id);
        $this->assertNull($review->fresh()->reviewer_user_id);
        $this->assertSame(2, $review->fresh()->revision);
    }

    public function test_triggered_cancellation_requires_a_bounded_reason_and_strictly_audits_the_transition(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedReviews();
        $regular = $this->review($client);
        $review = $this->review($client, ['review_type' => 'triggered', 'trigger_code' => 'health']);
        $this->actingAs($user)->deleteJson(route('emar.reviews.destroy', $regular), [
            'revision' => 1, 'reason' => 'Do not remove the regular review cycle.',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
        foreach (['', 'ab', str_repeat('x', 2001)] as $invalidReason) {
            $this->actingAs($user)->deleteJson(route('emar.reviews.destroy', $review), [
                'revision' => 1, 'reason' => $invalidReason,
            ])->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $this->assertSame('scheduled', $review->fresh()->status);
        $this->assertSame(1, $review->fresh()->revision);
        $this->assertDatabaseCount('medication_review_events', 0);
        $reason = 'The triggered review was scheduled against the wrong clinical episode.';
        $this->actingAs($user)->deleteJson(route('emar.reviews.destroy', $review), [
            'revision' => 1, 'reason' => $reason,
        ])->assertOk()->assertJsonPath('saved', true);
        $this->assertSame('cancelled', $review->fresh()->status);
        $this->assertSame(2, $review->fresh()->revision);
        $this->assertSame('scheduled', $regular->fresh()->status);
        $this->assertSame($regular->scheduled_date->toDateString(), $client->fresh()->next_chart_review_date->toDateString());
        $event = $review->events()->where('event', 'cancelled')->sole();
        $this->assertSame($reason, data_get($event->details, 'reason'));
        $this->assertSame($user->id, $event->actor_id);
        $audit = AuditLog::query()->where('action', 'medications.review.cancelled')
            ->where('auditable_type', MedicationReview::class)->where('auditable_id', $review->id)->sole();
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame($event->id, data_get($audit->meta, 'event_id'));
        $this->assertArrayNotHasKey('reason', $audit->meta);

        $rollbackReview = $this->review($client, ['review_type' => 'triggered', 'trigger_code' => 'asked']);
        $before = $this->reviewEvidence($rollbackReview);
        $chainCount = MedicationEvent::query()->count();
        $injectFailure = true;
        AuditLog::creating(function (AuditLog $log) use (&$injectFailure): void {
            if ($injectFailure && $log->action === 'medications.review.cancelled') {
                throw new \RuntimeException('Injected medication review cancellation audit failure.');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->deleteJson(route('emar.reviews.destroy', $rollbackReview), [
                'revision' => 1, 'reason' => 'This cancellation must roll back with its audit evidence.',
            ]);
            $this->fail('The cancellation audit failure did not escape the transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected medication review cancellation audit failure.', $exception->getMessage());
        } finally {
            $injectFailure = false;
            $this->withExceptionHandling();
        }
        $this->assertSame($before, $this->reviewEvidence($rollbackReview));
        $this->assertSame($chainCount, MedicationEvent::query()->count());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medications.review.cancelled', 'auditable_type' => MedicationReview::class,
            'auditable_id' => $rollbackReview->id,
        ]);
    }

    public function test_terminal_clinical_evidence_is_read_only_and_legacy_advance_is_always_held(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedReviews();
        $actions = [['drug' => 'Diazepam', 'action' => 'Stop', 'gp_status' => 'pending', 'stage' => 'gp']];
        $completed = $this->review($client, [
            'status' => 'completed', 'completed_date' => WorkerClock::today(),
            'clinical_summary' => 'Original completed evidence.', 'actions' => $actions,
        ]);
        $cancelled = $this->review($client, ['review_type' => 'triggered', 'status' => 'cancelled', 'actions' => $actions]);
        $scheduled = $this->review($client, ['actions' => $actions]);
        $before = collect([$completed, $cancelled, $scheduled])
            ->mapWithKeys(fn (MedicationReview $review) => [$review->id => $this->reviewEvidence($review)])->all();
        $auditCount = AuditLog::query()->count();
        $chainCount = MedicationEvent::query()->count();
        foreach ([$completed, $cancelled] as $terminal) {
            $this->actingAs($user)->putJson(route('emar.reviews.update', $terminal), [
                'revision' => 1, 'scheduled_date' => WorkerClock::today()->addWeek()->toDateString(),
                'reason_code' => 'other', 'reason' => 'Attempted terminal rewrite.',
            ])->assertUnprocessable()->assertJsonValidationErrors('review');
            $this->actingAs($user)->postJson(route('emar.reviews.complete', $terminal), $this->completionPayload($terminal, [
                'clinical_summary' => 'Rewritten clinical evidence.',
            ]))->assertUnprocessable()->assertJsonValidationErrors('review');
            $this->actingAs($user)->putJson(route('emar.reviews.appointment', $terminal), ['revision' => 1])
                ->assertUnprocessable()->assertJsonValidationErrors('review');
            $this->actingAs($user)->deleteJson(route('emar.reviews.destroy', $terminal), [
                'revision' => 1, 'reason' => 'Attempted terminal rewrite.',
            ])->assertUnprocessable()->assertJsonValidationErrors('review');
        }
        foreach ([$completed, $scheduled, $cancelled] as $held) {
            $this->actingAs($user)->postJson(route('emar.reviews.actions.advance', $held), ['index' => 0])
                ->assertUnprocessable()
                ->assertJsonPath('message', 'Record the prescriber’s decision on the recommendation, then enter and check the change through Orders.');
            $this->assertSame($before[$held->id], $this->reviewEvidence($held));
        }
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertSame($chainCount, MedicationEvent::query()->count());
    }

    public function test_order_management_does_not_authorize_review_mutations(): void
    {
        ['site' => $site, 'client' => $client] = $this->seedReviews();
        $ordersOnly = $this->currentReviewStaffAt($site, roleName: 'coordinator');
        $permission = Permission::query()->where('key', 'medications.reviews.manage')->sole();
        // Restrict a naturally authorized Orders role; no new grant is introduced.
        $ordersOnly->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        $ordersOnly = $ordersOnly->fresh();
        $this->assertTrue($ordersOnly->canDo('medications.orders.manage'));
        $this->assertFalse($ordersOnly->canDo('medications.reviews.manage'));
        $review = $this->review($client);
        $before = $this->reviewEvidence($review);
        $count = MedicationReview::query()->count();
        $this->actingAs($ordersOnly)->postJson(route('emar.reviews.store'), $this->bookingPayload($ordersOnly, $client))->assertForbidden();
        $this->actingAs($ordersOnly)->putJson(route('emar.reviews.update', $review), ['revision' => 1])->assertForbidden();
        $this->actingAs($ordersOnly)->putJson(route('emar.reviews.appointment', $review), ['revision' => 1])->assertForbidden();
        $this->actingAs($ordersOnly)->postJson(route('emar.reviews.complete', $review), $this->completionPayload($review))->assertForbidden();
        $this->actingAs($ordersOnly)->postJson(route('emar.reviews.actions.advance', $review), ['index' => 0])->assertForbidden();
        $this->actingAs($ordersOnly)->deleteJson(route('emar.reviews.destroy', $review), ['revision' => 1])->assertForbidden();
        $this->assertSame($before, $this->reviewEvidence($review));
        $this->assertSame($count, MedicationReview::query()->count());
    }

    public function test_review_mutations_are_confined_to_the_actors_approved_sites(): void
    {
        $siteA = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $siteB = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $user = $this->currentReviewStaffAt($siteA);
        $this->assertTrue($user->canDo('medications.reviews.manage'));
        $this->assertFalse($user->canDo('clinical.accessAllSites'));
        $this->assertFalse($user->canDo('sites.viewAll'));
        $clientA = Client::factory()->create(['site_id' => $siteA->id, 'status' => 'active']);
        $clientB = Client::factory()->create([
            'site_id' => $siteB->id, 'status' => 'active', 'next_chart_review_date' => WorkerClock::today()->addYear(),
        ]);
        // Assignment alone must not allow a person at an unapproved house.
        $clientA->supportWorkers()->attach($user->id);
        $clientB->supportWorkers()->attach($user->id);
        $foreignReview = $this->review($clientB, [
            'actions' => [['drug' => 'Foreign drug', 'action' => 'Stop', 'stage' => 'gp']],
            'clinical_summary' => 'Foreign clinical evidence must not change.',
        ]);
        $foreignBefore = $this->reviewEvidence($foreignReview);
        $foreignClientBefore = $this->persistedEvidence($clientB);
        $auditCount = AuditLog::query()->count();
        $chainCount = MedicationEvent::query()->count();
        $this->actingAs($user)->postJson(route('emar.reviews.store'), $this->bookingPayload($user, $clientB))->assertNotFound();
        $this->actingAs($user)->putJson(route('emar.reviews.update', $foreignReview), ['scheduled_date' => 'not-a-date'])->assertNotFound();
        $this->actingAs($user)->putJson(route('emar.reviews.appointment', $foreignReview), [])->assertNotFound();
        $this->actingAs($user)->postJson(route('emar.reviews.complete', $foreignReview), [])->assertNotFound();
        $this->actingAs($user)->postJson(route('emar.reviews.actions.advance', $foreignReview), [])->assertNotFound();
        $this->actingAs($user)->deleteJson(route('emar.reviews.destroy', $foreignReview), [])->assertNotFound();
        $this->actingAs($user)->putJson(route('emar.clients.review_interval', $clientB), [])->assertNotFound();
        $this->assertDatabaseCount('medication_reviews', 1);
        $this->assertSame($foreignBefore, $this->reviewEvidence($foreignReview));
        $this->assertSame($foreignClientBefore, $this->persistedEvidence($clientB));
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertSame($chainCount, MedicationEvent::query()->count());

        $response = $this->actingAs($user)->postJson(route('emar.reviews.store'), $this->bookingPayload($user, $clientA))
            ->assertOk()->assertJsonPath('saved', true);
        $local = MedicationReview::query()->findOrFail($response->json('review_id'));
        $this->assertSame($user->id, $local->owner_id);
        $this->assertSame($user->id, $local->requested_by);
        $this->actingAs($user)->putJson(route('emar.reviews.update', $local), [
            'revision' => 1, 'scheduled_date' => WorkerClock::today()->addWeeks(2)->toDateString(),
            'reason_code' => 'clinician', 'reason' => 'Site A clinician appointment moved.',
        ])->assertOk()->assertJsonPath('saved', true);
        $moved = $local->events()->where('event', 'moved')->sole();
        $this->assertSame($user->id, $moved->actor_id);
        $this->assertSame('Site A clinician appointment moved.', data_get($moved->details, 'reason'));
        $this->assertSame(2, $local->fresh()->revision);
        $nextReviewDate = WorkerClock::today()->addMonthsNoOverflow(2)->toDateString();
        $this->actingAs($user)->postJson(route('emar.reviews.complete', $local), $this->completionPayload($local, [
            'clinical_summary' => 'Site A review complete.', 'earlier_review_date' => $nextReviewDate,
        ]))->assertOk()->assertJsonPath('saved', true);
        $this->assertSame('completed', $local->fresh()->status);
        $this->assertSame($user->id, $local->fresh()->completed_by);
        $this->assertSame($nextReviewDate, $clientA->fresh()->next_chart_review_date->toDateString());
        $next = MedicationReview::query()->where('client_id', $clientA->id)->where('status', 'scheduled')->sole();
        $this->assertSame('regular', $next->review_type);
        $this->assertSame($nextReviewDate, $next->scheduled_date->toDateString());
        $cancellable = $this->review($clientA, ['review_type' => 'triggered', 'trigger_code' => 'asked']);
        $this->actingAs($user)->deleteJson(route('emar.reviews.destroy', $cancellable), [
            'revision' => 1, 'reason' => 'This duplicate triggered review was created in error.',
        ])->assertOk()->assertJsonPath('saved', true);
        $this->assertSame('cancelled', $cancellable->fresh()->status);
        $this->assertSame('scheduled', $next->fresh()->status);
        $this->assertSame($nextReviewDate, $clientA->fresh()->next_chart_review_date->toDateString());
        $this->assertSame($foreignBefore, $this->reviewEvidence($foreignReview));
        $this->assertSame($foreignClientBefore, $this->persistedEvidence($clientB));
    }

    private function currentReviewStaffAt(Site $site, array $profileOverrides = [], string $roleName = 'team_lead'): User
    {
        $staff = $this->makeRoleUser($roleName);
        HrEmployeeProfile::factory()->create([
            'user_id' => $staff->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => WorkerClock::today()->subMonth(), 'end_date' => null, 'is_active' => true,
            ...$profileOverrides,
        ]);

        return $staff;
    }

    private function review(Client $client, array $attributes = []): MedicationReview
    {
        return MedicationReview::query()->create([
            'client_id' => $client->id, 'review_type' => 'regular', 'status' => 'scheduled',
            'scheduled_date' => WorkerClock::today(), 'revision' => 1, ...$attributes,
        ]);
    }

    private function checkedMedication(Client $client, User $enterer, string $name, bool $controlled = false): ClientMedication
    {
        $checker = $this->currentReviewStaffAt($client->site);
        $medicine = ClientMedication::query()->create([
            'client_id' => $client->id, 'created_by' => $enterer->id, 'name' => $name,
            'dosage' => '10 mg', 'frequency' => 'Once daily', 'frequency_code' => 'daily',
            'dose_times' => ['08:00'], 'route' => 'oral', 'start_date' => WorkerClock::today()->subMonth(),
            'end_date' => null, 'controlled_drug' => $controlled, 'high_risk' => false,
            'witness_required' => false, 'is_prn' => false, 'active' => true, 'state' => 'active', 'version' => 1,
        ]);
        // A pre-existing independently checked chart fixture, never a review write.
        $medicine->forceFill([
            'approval_status' => 'verified', 'verified_by' => $checker->id, 'verified_at' => now()->subDay(),
        ])->saveQuietly();
        MedicationOrderVersion::query()->create([
            'client_medication_id' => $medicine->id, 'client_id' => $client->id, 'version_number' => 1,
            'name' => $name, 'dosage' => $medicine->dosage, 'frequency' => $medicine->frequency,
            'frequency_code' => $medicine->frequency_code, 'dose_times' => $medicine->dose_times,
            'route' => $medicine->route, 'start_date' => $medicine->start_date,
            'controlled_drug' => $controlled, 'high_risk' => false, 'witness_required' => false, 'is_prn' => false,
            'active' => true, 'state' => 'active', 'change_reason' => 'Existing checked prescription fixture.',
            'changed_by' => $enterer->id, 'changed_at' => now()->subDay(),
        ]);

        return $medicine->fresh();
    }

    private function item(MedicationReview $review, ClientMedication $medicine, string $outcome, ?string $recommendation = null): MedicationReviewItem
    {
        return $review->items()->create([
            'client_id' => $review->client_id, 'client_medication_id' => $medicine->id,
            'order_version_id' => MedicationOrderVersion::query()->where('client_medication_id', $medicine->id)->value('id'),
            'name_snapshot' => $medicine->name, 'controlled_snapshot' => $medicine->controlled_drug,
            'classification_pending' => false, 'outcome' => $outcome, 'recommendation' => $recommendation,
            'decision' => 'waiting',
        ]);
    }

    private function bookingPayload(User $owner, Client $client): array
    {
        return [
            'request_uuid' => (string) Str::uuid(), 'client_id' => $client->id, 'review_type' => 'regular',
            'scheduled_date' => WorkerClock::today()->addWeek()->toDateString(), 'owner_id' => $owner->id,
        ];
    }

    private function completionPayload(MedicationReview $review, array $overrides = []): array
    {
        return [
            'revision' => $review->fresh()->revision, 'completed_date' => WorkerClock::today()->toDateString(),
            'completed_time' => '00:10', 'reviewer_name' => 'Dr Synthetic Reviewer', 'reviewer_role' => 'GP',
            'review_location' => 'house', 'clinical_summary' => 'Synthetic clinician summary.',
            'participants' => ['person' => 'took', 'whanau' => 'told', 'whanau_detail' => 'Synthetic whānau informed.'],
            'items' => ClientMedication::query()->current()->active()->where('client_id', $review->client_id)
                ->get()->map(fn (ClientMedication $medicine) => ['client_medication_id' => $medicine->id, 'outcome' => 'continue'])->all(),
            ...$overrides,
        ];
    }

    private function reviewEvidence(MedicationReview $review): array
    {
        return [
            'review' => $this->persistedEvidence($review),
            'items' => $review->items()->orderBy('id')->get()
                ->map(fn (MedicationReviewItem $item) => $this->persistedEvidence($item))->all(),
            'events' => $review->events()->get()
                ->map(fn (MedicationReviewEvent $event) => $this->persistedEvidence($event))->all(),
        ];
    }

    /** All persisted columns, including private source/request/digest evidence. */
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

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $role = Role::query()->where('name', $roleName)->sole();
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }
}
