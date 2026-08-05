<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsEventService;
use App\Services\Incidents\IncidentJourneyService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HsWorksafeConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_incidents_and_health_safety_read_the_same_hs_event_worksafe_states_and_pending_count(): void
    {
        $site = Site::factory()->create();
        $reporter = User::factory()->create();
        $pending = $this->notifiableJourney($site, $reporter, HsEvent::WORKSAFE_PENDING, 'acknowledged');
        $notified = $this->notifiableJourney($site, $reporter, HsEvent::WORKSAFE_NOTIFIED, 'pending');
        $acknowledged = $this->notifiableJourney($site, $reporter, HsEvent::WORKSAFE_ACKNOWLEDGED, 'pending');
        ClientIncident::withoutEvents(fn () => ClientIncident::factory()->atSite($site)->create([
            'reported_by' => $reporter->id,
            'is_notifiable' => true,
            'worksafe_notification_status' => 'pending',
        ]));
        $viewer = $this->admin();

        $this->actingAs($viewer)
            ->get('/incidents?tab=worksafe')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('hero.attention.worksafe.value', 1)
                ->has('rows.data', 3)
                ->where('rows.data', fn ($rows) => collect($rows)
                    ->pluck('worksafe_notification_status')
                    ->sort()
                    ->values()
                    ->all() === [
                        HsEvent::WORKSAFE_ACKNOWLEDGED,
                        HsEvent::WORKSAFE_NOTIFIED,
                        HsEvent::WORKSAFE_PENDING,
                    ])
            );

        $this->actingAs($viewer)
            ->get('/health-safety')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('backbone.events.worksafe_pending', 1)
                ->where('backbone.events.incident_worksafe_pending', 1)
                ->has('worklists.notifiable_events', 3)
                ->where('worklists.notifiable_events.0.status', HsEvent::WORKSAFE_PENDING)
                ->where('worklists.notifiable_events.1.status', HsEvent::WORKSAFE_NOTIFIED)
                ->where('worklists.notifiable_events.2.status', HsEvent::WORKSAFE_ACKNOWLEDGED)
            );

        $this->actingAs($viewer)
            ->get('/health-safety/events')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('hero.attention.worksafe_due', 1)
            );
    }

    public function test_hs_service_projects_worksafe_changes_outward_to_legacy_records_only(): void
    {
        $site = Site::factory()->create();
        $actor = User::factory()->create();
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()
            ->atSite($site)
            ->submitted()
            ->create([
                'reported_by' => $actor->id,
                'is_notifiable' => false,
                'worksafe_notification_status' => null,
            ]));
        $event = HsEvent::factory()->forClientIncident($incident)->worksafeNotifiable()->create();
        $incident->updateQuietly(['hs_event_id' => $event->id]);
        $legacy = NotifiableIncident::query()->create([
            'incident_type' => 'serious_harm',
            'notification_authority' => 'worksafe',
            'title' => 'Legacy compatibility row',
            'description' => 'Projected from the H&S event.',
            'related_incident_id' => $incident->id,
            'severity' => 'high',
            'status' => 'pending',
            'occurred_at' => $incident->occurred_at,
            'submitted_by' => $actor->id,
        ]);
        $service = app(HsEventService::class);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs($actor);
        $service->recordWorksafeNotification(
            $event,
            '2026-07-13 09:30:00',
            'online',
            'WS-2026-4242',
            true,
        );

        $incidentLock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'client_incidents')
            && str_contains($sql, 'for update'));
        $eventLock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'hs_events')
            && str_contains($sql, 'for update'));
        $this->assertNotFalse($incidentLock);
        $this->assertNotFalse($eventLock);
        $this->assertLessThan($eventLock, $incidentLock, 'WorkSafe mutations must lock ClientIncident before HsEvent.');

        $event->refresh();
        $incident->refresh();
        $legacy->refresh();
        $this->assertSame(HsEvent::WORKSAFE_NOTIFIED, $event->worksafe_status);
        $this->assertTrue($incident->is_notifiable);
        $this->assertSame(HsEvent::WORKSAFE_NOTIFIED, $incident->worksafe_notification_status);
        $this->assertSame('WS-2026-4242', $incident->worksafe_reference);
        $this->assertTrue($incident->site_preserved);
        $this->assertSame(HsEvent::WORKSAFE_NOTIFIED, $legacy->status);
        $this->assertSame('WS-2026-4242', $legacy->notification_reference);
        $this->assertTrue($legacy->site_preserved);

        $service->acknowledgeWorksafe($event, '2026-07-13 11:00:00');

        $event->refresh();
        $incident->refresh();
        $legacy->refresh();
        $this->assertSame(HsEvent::WORKSAFE_ACKNOWLEDGED, $event->worksafe_status);
        $this->assertSame(HsEvent::WORKSAFE_ACKNOWLEDGED, $incident->worksafe_notification_status);
        $this->assertSame(HsEvent::WORKSAFE_ACKNOWLEDGED, $legacy->status);
        $this->assertNotNull(data_get($legacy->authority_response_tracking, 'worksafe_acknowledged_at'));
    }

    public function test_a_direct_hs_link_to_another_incident_fails_safe_without_disclosing_the_foreign_journey(): void
    {
        $site = Site::factory()->create();
        $reporter = User::factory()->create();
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()
            ->atSite($site)
            ->submitted()
            ->create(['reported_by' => $reporter->id]));
        $otherIncident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()
            ->atSite($site)
            ->submitted()
            ->create(['reported_by' => $reporter->id]));
        $event = HsEvent::factory()->forClientIncident($otherIncident)->create([
            'site_id' => $site->id,
            'worksafe_notifiable' => false,
            'worksafe_status' => null,
            'worksafe_reference' => null,
            'worksafe_notified_at' => null,
            'worksafe_acknowledged_at' => null,
        ]);
        $incident->updateQuietly([
            'hs_event_id' => $event->id,
            'is_notifiable' => true,
            'worksafe_notification_status' => HsEvent::WORKSAFE_PENDING,
            'worksafe_reference' => 'STALE-LEGACY-REF',
            'worksafe_notified_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->get('/incidents?q='.urlencode($incident->reference_number)."&incident={$incident->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rows.data', function ($rows) use ($incident): bool {
                    $row = collect($rows)->firstWhere('id', $incident->id);

                    return $row !== null
                        && $row['journey_repair_required'] === true
                        && $row['is_notifiable'] === false
                        && $row['worksafe_notification_status'] === null;
                })
                ->where('detail.journey_repair_required', true)
                ->where('detail.journey_state', 'Journey repair required')
                ->where('detail.hs_event', null)
                ->where('detail.control_room_alert', null)
                ->where('detail.close_gate.allowed', false)
                ->where('detail.close_gate.requirements.0.key', 'journey_integrity')
                ->where('detail.is_notifiable', false)
                ->where('detail.worksafe_notification_status', null)
                ->where('detail.worksafe_reference', null)
                ->where('detail.worksafe_notified_at', null)
            );

        $this->assertSame($event->id, $incident->fresh()->hs_event_id);
        $this->assertSame($otherIncident->id, $event->fresh()->source_id);
    }

    public function test_a_direct_hs_link_with_the_wrong_category_is_withheld_and_requires_repair(): void
    {
        $this->assertNoncanonicalDirectHsEventIsWithheld([
            'event_category' => HsEvent::CATEGORY_NEAR_MISS,
        ]);
    }

    public function test_a_direct_hs_link_with_the_wrong_idempotency_key_is_withheld_and_requires_repair(): void
    {
        $this->assertNoncanonicalDirectHsEventIsWithheld(
            fn (ClientIncident $incident): array => [
                'idempotency_key' => HsEvent::buildIdempotencyKey(
                    ClientIncident::class,
                    $incident->id,
                    HsEvent::CATEGORY_NEAR_MISS,
                ),
            ],
        );
    }

    public function test_submitted_incident_cannot_mutate_the_legacy_worksafe_projection_inward(): void
    {
        $site = Site::factory()->create();
        $reporter = User::factory()->create();
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()
            ->atSite($site)
            ->submitted()
            ->create([
                'reported_by' => $reporter->id,
                'is_notifiable' => false,
            ]));
        $event = HsEvent::factory()->forClientIncident($incident)->create([
            'worksafe_notifiable' => false,
            'worksafe_status' => null,
        ]);
        $incident->updateQuietly(['hs_event_id' => $event->id]);

        $this->actingAs($this->admin())
            ->put("/incidents/{$incident->id}", ['is_notifiable' => true])
            ->assertRedirect();

        $this->assertFalse($incident->fresh()->is_notifiable);
        $this->assertFalse($event->fresh()->worksafe_notifiable);
    }

    public function test_health_safety_worksafe_summary_is_viewer_site_scoped_and_rejects_a_foreign_site_filter(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $reporter = User::factory()->create();
        $eventA = $this->notifiableJourney($siteA, $reporter, HsEvent::WORKSAFE_PENDING, HsEvent::WORKSAFE_PENDING);
        $eventB = $this->notifiableJourney($siteB, $reporter, HsEvent::WORKSAFE_PENDING, HsEvent::WORKSAFE_PENDING);
        $viewer = $this->siteBoundUser($siteA, ['hazards.view']);

        $this->actingAs($viewer)
            ->get('/health-safety')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('backbone.events.worksafe_pending', 1)
                ->where('backbone.events.incident_worksafe_pending', 1)
                ->has('worklists.notifiable_events', 1)
                ->where('worklists.notifiable_events.0.event_reference', $eventA->reference_number)
                ->missing('worklists.notifiable_events.1')
            );

        $this->actingAs($viewer)
            ->get("/health-safety?site={$siteB->id}")
            ->assertForbidden();

        $this->assertNotSame($eventA->reference_number, $eventB->reference_number);
    }

    public function test_historic_source_only_event_keeps_worksafe_parity_and_freezes_the_incident_time_site(): void
    {
        $incidentSite = Site::factory()->create();
        $currentClientSite = Site::factory()->create();
        $reporter = User::factory()->create();
        $client = Client::factory()->create(['site_id' => $incidentSite->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()
            ->submitted()
            ->create([
                'client_id' => $client->id,
                'reported_by' => $reporter->id,
                'site_id' => null,
                'hs_event_id' => null,
                'is_notifiable' => false,
                'worksafe_notification_status' => HsEvent::WORKSAFE_ACKNOWLEDGED,
            ]));
        $event = HsEvent::factory()
            ->forClientIncident($incident)
            ->worksafeNotifiable()
            ->create([
                'site_id' => $incidentSite->id,
                'worksafe_status' => HsEvent::WORKSAFE_PENDING,
            ]);

        // This reproduces pre-link data after a client has moved sites: the
        // source HsEvent is the surviving incident-time governance snapshot.
        $client->update(['site_id' => $currentClientSite->id]);
        $viewer = $this->admin();

        $migration = require database_path('migrations/2026_07_13_000200_backfill_incident_journey_links.php');
        $migration->up();
        $migration->up();
        $incident->refresh();
        $this->assertSame($event->id, $incident->hs_event_id);
        $this->assertSame($incidentSite->id, $incident->site_id);
        $this->assertNotSame($currentClientSite->id, $incident->site_id);
        $this->assertSame(1, HsEvent::query()
            ->where('source_type', ClientIncident::class)
            ->where('source_id', $incident->id)
            ->count());

        $this->actingAs($viewer)
            ->get("/incidents?tab=worksafe&site_id={$incidentSite->id}&incident={$incident->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('hero.attention.worksafe.value', 1)
                ->has('rows.data', 1)
                ->where('rows.data.0.id', $incident->id)
                ->where('rows.data.0.is_notifiable', true)
                ->where('rows.data.0.worksafe_notification_status', HsEvent::WORKSAFE_PENDING)
                ->where('detail.hs_event.id', $event->id)
            );

        $this->actingAs($viewer)
            ->get("/health-safety?site={$incidentSite->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('backbone.events.worksafe_pending', 1)
                ->where('backbone.events.incident_worksafe_pending', 1)
            );

        $this->actingAs($viewer)
            ->get("/incidents?tab=worksafe&site_id={$currentClientSite->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('hero.attention.worksafe.value', 0)
                ->has('rows.data', 0)
            );

        $this->actingAs($viewer)
            ->get("/health-safety?site={$currentClientSite->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('backbone.events.worksafe_pending', 0)
                ->where('backbone.events.incident_worksafe_pending', 0)
            );

        // Exercise the runtime invariant independently of the backfill.
        $incident->updateQuietly(['hs_event_id' => null, 'site_id' => null]);
        app(IncidentJourneyService::class)->ensureForSubmittedIncident($incident, $reporter);
        $incident->refresh();
        $this->assertSame($event->id, $incident->hs_event_id);
        $this->assertSame($incidentSite->id, $incident->site_id);
    }

    public function test_closed_pending_work_and_the_incident_time_site_use_the_same_worksafe_population(): void
    {
        $incidentSite = Site::factory()->create();
        $newClientSite = Site::factory()->create();
        $reporter = User::factory()->create();
        $event = $this->notifiableJourney(
            $incidentSite,
            $reporter,
            HsEvent::WORKSAFE_PENDING,
            HsEvent::WORKSAFE_ACKNOWLEDGED,
        );
        $event->updateQuietly(['status' => HsEvent::STATUS_CLOSED]);
        $incident = $event->clientIncident()->firstOrFail();
        $incident->client()->update(['site_id' => $newClientSite->id]);
        $viewer = $this->admin();

        $this->actingAs($viewer)
            ->get("/incidents?site_id={$incidentSite->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('hero.attention.worksafe.value', 1)
                ->where('rows.data', fn ($rows) => collect($rows)->contains('id', $incident->id))
            );

        $this->actingAs($viewer)
            ->get("/health-safety?site={$incidentSite->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('backbone.events.worksafe_pending', 1)
                ->where('backbone.events.incident_worksafe_pending', 1)
                ->has('worklists.notifiable_events', 1)
                ->where('worklists.notifiable_events.0.event_reference', $event->reference_number)
            );

        $this->actingAs($viewer)
            ->get("/health-safety/events?site_id={$incidentSite->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('hero.attention.worksafe_due', 1)
            );
    }

    private function notifiableJourney(Site $site, User $reporter, string $hsStatus, string $legacyStatus): HsEvent
    {
        $client = Client::factory()->create(['site_id' => $site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()
            ->atSite($site)
            ->submitted()
            ->create([
                'client_id' => $client->id,
                'reported_by' => $reporter->id,
                'submitted_at' => now(),
                'is_notifiable' => true,
                'worksafe_notification_status' => $legacyStatus,
            ]));
        $event = HsEvent::factory()->forClientIncident($incident)->worksafeNotifiable()->create([
            'worksafe_status' => $hsStatus,
            'worksafe_notified_at' => in_array($hsStatus, [HsEvent::WORKSAFE_NOTIFIED, HsEvent::WORKSAFE_ACKNOWLEDGED], true) ? now() : null,
            'worksafe_acknowledged_at' => $hsStatus === HsEvent::WORKSAFE_ACKNOWLEDGED ? now() : null,
        ]);
        $incident->updateQuietly(['hs_event_id' => $event->id]);

        return $event;
    }

    /**
     * @param  array<string, mixed>|callable(ClientIncident): array<string, mixed>  $eventOverrides
     */
    private function assertNoncanonicalDirectHsEventIsWithheld(array|callable $eventOverrides): void
    {
        $site = Site::factory()->create();
        $reporter = User::factory()->create();
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()
            ->atSite($site)
            ->submitted()
            ->create(['reported_by' => $reporter->id]));
        $overrides = is_callable($eventOverrides)
            ? $eventOverrides($incident)
            : $eventOverrides;
        $event = HsEvent::factory()->forClientIncident($incident)->create($overrides);
        $incident->updateQuietly(['hs_event_id' => $event->id]);

        $this->actingAs($this->admin())
            ->get('/incidents?q='.urlencode($incident->reference_number)."&incident={$incident->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rows.data', fn ($rows): bool => collect($rows)
                    ->contains(fn (array $row): bool => $row['id'] === $incident->id
                        && $row['journey_repair_required'] === true))
                ->where('detail.journey_repair_required', true)
                ->where('detail.journey_state', 'Journey repair required')
                ->where('detail.hs_event', null)
                ->where('detail.close_gate.allowed', false)
            );

        $this->assertSame($event->id, $incident->fresh()->hs_event_id);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function siteBoundUser(Site $site, array $permissionKeys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->sync($permissionIds->mapWithKeys(
            fn ($permissionId) => [$permissionId => ['allowed' => true]],
        ));
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }
}
