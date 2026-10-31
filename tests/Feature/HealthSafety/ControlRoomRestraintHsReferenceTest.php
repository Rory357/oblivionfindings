<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\RestraintEvent;
use App\Models\User;
use App\Services\ControlRoom\AlertWorklistPresenter;
use App\Services\ControlRoom\AlertWorklistQuery;
use App\Services\ControlRoom\AlertWorkspaceService;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Control Room keeps a restraint's H&S reference, people and link for readers of that restraint (Stephan, 25 Oct:
 * fix after the H&S merge). The alert itself stays visible as an operational response to everyone who sees alerts.
 */
class ControlRoomRestraintHsReferenceTest extends RestraintEventCommandTestCase
{
    private const CONTROL_ROOM = ['controlRoom.viewAny', 'controlRoom.alerts.view', 'hazards.view', 'clients.viewAny'];

    private ControlRoomAlert $alert;

    private HsEvent $hsEvent;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $source = $this->event();
        $this->alert = ControlRoomAlert::withoutEvents(fn () => ControlRoomAlert::factory()->create([
            'source' => 'operations', 'alert_type' => 'operations.restraint_event',
            'client_id' => $source->client_id, 'site_id' => $source->site_id,
            'severity' => 'medium', 'status' => ControlRoomAlert::STATUS_OPEN, 'notes' => null,
            'context' => ['restraint_event_id' => $source->id],
        ]));
        $this->owner = $this->staff(['hazards.view', 'hazards.manage', 'restraints.view']);
        $this->hsEvent = HsEvent::withoutEvents(fn () => HsEvent::factory()->create([
            'source_type' => RestraintEvent::class, 'source_id' => $source->id,
            'event_category' => HsEvent::CATEGORY_RESTRAINT, 'site_id' => $source->site_id,
            'client_id' => $source->client_id, 'staff_id' => $source->created_by, 'created_by' => $source->created_by,
            'severity' => 'medium', 'status' => HsEvent::STATUS_OPEN,
            'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE, 'owner_user_id' => $this->owner->id,
            'control_room_alert_id' => $this->alert->id, 'reference_number' => 'HS-RESTR-CR-REF',
        ]));
    }

    public function test_restraint_reader_finds_and_sees_the_reference_link_and_owner(): void
    {
        $viewer = $this->staff([...self::CONTROL_ROOM, 'restraints.view']);

        $this->assertSame([$this->alert->id], $this->search($viewer, 'HS-RESTR-CR-REF'));
        $row = app(AlertWorklistPresenter::class)->present($this->alert->fresh(), $viewer);
        $this->assertSame('HS-RESTR-CR-REF', $row['journey']['health_safety_reference']);
        $this->assertSame('/health-safety/events/'.$this->hsEvent->id, $row['actions']['health_safety_href']);

        $this->actingAs($viewer)->get('/control-room/incidents?'.http_build_query(['search' => 'HS-RESTR-CR-REF']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('journeys.data', 1)
                ->where('journeys.data.0.alert.id', $this->alert->id)
                ->where('journeys.data.0.health_safety.reference_number', 'HS-RESTR-CR-REF')
                ->where('journeys.data.0.health_safety.owner.name', $this->owner->name)
                ->where('journeys.data.0.health_safety.href', '/health-safety/events/'.$this->hsEvent->id));

        $gate = $this->hsRequirement($viewer);
        $this->assertStringContainsString('HS-RESTR-CR-REF', $gate['label']);
        $this->assertSame('/health-safety/events/'.$this->hsEvent->id, $gate['href']);
    }

    public function test_without_restraint_read_the_reference_does_not_find_or_name_the_alert(): void
    {
        $viewer = $this->staff(self::CONTROL_ROOM);

        // Reference search finds nothing; the alert is still in the operational list.
        $this->assertSame([], $this->search($viewer, 'HS-RESTR-CR-REF'));
        $this->assertSame([$this->alert->id], app(AlertWorklistQuery::class)->forUser($viewer, ['ids' => [$this->alert->id]])
            ->pluck('control_room_alerts.id')->all());

        $row = app(AlertWorklistPresenter::class)->present($this->alert->fresh(), $viewer);
        $this->assertNull($row['journey']['health_safety_reference']);
        $this->assertSame(HsEvent::HANDOVER_AWAITING_ACCEPTANCE, $row['journey']['handover_status']);
        $this->assertNull($row['actions']['health_safety_href']);

        $this->actingAs($viewer)->get('/control-room/incidents?'.http_build_query(['search' => 'HS-RESTR-CR-REF']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('journeys.data', 0));
        $page = $this->get('/control-room/incidents?lens=awaiting_health_safety')->assertOk();
        $page->assertInertia(fn (Assert $page) => $page
            ->has('journeys.data', 1)
            ->where('journeys.data.0.alert.id', $this->alert->id)
            ->where('journeys.data.0.health_safety.reference_number', null)
            ->where('journeys.data.0.health_safety.owner', null)
            ->where('journeys.data.0.health_safety.accepted_by', null)
            ->where('journeys.data.0.health_safety.href', null)
            ->where('journeys.data.0.health_safety.handover_status', HsEvent::HANDOVER_AWAITING_ACCEPTANCE)
            // The next step may open the alert itself (an operational response), never the restraint's H&S event.
            ->where('journeys.data.0.next_action.href', fn (?string $href): bool => $href === null || ! str_contains($href, '/health-safety/')));
        $this->assertStringNotContainsString('HS-RESTR-CR-REF', json_encode($page->viewData('page')['props']['journeys']));

        $gate = $this->hsRequirement($viewer);
        $this->assertSame('Close linked H&S governance before closing this alert.', $gate['label']);
        $this->assertNull($gate['href']);
        $this->assertFalse($gate['complete']);
    }

    public function test_restraint_read_withdrawn_after_load_is_read_fresh(): void
    {
        $viewer = $this->staff([...self::CONTROL_ROOM, 'restraints.view']);
        $viewer->load('permissionOverrides', 'roles.permissions');
        $this->revoke($viewer, 'restraints.view');

        $this->assertSame([], $this->search($viewer, 'HS-RESTR-CR-REF'));
        $this->assertNull(app(AlertWorklistPresenter::class)->present($this->alert->fresh(), $viewer)['journey']['health_safety_reference']);
    }

    public function test_ordinary_hs_reference_is_unchanged_for_the_same_viewer(): void
    {
        $viewer = $this->staff(self::CONTROL_ROOM);
        $alert = ControlRoomAlert::withoutEvents(fn () => ControlRoomAlert::factory()->create([
            'site_id' => $this->site->id, 'status' => 'open', 'severity' => 'medium', 'context' => [],
        ]));
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->create([
            'site_id' => $this->site->id, 'control_room_alert_id' => $alert->id,
            'reference_number' => 'HS-ORD-CR-REF', 'handover_status' => HsEvent::HANDOVER_ACCEPTED,
        ]));

        $this->assertSame([$alert->id], $this->search($viewer, 'HS-ORD-CR-REF'));
        $row = app(AlertWorklistPresenter::class)->present($alert->fresh(), $viewer);
        $this->assertSame('HS-ORD-CR-REF', $row['journey']['health_safety_reference']);
        $this->assertSame('/health-safety/events/'.$event->id, $row['actions']['health_safety_href']);
    }

    /** @return list<int> */
    private function search(User $viewer, string $term): array
    {
        return app(AlertWorklistQuery::class)->forUser($viewer, ['q' => $term])->pluck('control_room_alerts.id')->all();
    }

    /** @return array<string, mixed> */
    private function hsRequirement(User $viewer): array
    {
        $workspace = app(AlertWorkspaceService::class)->build($viewer, $this->alert->id);
        $this->assertNotNull($workspace);

        return collect(data_get($workspace, 'close_gate.requirements'))->firstWhere('key', 'health_safety_closed');
    }
}
