<?php

namespace Tests\Feature\Respite;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Events\Respite\RespiteEvent;
use App\Models\BehaviourSupportPlan;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Permission;
use App\Models\RespiteAuditLog;
use App\Models\RespiteBooking;
use App\Models\RespiteDailyNote;
use App\Models\RespiteEvidencePack;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\IncidentJourneyService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RespiteScopeIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private Site $site;

    private Site $foreignSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create();
        $this->foreignSite = Site::factory()->create();
        $this->coordinator = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);

        $role = Role::query()->create([
            'name' => 'respite_scope_test_coordinator',
            'label' => 'Respite Scope Test Coordinator',
            'level' => 20,
            'type' => 'custom',
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('key', [
            'clients.viewAssigned',
            'respite.viewAny',
            'respite.stays.manage',
            'respite.daily-notes.view',
            'respite.daily-notes.manage',
            'respite.evidence.view',
            'respite.evidence.manage',
            'respite.evidence.seal',
            'restraints.create',
        ])->pluck('id'));
        $this->coordinator->roles()->attach($role);

        HrEmployeeProfile::factory()->create([
            'user_id' => $this->coordinator->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
    }

    public function test_daily_note_rejects_submitted_resident_mismatch_without_partial_incident_or_audit(): void
    {
        Event::fake([RespiteEvent::class]);
        Notification::fake();
        [$stay, $resident] = $this->stayAt($this->site, true);
        [, $otherResident] = $this->stayAt($this->site, true);

        $this->actingAs($this->coordinator)
            ->post(route('respite.daily-notes.store'), $this->dailyNotePayload($stay, $otherResident, [
                'incident_occurred' => true,
                'concerns' => 'This must not create a mismatched incident.',
            ]))
            ->assertNotFound();

        $this->assertSame($resident->id, $stay->client_id);
        $this->assertDatabaseCount('respite_daily_notes', 0);
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('respite_audit_logs', 0);
        Event::assertNotDispatched(RespiteEvent::class);
        Notification::assertNothingSent();
    }

    public function test_daily_note_rejects_incident_from_another_stay_without_partial_side_effects(): void
    {
        [$stay, $resident] = $this->stayAt($this->site, true);
        [$otherStay] = $this->stayAt($this->site, true, $resident);
        $foreignIncident = ClientIncident::factory()->create([
            'client_id' => $resident->id,
            'site_id' => $this->site->id,
            'respite_stay_id' => $otherStay->id,
        ]);

        $this->actingAs($this->coordinator)
            ->post(route('respite.daily-notes.store'), $this->dailyNotePayload($stay, $resident, [
                'linked_incident_id' => $foreignIncident->id,
                'incident_occurred' => true,
            ]))
            ->assertNotFound();
        $this->actingAs($this->coordinator)
            ->post(route('respite.daily-notes.store'), $this->dailyNotePayload($stay, $resident, [
                'linked_incident_id' => 999999999,
                'incident_occurred' => true,
            ]))
            ->assertNotFound();

        $this->assertDatabaseCount('respite_daily_notes', 0);
        $this->assertSame(1, ClientIncident::query()->count());
        $this->assertDatabaseCount('respite_audit_logs', 0);
    }

    public function test_valid_daily_note_derives_incident_ownership_from_the_locked_stay(): void
    {
        Event::fake([RespiteEvent::class]);
        [$stay, $resident] = $this->stayAt($this->site, true);

        $this->actingAs($this->coordinator)
            ->post(route('respite.daily-notes.store'), $this->dailyNotePayload($stay, $resident, [
                'incident_occurred' => true,
                'concerns' => 'A low-severity incident requires follow-up.',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $note = RespiteDailyNote::query()->sole();
        $incident = ClientIncident::query()->sole();
        $this->assertSame($stay->id, $note->stay_id);
        $this->assertSame($resident->id, $note->client_id);
        $this->assertSame($incident->id, $note->linked_incident_id);
        $this->assertSame($stay->id, $incident->respite_stay_id);
        $this->assertSame($resident->id, $incident->client_id);
        $this->assertSame($this->site->id, $incident->site_id);
        $this->assertNotNull($incident->hs_event_id);
        $this->assertDatabaseCount('respite_audit_logs', 1);
        Event::assertDispatchedTimes(RespiteEvent::class, 1);
    }

    public function test_daily_note_store_rolls_back_when_derived_incident_journey_fails(): void
    {
        Event::fake([RespiteEvent::class]);
        Notification::fake();
        [$stay, $resident] = $this->stayAt($this->site, true);
        $this->failIncidentJourneyAfterRealEffects();

        $this->actingAs($this->coordinator)
            ->post(route('respite.daily-notes.store'), $this->dailyNotePayload($stay, $resident, [
                'incident_occurred' => true,
                'concerns' => 'This write must roll back in full.',
            ]))
            ->assertStatus(500);

        $this->assertDatabaseCount('respite_daily_notes', 0);
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('respite_audit_logs', 0);
        Event::assertNotDispatched(RespiteEvent::class);
        Notification::assertNothingSent();
    }

    public function test_daily_note_update_rolls_back_when_derived_incident_journey_fails(): void
    {
        Event::fake([RespiteEvent::class]);
        Notification::fake();
        [$stay, $resident] = $this->stayAt($this->site, true);
        $note = RespiteDailyNote::factory()->create([
            'stay_id' => $stay->id,
            'client_id' => $resident->id,
            'incident_occurred' => false,
            'concerns' => null,
        ]);
        $this->failIncidentJourneyAfterRealEffects();

        $this->actingAs($this->coordinator)
            ->put(route('respite.daily-notes.update', $note), [
                'incident_occurred' => true,
                'concerns' => 'This update must roll back in full.',
            ])
            ->assertStatus(500);

        $note->refresh();
        $this->assertFalse($note->incident_occurred);
        $this->assertNull($note->concerns);
        $this->assertNull($note->linked_incident_id);
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('respite_audit_logs', 0);
        Event::assertNotDispatched(RespiteEvent::class);
        Notification::assertNothingSent();
    }

    public function test_restraint_rejects_foreign_or_expired_plan_and_mismatched_incident_before_creation(): void
    {
        Event::fake([RespiteEvent::class]);
        [$stay, $resident] = $this->stayAt($this->site, true);
        [, $foreignResident] = $this->stayAt($this->site, true);
        $foreignPlan = BehaviourSupportPlan::factory()->create([
            'client_id' => $foreignResident->id,
            'status' => 'active',
            'review_date' => today()->addMonth(),
        ]);

        $this->actingAs($this->coordinator)
            ->post(route('respite.stays.restraints.store', $stay), $this->restraintPayload([
                'behaviour_support_plan_id' => $foreignPlan->id,
            ]))
            ->assertNotFound();

        $expiredPlan = BehaviourSupportPlan::factory()->create([
            'client_id' => $resident->id,
            'status' => 'active',
            'review_date' => today()->subDay(),
        ]);
        $this->actingAs($this->coordinator)
            ->post(route('respite.stays.restraints.store', $stay), $this->restraintPayload([
                'behaviour_support_plan_id' => $expiredPlan->id,
            ]))
            ->assertNotFound();

        $currentPlan = $this->currentPlan($resident);
        [$otherStay] = $this->stayAt($this->site, true, $resident);
        $otherIncident = ClientIncident::factory()->create([
            'client_id' => $resident->id,
            'site_id' => $this->site->id,
            'respite_stay_id' => $otherStay->id,
        ]);
        $this->actingAs($this->coordinator)
            ->post(route('respite.stays.restraints.store', $stay), $this->restraintPayload([
                'behaviour_support_plan_id' => $currentPlan->id,
                'related_incident_id' => $otherIncident->id,
            ]))
            ->assertNotFound();

        $this->assertDatabaseCount('restraint_events', 0);
        Event::assertNotDispatched(RespiteEvent::class);
    }

    public function test_shared_restraint_register_cannot_bypass_respite_stay_scope(): void
    {
        [$stay, $resident] = $this->stayAt($this->site, true);
        [, $otherResident] = $this->stayAt($this->site, true);
        [, $foreignResident] = $this->stayAt($this->foreignSite);
        [$foreignStay] = $this->stayAt($this->foreignSite);
        $foreignPlan = $this->currentPlan($foreignResident);

        $this->actingAs($this->coordinator)
            ->post(route('health-safety.restraints.events.store'), $this->restraintPayload([
                'client_id' => $otherResident->id,
                'site_id' => $this->site->id,
                'stay_id' => $stay->id,
            ]))
            ->assertNotFound();

        $this->actingAs($this->coordinator)
            ->post(route('health-safety.restraints.events.store'), $this->restraintPayload([
                'client_id' => $resident->id,
                'site_id' => $this->site->id,
                'stay_id' => $stay->id,
                'behaviour_support_plan_id' => $foreignPlan->id,
            ]))
            ->assertNotFound();

        $this->actingAs($this->coordinator)
            ->post(route('health-safety.restraints.events.store'), $this->restraintPayload([
                'client_id' => $resident->id,
                'site_id' => $this->site->id,
                'stay_id' => $foreignStay->id,
            ]))
            ->assertNotFound();

        $this->assertDatabaseCount('restraint_events', 0);
    }

    public function test_stay_disclosure_revalidates_incident_and_restraint_bindings(): void
    {
        [$stay] = $this->stayAt($this->site, true);
        [, $otherResident] = $this->stayAt($this->site, true);
        RestraintEvent::factory()->create([
            'stay_id' => $stay->id,
            'client_id' => $otherResident->id,
            'site_id' => $this->site->id,
            'within_support_plan' => false,
        ]);

        $this->actingAs($this->coordinator)
            ->get(route('respite.stays.show', $stay))
            ->assertNotFound();
    }

    public function test_cross_site_direct_objects_are_denied_before_disclosure_audit_or_notification(): void
    {
        Event::fake([RespiteEvent::class]);
        Notification::fake();
        [$foreignStay, $foreignResident, $foreignBooking] = $this->stayAt($this->foreignSite);
        [$accessibleStay, $accessibleResident] = $this->stayAt($this->site, true);
        [$foreignLocationStay] = $this->stayAt($this->foreignSite, true, $accessibleResident);
        $foreignNote = RespiteDailyNote::factory()->create([
            'stay_id' => $foreignStay->id,
            'client_id' => $foreignResident->id,
        ]);
        $foreignPack = RespiteEvidencePack::query()->create([
            'stay_id' => $foreignStay->id,
            'booking_id' => $foreignBooking->id,
            'status' => 'draft',
            'items' => [],
        ]);
        $mismatchedPack = RespiteEvidencePack::query()->create([
            'stay_id' => $accessibleStay->id,
            'booking_id' => $foreignBooking->id,
            'status' => 'draft',
            'items' => [],
        ]);
        $packCount = RespiteEvidencePack::query()->count();
        $noteCount = RespiteDailyNote::query()->count();

        $this->actingAs($this->coordinator)
            ->get(route('respite.stays.show', $foreignStay))
            ->assertNotFound();
        $this->actingAs($this->coordinator)
            ->get(route('respite.stays.show', $foreignLocationStay))
            ->assertNotFound();
        $this->actingAs($this->coordinator)
            ->get(route('respite.daily-notes.show', $foreignNote))
            ->assertNotFound();
        $this->actingAs($this->coordinator)
            ->get(route('respite.evidence-packs.show', $foreignPack))
            ->assertNotFound();
        $this->actingAs($this->coordinator)
            ->get(route('respite.evidence-packs.export', $foreignPack))
            ->assertNotFound();
        $this->actingAs($this->coordinator)
            ->get(route('respite.evidence-packs.show', $mismatchedPack))
            ->assertNotFound();
        $this->actingAs($this->coordinator)
            ->post(route('respite.evidence-packs.store'), [
                'stay_id' => $foreignStay->id,
                'summary' => 'Must not be created',
            ])
            ->assertNotFound();
        $this->actingAs($this->coordinator)
            ->post(
                route('respite.daily-notes.store'),
                $this->dailyNotePayload($foreignLocationStay, $accessibleResident),
            )
            ->assertNotFound();
        $this->actingAs($this->coordinator)
            ->post(route('respite.stays.incidents.store', $foreignStay), [
                'type' => 'privacy',
                'severity' => 'high',
                'title' => 'Must not be created',
                'description' => 'Cross-site direct-object attempt.',
                'immediate_action_taken' => 'None because access must be denied.',
                'is_notifiable' => true,
                'notification_authority' => 'privacy_commissioner',
                'incident_type' => 'privacy_breach',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('notifiable_incidents', 0);
        $this->assertDatabaseCount('data_breach_logs', 0);
        $this->assertDatabaseCount('respite_audit_logs', 0);
        $this->assertSame($packCount, RespiteEvidencePack::query()->count());
        $this->assertSame($noteCount, RespiteDailyNote::query()->count());
        Event::assertNotDispatched(RespiteEvent::class);
        Notification::assertNothingSent();
    }

    public function test_client_view_permission_does_not_bypass_stay_site_scope_without_explicit_global_access(): void
    {
        [$accessibleStay, $resident] = $this->stayAt($this->site, true);
        [$foreignLocationStay] = $this->stayAt($this->foreignSite, true, $resident);
        $role = $this->coordinator->roles()->firstOrFail();
        $role->permissions()->syncWithoutDetaching(
            Permission::query()->where('key', 'clients.viewAny')->pluck('id'),
        );

        $this->actingAs($this->coordinator)
            ->get(route('respite.stays.show', $accessibleStay))
            ->assertOk();
        $this->actingAs($this->coordinator)
            ->get(route('respite.stays.show', $foreignLocationStay))
            ->assertNotFound();

        $role->permissions()->syncWithoutDetaching(
            Permission::query()->where('key', 'sites.viewAll')->pluck('id'),
        );
        $globalCoordinator = $this->coordinator->fresh();

        $this->actingAs($globalCoordinator)
            ->get(route('respite.stays.show', $foreignLocationStay))
            ->assertOk();
    }

    public function test_evidence_item_rejects_foreign_record_metadata_without_mutation_or_audit(): void
    {
        [$stay, , $booking] = $this->stayAt($this->site, true);
        [$foreignStay, $foreignResident] = $this->stayAt($this->foreignSite);
        $foreignIncident = ClientIncident::factory()->create([
            'client_id' => $foreignResident->id,
            'site_id' => $this->foreignSite->id,
            'respite_stay_id' => $foreignStay->id,
        ]);
        $foreignPlan = $this->currentPlan($foreignResident);
        $foreignNote = RespiteDailyNote::factory()->create([
            'stay_id' => $foreignStay->id,
            'client_id' => $foreignResident->id,
        ]);
        $foreignRestraint = RestraintEvent::factory()->create([
            'stay_id' => $foreignStay->id,
            'client_id' => $foreignResident->id,
            'site_id' => $this->foreignSite->id,
            'within_support_plan' => false,
        ]);
        $pack = RespiteEvidencePack::query()->create([
            'stay_id' => $stay->id,
            'booking_id' => $booking->id,
            'status' => 'draft',
            'items' => [],
        ]);

        foreach ([
            ['incident_id' => $foreignIncident->id],
            ['daily_note_id' => $foreignNote->id],
            ['restraint_event_id' => $foreignRestraint->id],
            ['behaviour_support_plan_id' => $foreignPlan->id],
        ] as $metadata) {
            $this->actingAs($this->coordinator)
                ->post(route('respite.evidence-packs.add-item', $pack), [
                    'type' => 'note',
                    'title' => 'Forged respite evidence relation',
                    'metadata' => $metadata,
                ])
                ->assertNotFound();
        }

        $this->assertSame([], $pack->fresh()->items);
        $this->assertDatabaseCount('respite_audit_logs', 0);
    }

    public function test_evidence_item_validates_and_canonicalizes_supplied_relation_ids_before_write(): void
    {
        [$stay, $resident, $booking] = $this->stayAt($this->site, true);
        $incident = ClientIncident::factory()->create([
            'client_id' => $resident->id,
            'site_id' => $this->site->id,
            'respite_stay_id' => $stay->id,
        ]);
        $pack = RespiteEvidencePack::query()->create([
            'stay_id' => $stay->id,
            'booking_id' => $booking->id,
            'status' => 'draft',
            'items' => [],
        ]);

        $this->actingAs($this->coordinator)
            ->post(route('respite.evidence-packs.add-item', $pack), [
                'type' => 'note',
                'title' => 'Canonical incident relation',
                'metadata' => ['incident_id' => (string) $incident->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $storedItems = $pack->fresh()->items;
        $this->assertSame($incident->id, $storedItems[0]['metadata']['incident_id']);
        $auditCount = RespiteAuditLog::query()->count();

        $this->actingAs($this->coordinator)
            ->post(route('respite.evidence-packs.add-item', $pack), [
                'type' => 'note',
                'title' => 'Malformed relation must not persist',
                'metadata' => ['incident_id' => ['not-an-id']],
            ])
            ->assertSessionHasErrors('metadata.incident_id');

        $this->assertSame($storedItems, $pack->fresh()->items);
        $this->assertSame($auditCount, RespiteAuditLog::query()->count());
    }

    public function test_seal_revalidates_tampered_plan_binding_and_rolls_back_all_seal_side_effects(): void
    {
        Event::fake([RespiteEvent::class]);
        [$stay, , $booking] = $this->stayAt($this->site, true);
        [, $foreignResident] = $this->stayAt($this->site, true);
        $foreignPlan = $this->currentPlan($foreignResident);
        RestraintEvent::factory()->create([
            'stay_id' => $stay->id,
            'client_id' => $stay->client_id,
            'site_id' => $this->site->id,
            'behaviour_support_plan_id' => $foreignPlan->id,
            'within_support_plan' => true,
            'reviewed_at' => now(),
        ]);
        $pack = RespiteEvidencePack::query()->create([
            'stay_id' => $stay->id,
            'booking_id' => $booking->id,
            'status' => 'draft',
            'items' => [],
        ]);

        $this->actingAs($this->coordinator)
            ->post(route('respite.evidence-packs.seal', $pack), ['seal_reason' => 'Attempt forged seal.'])
            ->assertSessionHasErrors('manifest');

        $pack->refresh();
        $this->assertNull($pack->sealed_at);
        $this->assertSame('draft', $pack->status);
        $this->assertDatabaseMissing('respite_audit_logs', ['auditable_id' => $pack->id, 'action' => 'sealed']);
        Event::assertNotDispatched(RespiteEvent::class);
    }

    public function test_seal_revalidates_a_plan_that_expired_after_evidence_was_added(): void
    {
        Event::fake([RespiteEvent::class]);
        [$stay, $resident, $booking] = $this->stayAt($this->site, true);
        $plan = $this->currentPlan($resident);
        $pack = RespiteEvidencePack::query()->create([
            'stay_id' => $stay->id,
            'booking_id' => $booking->id,
            'status' => 'draft',
            'items' => [],
        ]);

        $this->actingAs($this->coordinator)
            ->post(route('respite.evidence-packs.add-item', $pack), [
                'type' => 'document',
                'title' => 'Current behaviour support plan',
                'metadata' => ['behaviour_support_plan_id' => $plan->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $plan->update(['review_date' => today()->subDay()]);
        $itemsBeforeSeal = $pack->fresh()->items;

        $this->actingAs($this->coordinator)
            ->post(route('respite.evidence-packs.seal', $pack), ['seal_reason' => 'Plan has expired.'])
            ->assertSessionHasErrors('manifest');

        $pack->refresh();
        $this->assertSame($itemsBeforeSeal, $pack->items);
        $this->assertNull($pack->sealed_at);
        $this->assertDatabaseMissing('respite_audit_logs', ['auditable_id' => $pack->id, 'action' => 'sealed']);
        Event::assertNotDispatched(RespiteEvent::class);
    }

    public function test_seal_replay_is_serialized_and_produces_one_audit_and_one_event(): void
    {
        Event::fake([RespiteEvent::class]);
        [$stay, , $booking] = $this->stayAt($this->site, true);
        $pack = RespiteEvidencePack::query()->create([
            'stay_id' => $stay->id,
            'booking_id' => $booking->id,
            'status' => 'draft',
            'items' => [],
        ]);

        $this->actingAs($this->coordinator)
            ->post(route('respite.evidence-packs.seal', $pack), ['seal_reason' => 'Complete and seal.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->actingAs($this->coordinator)
            ->post(route('respite.evidence-packs.seal', $pack), ['seal_reason' => 'Replay seal.'])
            ->assertSessionHas('error', 'Evidence pack is already sealed.');

        $this->assertSame(1, RespiteAuditLog::query()
            ->where('auditable_type', $pack->getMorphClass())
            ->where('auditable_id', $pack->id)
            ->where('action', 'sealed')
            ->count());
        Event::assertDispatchedTimes(RespiteEvent::class, 1);
    }

    public function test_evidence_pack_creation_replay_produces_one_pack_audit_and_event(): void
    {
        Event::fake([RespiteEvent::class]);
        [$stay] = $this->stayAt($this->site, true);

        $this->actingAs($this->coordinator)
            ->post(route('respite.evidence-packs.store'), [
                'stay_id' => $stay->id,
                'summary' => 'One authoritative pack',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->actingAs($this->coordinator)
            ->post(route('respite.evidence-packs.store'), [
                'stay_id' => $stay->id,
                'summary' => 'Replay must not create another pack',
            ])
            ->assertSessionHasErrors('stay_id');

        $pack = RespiteEvidencePack::query()->sole();
        $this->assertSame(1, RespiteAuditLog::query()
            ->where('auditable_type', $pack->getMorphClass())
            ->where('auditable_id', $pack->id)
            ->where('action', RespiteAuditLog::ACTION_CREATED)
            ->count());
        Event::assertDispatchedTimes(RespiteEvent::class, 1);
    }

    /** @return array{0:RespiteStay,1:Client,2:RespiteBooking} */
    private function stayAt(Site $site, bool $assign = false, ?Client $client = null): array
    {
        $client ??= Client::factory()->create(['site_id' => $site->id]);
        if ($assign) {
            $client->supportWorkers()->syncWithoutDetaching([$this->coordinator->id]);
        }
        $booking = RespiteBooking::factory()->create([
            'client_id' => $client->id,
            'location_id' => $site->id,
            'status' => 'confirmed',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'consent_authority' => 'self',
            'agreement_status' => 'signed',
            'code_of_rights_provided' => true,
            'consent_to_respite' => true,
            'advocate_offered' => true,
            'consent_capacity_basis' => 'has_capacity',
            'rights_format_provided' => 'written',
            'rights_recorded_at' => now(),
        ]);
        $stay = RespiteStay::query()->create([
            'booking_id' => $booking->id,
            'client_id' => $client->id,
            'status' => 'active',
            'actual_start' => now()->subDay(),
            'created_by' => $this->coordinator->id,
        ]);

        return [$stay, $client, $booking];
    }

    private function currentPlan(Client $client): BehaviourSupportPlan
    {
        return BehaviourSupportPlan::factory()->create([
            'client_id' => $client->id,
            'status' => 'active',
            'developed_at' => today()->subMonth(),
            'review_date' => today()->addMonth(),
        ]);
    }

    private function failIncidentJourneyAfterRealEffects(): void
    {
        $realJourney = app(IncidentJourneyService::class);
        $this->mock(IncidentJourneyService::class, function ($mock) use ($realJourney): void {
            $mock->shouldReceive('ensureForSubmittedIncident')
                ->once()
                ->andReturnUsing(function (ClientIncident $incident, ?User $actor) use ($realJourney): never {
                    $realJourney->ensureForSubmittedIncident($incident, $actor);

                    throw new \RuntimeException('Forced failure after derived incident effects.');
                });
        });
    }

    /** @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function dailyNotePayload(RespiteStay $stay, Client $client, array $overrides = []): array
    {
        return [
            'stay_id' => $stay->id,
            'client_id' => $client->id,
            'note_date' => today()->toDateString(),
            'shift_period' => 'morning',
            'observations' => 'Resident settled well during the shift.',
            ...$overrides,
        ];
    }

    /** @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function restraintPayload(array $overrides = []): array
    {
        return [
            'started_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'ended_at' => now()->subMinutes(45)->format('Y-m-d H:i:s'),
            'restraint_type' => 'physical',
            'severity' => 'medium',
            'trigger_description' => 'Resident moved toward an unsafe exit.',
            'de_escalation_attempted' => 'Quiet space and reassurance were offered.',
            'restraint_description' => 'Brief approved physical redirection.',
            'within_support_plan' => true,
            ...$overrides,
        ];
    }
}
