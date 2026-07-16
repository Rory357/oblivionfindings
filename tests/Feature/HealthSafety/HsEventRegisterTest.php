<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\FleetWorkOrder;
use App\Models\HazardousSubstance;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteInspectionRecord;
use App\Models\SiteInspectionSchedule;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * /health-safety/events — the governance register (Step 1 foundation): hero +
 * tab counts + standardised rows, the over-the-list detail (?event=), the
 * deep-link shell, tab scoping, and permission gating.
 */
class HsEventRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    protected function hsOfficer(): User
    {
        $user = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        if ($role = Role::where('name', 'health_safety_officer')->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    public function test_register_renders_governance_payload(): void
    {
        $site = Site::factory()->create();
        HsEvent::factory()->high()->create(['site_id' => $site->id]);
        HsEvent::factory()->closed()->create();
        HsEvent::factory()->worksafeNotifiable()->create();

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/events/index')
                ->has('events.data', 3)
                ->has('tabCounts.all')
                ->has('hero.live.open')
                ->has('hero.attention.worksafe_due')
                ->has('sites')
                ->has('can.manage')
                ->where('tab', 'all')
            );
    }

    public function test_worksafe_tab_scopes_to_notifiable(): void
    {
        HsEvent::factory()->create();                 // not notifiable
        HsEvent::factory()->worksafeNotifiable()->create();

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/events?tab=worksafe')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('tabCounts.worksafe', 1)
            );
    }

    public function test_register_can_filter_by_originating_source(): void
    {
        HsEvent::factory()->create(); // default source is a ClientIncident
        $workOrder = FleetWorkOrder::factory()->create(['priority' => 'high']);
        $equipmentEvent = HsEvent::query()
            ->where('source_type', FleetWorkOrder::class)
            ->where('source_id', $workOrder->id)
            ->firstOrFail();

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/events?source=equipment')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.id', $equipmentEvent->id)
                ->where('filters.source', 'equipment')
            );
    }

    public function test_event_query_param_returns_detail_over_list(): void
    {
        $event = HsEvent::factory()->high()->create();

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/events?event='.$event->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('detail')
                ->where('detail.id', $event->id)
                ->where('detail.reference_number', $event->reference_number)
                ->has('detail.investigations')
                ->has('detail.corrective_actions')
                ->has('detail.risk_assessments')
            );
    }

    public function test_register_and_detail_preserve_nullable_worksafe_decision_truth(): void
    {
        $actor = $this->hsOfficer();
        $undecided = HsEvent::factory()->worksafeUndecided()->create([
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);

        $this->actingAs($actor)
            ->get('/health-safety/events?event='.$undecided->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.data.0.worksafe_notifiable', null)
                ->where('detail.worksafe.notifiable', null)
                ->where('detail.worksafe.status', null)
                ->where('detail.worksafe.decision_reason', null)
                ->where('detail.worksafe.decided_at', null)
                ->where('detail.worksafe.decided_by', null)
                ->where('detail.worksafe.can_decide', true)
                ->where('detail.worksafe.can_notify', false)
                ->where('detail.worksafe.can_acknowledge', false)
                ->where('detail.close_gate.worksafe_ok', false)
                ->where(
                    'detail.close_gate.requirements.0.href',
                    "/health-safety/events/{$undecided->id}?action=worksafe-decision",
                )
            );

        $notNotifiable = HsEvent::factory()->worksafeNotNotifiable($actor)->create([
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);

        $this->actingAs($actor)
            ->get('/health-safety/events?event='.$notNotifiable->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.worksafe.notifiable', false)
                ->where('detail.worksafe.decision_reason', $notNotifiable->worksafe_decision_reason)
                ->where('detail.worksafe.decision_source', 'manual')
                ->where('detail.worksafe.decided_by.id', $actor->id)
                ->where('detail.worksafe.decided_by.name', $actor->name)
                ->where('detail.worksafe.can_decide', true)
                ->where('detail.worksafe.can_notify', false)
                ->where('detail.worksafe.can_acknowledge', false)
            );

        $pending = HsEvent::factory()->worksafeNotifiable($actor)->create([
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);

        $this->actingAs($actor)
            ->get('/health-safety/events?event='.$pending->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.worksafe.notifiable', true)
                ->where('detail.worksafe.status', HsEvent::WORKSAFE_PENDING)
                ->where('detail.worksafe.can_decide', true)
                ->where('detail.worksafe.can_notify', true)
                ->where('detail.worksafe.can_acknowledge', false)
            );
    }

    public function test_detail_worksafe_capabilities_follow_event_lifecycle(): void
    {
        $actor = $this->hsOfficer();
        $awaiting = HsEvent::factory()->worksafeNotifiable($actor)->create([
            'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
        ]);

        $this->actingAs($actor)
            ->get('/health-safety/events?event='.$awaiting->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.worksafe.can_decide', false)
                ->where('detail.worksafe.can_notify', true)
                ->where('detail.worksafe.can_acknowledge', false)
            );

        $notified = HsEvent::factory()->worksafeNotifiable($actor)->create([
            'handover_status' => HsEvent::HANDOVER_ACCEPTED,
            'worksafe_status' => HsEvent::WORKSAFE_NOTIFIED,
            'worksafe_notified_at' => now(),
            'worksafe_method' => 'online',
        ]);

        $this->actingAs($actor)
            ->get('/health-safety/events?event='.$notified->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.worksafe.can_decide', true)
                ->where('detail.worksafe.can_notify', false)
                ->where('detail.worksafe.can_acknowledge', true)
            );

        $notified->update([
            'status' => HsEvent::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => $actor->id,
            'closure_summary' => 'Closed under an authorised historic exception.',
        ]);

        $this->actingAs($actor)
            ->get('/health-safety/events?event='.$notified->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.worksafe.can_decide', false)
                ->where('detail.worksafe.can_notify', false)
                ->where('detail.worksafe.can_acknowledge', true)
            );
    }

    public function test_view_only_detail_exposes_worksafe_truth_without_mutation_controls(): void
    {
        $actor = $this->hsOfficer();
        $event = HsEvent::factory()->worksafeNotNotifiable($actor)->create([
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);
        $viewer = User::factory()->create(['approved_at' => now()]);
        $permission = Permission::query()->where('key', 'hazards.view')->firstOrFail();
        $viewer->permissionOverrides()->sync([
            $permission->id => ['allowed' => true],
        ]);

        $this->actingAs($viewer)
            ->get('/health-safety/events?event='.$event->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.worksafe.notifiable', false)
                ->where('detail.worksafe.decision_reason', $event->worksafe_decision_reason)
                ->where('detail.worksafe.can_decide', false)
                ->where('detail.worksafe.can_notify', false)
                ->where('detail.worksafe.can_acknowledge', false)
            );
    }

    public function test_show_renders_thin_deeplink_shell(): void
    {
        $client = Client::factory()->create();
        $event = HsEvent::factory()->create(['client_id' => $client->id]);

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/events/'.$event->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/events/show')
                ->where('detail.reference_number', $event->reference_number)
                ->has('detail.can.manage')
            );
    }

    public function test_detail_resolves_the_originating_source_and_only_links_for_an_authorised_viewer(): void
    {
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create());
        $event = HsEvent::factory()->forClientIncident($incident)->create();

        $this->assertTrue($event->source->is($incident));

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/events?event='.$event->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.source.type', 'ClientIncident')
                ->where('detail.source.url', null)
                ->where('detail.source.unwired', false)
            );

        $admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        $this->actingAs($admin)
            ->get('/health-safety/events?event='.$event->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.source.type', 'ClientIncident')
                ->where('detail.source.url', "/incidents/{$event->source_id}")
                ->where('detail.source.unwired', false)
            );
    }

    public function test_detail_resolves_substance_exposure_originating_source_link(): void
    {
        $substance = HazardousSubstance::factory()->create();
        $record = SubstanceExposureRecord::create([
            'hazardous_substance_id' => $substance->id,
            'user_id' => User::factory()->create()->id,
            'exposed_at' => now(),
            'exposure_type' => 'skin_contact',
        ]);
        $event = HsEvent::factory()->create([
            'source_type' => SubstanceExposureRecord::class,
            'source_id' => $record->id,
            'event_category' => HsEvent::CATEGORY_EXPOSURE,
        ]);

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/events?event='.$event->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.source.type', 'SubstanceExposureRecord')
                ->where('detail.source.url', "/health-safety/substances/{$substance->id}")
                ->where('detail.source.unwired', false)
            );
    }

    public function test_detail_resolves_site_inspection_failure_originating_source_link(): void
    {
        $site = Site::factory()->create();
        $schedule = SiteInspectionSchedule::create([
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'inspection_type' => 'house_safety',
            'title' => 'House safety inspection',
            'frequency' => 'monthly',
            'first_due_date' => now()->subDay()->toDateString(),
            'next_due_date' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);
        $record = SiteInspectionRecord::create([
            'schedule_id' => $schedule->id,
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'due_date' => now()->subDay()->toDateString(),
            'completed_at' => now(),
            'result' => 'fail',
        ]);
        $event = HsEvent::factory()->create([
            'source_type' => SiteInspectionRecord::class,
            'source_id' => $record->id,
            'event_category' => HsEvent::CATEGORY_INSPECTION_FAILURE,
            'site_id' => $site->id,
        ]);

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/events?event='.$event->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.source.type', 'SiteInspectionRecord')
                ->where('detail.source.url', "/sites/{$site->id}/inspections")
                ->where('detail.source.unwired', false)
            );
    }

    public function test_detail_resolves_fleet_work_order_originating_source_link(): void
    {
        $asset = Asset::factory()->vehicle()->create();
        $workOrder = FleetWorkOrder::factory()->create([
            'asset_id' => $asset->id,
            'priority' => 'critical',
            'status' => 'open',
        ]);
        $event = HsEvent::factory()->create([
            'source_type' => FleetWorkOrder::class,
            'source_id' => $workOrder->id,
            'event_category' => HsEvent::CATEGORY_EQUIPMENT_FAULT,
            'site_id' => $asset->site_id,
            'asset_id' => $asset->id,
        ]);

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/events?event='.$event->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.source.type', 'FleetWorkOrder')
                ->where('detail.source.url', "/fleet-assets/maintenance/work-orders/{$workOrder->id}")
                ->where('detail.source.unwired', false)
            );
    }

    public function test_register_requires_hazards_view(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->get('/health-safety/events')
            ->assertForbidden();
    }
}
