<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Events\Respite\RespiteEvent;
use App\Models\BehaviourSupportPlan;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\RespiteBooking;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\RestraintEventAttachment;
use App\Models\RestraintSourceDelivery;
use App\Models\Site;
use App\Services\HealthSafety\RestraintRegisterService;
use App\Services\HealthSafety\RestraintSourceDeliveryService;
use App\Support\HealthSafety\RestraintReturn;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/** Entry parity uses actual HTTP commands and retained source records, never serial command mocks. */
final class RestraintEntryParityTest extends RestraintEventCommandTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = $this->staff(['restraints.view', 'restraints.create', 'restraints.review', 'restraints.manage', 'clients.viewAny', 'incidents.viewAny', 'incidents.update', 'respite.viewAny', 'respite.stays.manage']);
    }

    private function stay(?Client $client = null, ?Site $site = null): RespiteStay
    {
        $client ??= $this->client;
        $booking = RespiteBooking::factory()->create(['client_id' => $client->id, 'location_id' => ($site ?? $this->site)->id, 'status' => 'confirmed', 'start_at' => now()->subDay(), 'end_at' => now()->addDay()]);

        return RespiteStay::create(['booking_id' => $booking->id, 'client_id' => $client->id, 'status' => 'active', 'actual_start' => now()->subDay(), 'created_by' => $this->actor->id]);
    }

    private function payload(array $values = []): array
    {
        return $this->capture(['started_at' => now()->subHours(2)->timezone('Pacific/Auckland')->toIso8601String(), 'ended_at' => now()->subHour()->timezone('Pacific/Auckland')->toIso8601String(), ...$values]);
    }

    /*
     * Decision 7 (Stephan, 25 Oct): the stay post (`respite.stays.restraints.store`) is main's
     * respite form for this merge: main's payload, no idempotency key, receipt or replay. Parity
     * with Codex's keyed stay recorder is a logged follow-up, so these cases check the shared
     * keyed entry and main's form each on its own terms; both still reach H&S review.
     */
    public function test_both_http_entries_record_same_canonical_client_site_stay_and_revision_contract(): void
    {
        $stay = $this->stay();
        $shared = $this->payload(['site_id' => $this->site->id, 'stay_id' => $stay->id]);
        $this->actingAs($this->actor)->postJson(route('health-safety.restraints.events.store'), $shared)->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('restraint_confirmation.version', 1)->assertJsonPath('restraint_confirmation.source_revision', 1);
        $this->post(route('respite.stays.restraints.store', $stay), $this->payload())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('restraint_events', 2);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
        $keyed = RestraintEvent::where('creation_key', $shared['idempotency_key'])->sole();
        $this->assertSame($this->client->id, $keyed->recorded_client_id);
        $this->assertSame($this->site->id, $keyed->recorded_site_id);
        $this->assertSame($stay->id, $keyed->recorded_stay_id);
        $this->assertSame(1, $keyed->source_revision);
        $this->assertSame('missing', $keyed->plan_authorization_state);
        foreach (RestraintEvent::all() as $event) {
            $this->assertSame($this->client->id, $event->client_id);
            $this->assertSame($this->site->id, $event->site_id);
            $this->assertSame($stay->id, $event->stay_id);
            $intent = RestraintSourceDelivery::where('restraint_event_id', $event->id)->where('purpose', 'source_review')->sole();
            $this->assertSame('pending', $intent->status);
            app(RestraintSourceDeliveryService::class)->deliver($intent->id);
            $this->assertSame('delivered', $intent->fresh()->status);
            $this->assertDatabaseHas('hs_events', ['source_type' => RestraintEvent::class, 'source_id' => $event->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id]);
        }
    }

    public function test_shared_entry_replays_its_exact_uuid_and_respite_form_emits_its_downstream_event_once(): void
    {
        Event::fake([RespiteEvent::class]);
        $stay = $this->stay();
        $this->actingAs($this->actor)->post(route('respite.stays.restraints.store', $stay), $this->payload())->assertRedirect()->assertSessionHasNoErrors();
        Event::assertDispatchedTimes(RespiteEvent::class, 1);
        $this->assertDatabaseCount('restraint_events', 1);
        $this->assertDatabaseCount('restraint_command_receipts', 0);
        $shared = $this->payload(['stay_id' => $stay->id, 'site_id' => $this->site->id]);
        $first = $this->postJson(route('health-safety.restraints.events.store'), $shared)->assertOk()->json('restraint_confirmation');
        $this->postJson(route('health-safety.restraints.events.store'), $shared)->assertOk()->assertJsonPath('restraint_confirmation.event_id', $first['event_id'])->assertJsonPath('restraint_confirmation.replayed', true);
        $this->assertDatabaseCount('restraint_events', 2);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
        Event::assertDispatchedTimes(RespiteEvent::class, 1);
    }

    public function test_respite_only_capture_authority_does_not_grant_shared_capture_or_register_read(): void
    {
        $stay = $this->stay();
        $actor = $this->staff(['clients.viewAny', 'respite.viewAny', 'respite.stays.manage']);
        $this->actingAs($actor)->post(route('respite.stays.restraints.store', $stay), $this->payload())->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson(route('health-safety.restraints.events.store'), $this->payload())->assertForbidden();
        $this->get(route('health-safety.restraints.index'))->assertForbidden();
        $this->getJson(route('health-safety.restraints.clients.summary', $this->client))->assertForbidden();
        $this->assertDatabaseCount('restraint_events', 1);
    }

    public function test_shared_capture_only_receives_receipt_and_current_picker_without_view_grant(): void
    {
        $actor = $this->staff(['restraints.create', 'clients.viewAny']);
        $result = $this->actingAs($actor)->postJson(route('health-safety.restraints.events.store'), $this->payload())->assertOk();
        $result->assertJsonPath('event_href', null);
        $id = $result->json('restraint_confirmation.event_id');
        $this->getJson(route('health-safety.restraints.report'))->assertOk()->assertJsonPath('pickers.clients.0.id', $this->client->id)->assertJsonMissing(['restraint_description' => 'Sensitive factual source']);
        $this->getJson(route('health-safety.restraints.events.show', $id))->assertForbidden();
    }

    public function test_respite_form_keeps_the_stays_own_tuple_and_both_entries_refuse_a_foreign_stay(): void
    {
        $stay = $this->stay();
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $foreignStay = $this->stay($foreignClient, $foreignSite);
        // Main's form takes the Client, Site and stay from the stay itself, never from the payload.
        $this->actingAs($this->actor)->post(route('respite.stays.restraints.store', $stay), $this->payload([
            'client_id' => $otherClient->id, 'site_id' => $foreignSite->id, 'stay_id' => $foreignStay->id,
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $row = RestraintEvent::sole();
        $this->assertSame($this->client->id, $row->client_id);
        $this->assertSame($this->site->id, $row->site_id);
        $this->assertSame($stay->id, $row->stay_id);
        $this->post(route('respite.stays.restraints.store', $foreignStay), $this->payload())->assertNotFound();
        $this->postJson(route('health-safety.restraints.events.store'), $this->payload(['client_id' => $this->client->id, 'stay_id' => $foreignStay->id]))->assertNotFound();
        $this->assertDatabaseCount('restraint_events', 1);
        $this->assertDatabaseCount('restraint_command_receipts', 0);
    }

    public function test_incident_picker_and_both_writers_require_exact_same_stay_even_with_incident_authority(): void
    {
        $stay = $this->stay();
        $otherStay = $this->stay();
        $foreign = ClientIncident::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'respite_stay_id' => $otherStay->id, 'status' => 'submitted', 'is_hr_confidential' => false]);
        $this->actingAs($this->actor)->post(route('respite.stays.restraints.store', $stay), $this->payload(['related_incident_id' => $foreign->id]))->assertNotFound();
        $this->postJson(route('health-safety.restraints.events.store'), $this->payload(['site_id' => $this->site->id, 'stay_id' => $stay->id, 'related_incident_id' => $foreign->id]))->assertNotFound();
        $this->assertDatabaseCount('restraint_events', 0);
        $event = RestraintEvent::findOrFail($this->service()->createForStay($this->actor, $stay, $this->payload())['event_id']);
        $this->assertSame([], app(RestraintRegisterService::class)->pickers($this->actor, [], $event)['incidents']);
    }

    public function test_unapproved_legacy_plan_stays_factual_in_the_shared_entry_and_respite_form_refuses_a_lapsed_plan(): void
    {
        $stay = $this->stay();
        $plan = BehaviourSupportPlan::factory()->create(['client_id' => $this->client->id, 'status' => 'active', 'review_date' => now()->subDay()]);
        // Main's respite form only accepts an active plan whose review date has not passed.
        $this->actingAs($this->actor)->post(route('respite.stays.restraints.store', $stay), $this->payload(['behaviour_support_plan_id' => $plan->id]))->assertNotFound();
        $this->assertDatabaseCount('restraint_events', 0);
        $this->postJson(route('health-safety.restraints.events.store'), $this->payload(['site_id' => $this->site->id, 'stay_id' => $stay->id, 'behaviour_support_plan_id' => $plan->id]))->assertOk();
        $this->assertDatabaseCount('restraint_events', 1);
        foreach (RestraintEvent::all() as $event) {
            $this->assertTrue($event->within_support_plan);
            $this->assertSame('approval_unknown', $event->plan_authorization_state);
            $this->assertSame($plan->id, $event->behaviour_support_plan_id);
            $this->assertNull($event->behaviour_support_plan_version_id);
            $this->assertNull($event->plan_authorization_snapshot['approval_id']);
        }
    }

    public function test_fresh_hr_and_respite_grant_revocation_reject_warm_actor_before_writes(): void
    {
        $stay = $this->stay();
        $this->revoke($this->actor, 'respite.stays.manage');
        // Main's respite form checks its route permission on the user loaded for the request.
        $this->actingAs($this->actor->fresh())->post(route('respite.stays.restraints.store', $stay), $this->payload())->assertForbidden();
        // The shared keyed entry also refuses a warmed actor whose HR record was withdrawn.
        $this->actor->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        HrEmployeeProfile::where('user_id', $this->actor->id)->update(['is_active' => false]);
        $this->actingAs($this->actor)->postJson(route('health-safety.restraints.events.store'), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('restraint_events', 0);
    }

    public function test_legacy_event_and_plan_register_links_redirect_to_current_canonical_workspaces(): void
    {
        $event = $this->event();
        $plan = BehaviourSupportPlan::factory()->create(['client_id' => $this->client->id]);
        // The permanent page keeps the register as its safe back link.
        $this->actingAs($this->actor)->get(route('health-safety.restraints.index', ['event' => $event->id]))->assertRedirect(RestraintReturn::event($event->id, RestraintReturn::REGISTER));
        $this->get(route('health-safety.restraints.index', ['plan' => $plan->id]))->assertRedirect(RestraintReturn::plan($plan->id, RestraintReturn::REGISTER));
        $this->get(route('health-safety.restraints.index', ['event' => 'invalid']))->assertNotFound();
    }

    public function test_attachment_identity_does_not_cross_events_or_follow_parent_read_revocation(): void
    {
        $event = $this->event();
        $other = $this->event();
        Storage::fake('private');
        Storage::disk('private')->put('restraint_attachments/missing.txt', 'Actual private evidence');
        $attachment = RestraintEventAttachment::create(['restraint_event_id' => $other->id, 'disk' => 'private', 'path' => 'restraint_attachments/missing.txt', 'original_name' => 'Private evidence', 'mime' => 'text/plain', 'size' => 1, 'uploaded_by' => $this->actor->id]);
        $this->actingAs($this->actor)->get(route('health-safety.restraints.events.attachments.download', [$other, $attachment]))->assertOk();
        $this->get(route('health-safety.restraints.events.attachments.download', [$event, $attachment]))->assertNotFound();
        $this->revoke($this->actor, 'restraints.view');
        $this->revoke($this->actor, 'restraints.review');
        $this->revoke($this->actor, 'restraints.manage');
        $this->get(route('health-safety.restraints.events.attachments.download', [$other, $attachment]))->assertForbidden();
    }

    public function test_full_and_partial_inertia_register_props_cannot_resurrect_modal_or_source_metadata(): void
    {
        $this->event();
        $this->actingAs($this->actor)->get(route('health-safety.restraints.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('health-safety/restraints/workspace-index')->where('events.total', 1)->where('detail', null));
        $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'health-safety/restraints/workspace-index', 'X-Inertia-Partial-Data' => 'events,tabCounts'])->get(route('health-safety.restraints.index'))->assertOk();
        $this->assertSame(1, $response->json('props.events.total'));
        $this->assertStringNotContainsString('plan_authorization_snapshot', $response->getContent());
        $this->assertStringNotContainsString('Sensitive factual source', $response->getContent());
    }
}
