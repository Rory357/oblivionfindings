<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoom\Communication;
use App\Models\ControlRoom\EvidenceItem;
use App\Models\ControlRoom\EvidencePack;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsCorrectiveActionService;
use App\Services\HealthSafety\HsEventClosureService;
use App\Services\Incidents\IncidentJourney;
use App\Services\Incidents\IncidentJourneyPresenter;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentJourneyPresenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_a_missing_response_does_not_claim_live_response_or_complete_work(): void
    {
        $presenter = app(IncidentJourneyPresenter::class);
        $this->assertSame('Incident review pending', $presenter->journeyState(new ClientIncident(['status' => 'submitted']), null, null));
        $this->assertSame('Incident draft', $presenter->journeyState(new ClientIncident(['status' => 'draft']), null, null));
        $this->assertSame('Journey needs review', $presenter->journeyState(null, null, null));
        $this->assertSame('Journey needs review', $presenter->journeyState(null, new ControlRoomAlert(['status' => 'unknown_legacy']), null));
        $this->assertSame('Operational response active', $presenter->journeyState(null, new ControlRoomAlert(['status' => 'open']), null));
    }

    public function test_it_presents_one_truthful_three_stage_journey_with_role_aware_next_action(): void
    {
        $incidentSite = Site::factory()->create(['name' => 'Rimu House']);
        $currentSite = Site::factory()->create(['name' => 'New Placement']);
        $client = Client::factory()->create([
            'site_id' => $currentSite->id,
            'organization_id' => 1,
            'first_name' => 'Wiremu',
            'last_name' => 'Tane',
        ]);
        $reporter = User::factory()->create(['name' => 'Support Worker']);
        $owner = User::factory()->create(['name' => 'H&S Lead']);
        $viewer = $this->siteBoundUser($incidentSite, [
            'controlRoom.alerts.manage',
            'incidents.viewAny',
            'hazards.view',
            'hazards.manage',
        ]);

        $alert = ControlRoomAlert::factory()->triaging()->create([
            'reference_number' => 'CR-2026-1204',
            'site_id' => $incidentSite->id,
            'client_id' => $client->id,
            'alert_type' => 'fall_detected',
            'notes' => 'Operator confirmed the room was made safe.',
        ]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
            'reference_number' => 'INC-2026-0831',
            'site_id' => $incidentSite->id,
            'client_id' => $client->id,
            'reported_by' => $reporter->id,
            'control_room_alert_id' => $alert->id,
            'title' => 'Fall beside the bed',
            'description' => 'Wiremu was found seated on the floor beside the bed.',
            'immediate_action_taken' => 'Checked for injury, called the nurse and kept the area clear.',
            'witnesses' => 'Night support worker',
            'potential_consequence' => 'Possible head injury',
            'occurred_at' => '2026-07-15 08:25:00',
        ]));
        $hsEvent = HsEvent::factory()->worksafeNotifiable()->awaitingHandoverAcceptance($owner)->forClientIncident($incident)->create([
            'reference_number' => 'HS-2026-0440',
            'organization_id' => 1,
            'control_room_alert_id' => $alert->id,
            'site_id' => $incidentSite->id,
            'client_id' => $client->id,
            'worksafe_reference' => 'WS-9988',
        ]);
        $incident->update(['hs_event_id' => $hsEvent->id]);

        $playbook = Playbook::factory()->create(['name' => 'Falls immediate response']);
        $run = PlaybookRun::query()->create([
            'playbook_id' => $playbook->id,
            'alert_id' => $alert->id,
            'status' => 'in_progress',
            'current_step' => 2,
            'completed_steps' => 1,
            'total_steps' => 4,
        ]);
        $alert->update(['playbook_run_id' => $run->id]);
        $operationalTask = AlertTask::query()->create([
            'alert_id' => $alert->id,
            'title' => 'Contact the on-call nurse',
            'status' => AlertTask::STATUS_IN_PROGRESS,
            'priority' => 'high',
            'assigned_to_user_id' => $owner->id,
            'due_at' => '2026-07-15 09:30:00',
        ]);
        $transferredTask = AlertTask::query()->create([
            'alert_id' => $alert->id,
            'title' => 'Preserve the fall-scene evidence',
            'status' => AlertTask::STATUS_TRANSFERRED,
            'priority' => 'critical',
        ]);
        $correctiveAction = HsCorrectiveAction::factory()->create([
            'hs_event_id' => $hsEvent->id,
            'source_control_room_task_id' => $transferredTask->id,
            'reference_number' => 'CA-2026-0120',
            'title' => 'Retain and review the fall-scene evidence',
            'assigned_to_user_id' => $owner->id,
        ]);
        $transferredTask->update([
            'transferred_to_hs_corrective_action_id' => $correctiveAction->id,
            'transferred_at' => '2026-07-15 09:00:00',
            'transferred_by_user_id' => $viewer->id,
        ]);
        OperatorNote::query()->create([
            'alert_id' => $alert->id,
            'type' => OperatorNote::TYPE_ACTION,
            'purpose' => OperatorNote::PURPOSE_IMMEDIATE_CONTROLS,
            'content' => 'Room isolated and nurse called.',
            'user_id' => $viewer->id,
        ]);
        $pack = EvidencePack::query()->create([
            'alert_id' => $alert->id,
            'title' => 'Room and response evidence',
            'status' => 'collecting',
            'items' => [],
            'item_count' => 1,
        ]);
        EvidenceItem::query()->create([
            'evidence_pack_id' => $pack->id,
            'type' => 'document',
            'title' => 'Room preservation record',
            'description' => 'Duty manager confirmed the room stayed isolated.',
            'storage_path' => 'control-room/room-preservation.txt',
            'mime_type' => 'text/plain',
            'captured_at' => '2026-07-15 09:05:00',
            'captured_by_user_id' => $viewer->id,
        ]);
        Communication::query()->create([
            'alert_id' => $alert->id,
            'channel' => 'phone_call',
            'direction' => 'outbound',
            'subject' => 'Nurse notified',
            'content' => 'On-call nurse is attending.',
            'status' => 'sent',
        ]);

        $payload = app(IncidentJourneyPresenter::class)->present(
            new IncidentJourney($incident->fresh(), $alert->fresh(), $hsEvent->fresh()),
            $viewer,
        );

        $this->assertSame([
            'control_room' => 'CR-2026-1204',
            'incident' => 'INC-2026-0831',
            'health_safety' => 'HS-2026-0440',
        ], $payload['references']);
        $this->assertSame('Rimu House', $payload['incident']['site']['name']);
        $this->assertSame('Wiremu Tane', $payload['incident']['person']['name']);
        $this->assertSame('Fall beside the bed', $payload['incident']['narrative']['title']);
        $this->assertSame('Checked for injury, called the nurse and kept the area clear.', $payload['incident']['narrative']['immediate_controls']);
        $this->assertSame('Falls immediate response', $payload['control_room']['playbook']['name']);
        $this->assertSame('Contact the on-call nurse', $payload['control_room']['tasks'][0]['title']);
        $this->assertSame('Room and response evidence', $payload['control_room']['evidence'][0]['title']);
        $this->assertSame('Nurse notified', $payload['control_room']['communications'][0]['subject']);
        $this->assertSame('Linked Control Room evidence', $payload['linked_operational_evidence']['label']);
        $this->assertTrue($payload['linked_operational_evidence']['read_only']);
        $this->assertSame('CR-2026-1204', $payload['linked_operational_evidence']['source']['reference']);
        $this->assertSame('Rimu House', $payload['linked_operational_evidence']['source']['site']['name']);
        $this->assertSame('Wiremu Tane', $payload['linked_operational_evidence']['source']['client']['name']);
        $this->assertSame('immediate_controls', $payload['linked_operational_evidence']['notes'][0]['purpose']);
        $this->assertSame('Immediate controls', $payload['linked_operational_evidence']['notes'][0]['purpose_label']);
        $this->assertSame($operationalTask->id, $payload['linked_operational_evidence']['tasks'][0]['id']);
        $this->assertSame('open', $payload['linked_operational_evidence']['tasks'][0]['transfer']['state']);
        $this->assertSame('transferred', $payload['linked_operational_evidence']['tasks'][1]['transfer']['state']);
        $this->assertSame('CA-2026-0120', $payload['linked_operational_evidence']['tasks'][1]['transfer']['corrective_action_reference']);
        $this->assertSame(
            "/incidents/{$incident->id}/control-room-evidence/".$payload['linked_operational_evidence']['evidence_packs'][0]['items'][0]['id'].'/download',
            $payload['linked_operational_evidence']['evidence_packs'][0]['items'][0]['download_url'],
        );
        $this->assertSame('Nurse notified', $payload['linked_operational_evidence']['communications'][0]['subject']);
        $this->assertSame('awaiting_acceptance', $payload['health_safety']['handover']['status']);
        $this->assertSame('H&S Lead', $payload['health_safety']['handover']['owner']['name']);
        $this->assertTrue($payload['health_safety']['worksafe']['notifiable']);
        $this->assertSame('pending', $payload['health_safety']['worksafe']['status']);
        $this->assertSame('Operational response active', $payload['journey_state']);
        $this->assertSame(['in_progress', 'complete', 'waiting'], array_column($payload['lifecycle'], 'state'));
        $this->assertSame('Continue Control Room response', $payload['next_action']['label']);
        $this->assertSame('/control-room/alerts/'.$alert->id, $payload['next_action']['href']);

        // The presenter re-reads the alert (supplied models carry identities only), so the resolution is saved.
        $resolvedAlert = tap($alert->fresh()->forceFill(['status' => ControlRoomAlert::STATUS_RESOLVED]))->saveQuietly();
        $afterResponse = app(IncidentJourneyPresenter::class)->present(
            new IncidentJourney($incident->fresh(), $resolvedAlert, $hsEvent->fresh()),
            $viewer,
        );
        $this->assertSame('Accept H&S handover', $afterResponse['next_action']['label']);
        $this->assertSame('/health-safety/events/'.$hsEvent->id, $afterResponse['next_action']['href']);

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('CR-'.$alert->id.'"', $json);
        $this->assertStringNotContainsString('INC-'.$incident->id.'"', $json);
    }

    public function test_it_reports_each_cross_module_journey_state_from_canonical_records(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'organization_id' => 1,
        ]);
        $owner = $this->siteBoundUser($site, ['healthSafety.events.close']);
        $acceptor = User::factory()->create();
        $verifier = $this->siteBoundUser($site, ['hazards.manage']);
        $alert = ControlRoomAlert::factory()->triaging()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'severity' => 'low',
        ]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'control_room_alert_id' => $alert->id,
            'type' => 'fall',
            'severity' => 'low',
        ]));
        $hsEvent = HsEvent::factory()
            ->forClientIncident($incident)
            ->awaitingHandoverAcceptance($owner)
            ->worksafeNotNotifiable($owner)
            ->create([
                'control_room_alert_id' => $alert->id,
                'site_id' => $site->id,
                'client_id' => $client->id,
                'severity' => HsEvent::SEVERITY_LOW,
            ]);
        $presenter = app(IncidentJourneyPresenter::class);

        $this->assertSame(
            'Operational response active',
            $presenter->journeyState($incident, $alert, $hsEvent),
        );

        $alert->forceFill([
            'status' => ControlRoomAlert::STATUS_RESOLVED,
            'resolved_at' => now(),
        ])->save();
        $this->assertSame(
            'Operationally resolved',
            $presenter->journeyState($incident, $alert->fresh(), $hsEvent),
        );

        $incident->forceFill([
            'status' => 'reviewed',
            'reviewed_at' => now(),
            'reviewed_by' => $owner->id,
        ])->save();
        $this->assertSame(
            'Incident review complete',
            $presenter->journeyState($incident->fresh(), $alert->fresh(), null),
        );
        $this->assertSame(
            'H&S acceptance pending',
            $presenter->journeyState($incident->fresh(), $alert->fresh(), $hsEvent),
        );

        $hsEvent->forceFill([
            'handover_status' => HsEvent::HANDOVER_ACCEPTED,
            'accepted_by_user_id' => $acceptor->id,
            'accepted_at' => now(),
        ])->save();
        $this->assertSame(
            'H&S governance active',
            $presenter->journeyState($incident->fresh(), $alert->fresh(), $hsEvent->fresh()),
        );

        $action = HsCorrectiveAction::factory()->completed()->create([
            'hs_event_id' => $hsEvent->id,
            'assigned_to_user_id' => $owner->id,
            'completed_by_user_id' => $owner->id,
            'created_by' => $owner->id,
        ]);
        $this->assertSame(
            'Awaiting independent verification',
            $presenter->journeyState($incident->fresh(), $alert->fresh(), $hsEvent->fresh()),
        );

        $this->actingAs($verifier);
        $verified = app(HsCorrectiveActionService::class)->verify($action, [
            'verified_by_user_id' => $verifier->id,
            'evidence_reviewed' => true,
            'effectiveness_confirmed' => true,
            'verification_notes' => 'Independent review confirmed the recorded completion evidence.',
        ]);
        $this->assertSame(HsCorrectiveAction::STATUS_VERIFIED, $verified->status);
        $this->assertSame($verifier->id, $verified->verified_by_user_id);
        $closure = app(HsEventClosureService::class);
        $readyEvent = $hsEvent->fresh();
        $this->assertFalse($readyEvent->investigation_required);
        $readiness = $closure->readiness($readyEvent);
        $this->assertSame([], array_column($readiness->blockers(), 'key'));
        $this->assertTrue($readiness->ordinaryAllowed());

        $this->actingAs($owner);
        $hsEvent = $closure->closeEvent($hsEvent, 'Governance work completed.', $owner);
        $this->assertSame(HsEvent::STATUS_CLOSED, $hsEvent->status);
        $this->assertSame($owner->id, $hsEvent->closed_by);
        $this->assertSame(
            'Governance closed',
            $presenter->journeyState($incident->fresh(), $alert->fresh(), $hsEvent->fresh()),
        );

        $incident->forceFill(['status' => 'closed'])->save();
        $alert->forceFill([
            'status' => ControlRoomAlert::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by_user_id' => $owner->id,
        ])->save();
        $this->assertSame(
            'Journey closed',
            $presenter->journeyState($incident->fresh(), $alert->fresh(), $hsEvent->fresh()),
        );
    }

    public function test_missing_official_references_remain_null_and_links_follow_viewer_permissions(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id, 'organization_id' => 1]);
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
            'reference_number' => null,
            'site_id' => $site->id,
            'client_id' => $client->id,
        ]));

        $payload = app(IncidentJourneyPresenter::class)->present(
            new IncidentJourney($incident, null, null),
            $viewer,
        );

        $this->assertNull($payload['references']['control_room']);
        $this->assertNull($payload['references']['incident']);
        $this->assertNull($payload['references']['health_safety']);
        $this->assertSame('View incident', $payload['next_action']['label']);
        $this->assertSame('/incidents/'.$incident->id, $payload['next_action']['href']);
        $this->assertStringNotContainsString('INC-'.$incident->id, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_standalone_terminal_alerts_never_report_an_active_response(): void
    {
        $presenter = app(IncidentJourneyPresenter::class);
        $closed = ControlRoomAlert::factory()->closed()->create();
        $dismissed = ControlRoomAlert::factory()->create([
            'status' => ControlRoomAlert::STATUS_DISMISSED,
        ]);

        $this->assertSame('Journey closed', $presenter->journeyState(null, $closed, null));
        $this->assertSame('Journey closed', $presenter->journeyState(null, $dismissed, null));
    }

    public function test_active_response_precedes_finishing_a_draft_report(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['controlRoom.alerts.manage', 'incidents.create']);
        // A linked alert and incident share one client (Codex's journey integrity rule).
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->triaging()->create(['site_id' => $site->id, 'client_id' => $client->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'status' => 'draft',
            'reported_by' => $viewer->id,
            'control_room_alert_id' => $alert->id,
        ]));

        $payload = app(IncidentJourneyPresenter::class)->present(new IncidentJourney($incident, $alert, null), $viewer);

        $this->assertSame('control_room', $payload['next_action']['stage']);
        $this->assertSame('/control-room/alerts/'.$alert->id, $payload['next_action']['href']);
    }

    public function test_lacking_a_permitted_action_does_not_complete_an_open_journey_or_link_a_restricted_alert(): void
    {
        $incidentSite = Site::factory()->create();
        $viewerSite = Site::factory()->create();
        $viewer = $this->siteBoundUser($viewerSite, ['controlRoom.alerts.manage']);
        $client = Client::factory()->create(['site_id' => $incidentSite->id]);
        $alert = ControlRoomAlert::factory()->triaging()->create(['site_id' => $incidentSite->id, 'client_id' => $client->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
            'site_id' => $incidentSite->id,
            'client_id' => $client->id,
            'control_room_alert_id' => $alert->id,
        ]));

        $payload = app(IncidentJourneyPresenter::class)->present(new IncidentJourney($incident, $alert, null), $viewer);

        $this->assertSame('Operational response active', $payload['journey_state']);
        $this->assertSame('blocked', $payload['next_action']['stage']);
        $this->assertSame('No action available for your role', $payload['next_action']['label']);
        $this->assertNull($payload['next_action']['href']);
        $this->assertNull($payload['control_room']);
        $this->assertNull($payload['incident']);
        $this->assertSame('restricted', $payload['linked_access']['control_room']);
        $this->assertNull($payload['references']['control_room']);
    }

    public function test_a_closed_journey_without_a_permitted_action_is_complete(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, []);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->closed()->create(['site_id' => $site->id, 'client_id' => $client->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'status' => 'closed',
            'control_room_alert_id' => $alert->id,
        ]));

        $payload = app(IncidentJourneyPresenter::class)->present(new IncidentJourney($incident, $alert, null), $viewer);

        $this->assertSame('Journey closed', $payload['journey_state']);
        $this->assertSame(['label' => 'Journey closed', 'href' => null, 'stage' => 'complete'], $payload['next_action']);
    }

    public function test_hs_access_does_not_reveal_a_confidential_incident_in_the_journey(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny', 'hazards.view']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->atSite($site)->create([
            'is_hr_confidential' => true,
            'description' => 'Private staff account must stay in the incident.',
        ]));
        $event = HsEvent::factory()->forClientIncident($incident)->create(['site_id' => $site->id]);
        $payload = app(IncidentJourneyPresenter::class)->present(new IncidentJourney($incident, null, $event), $viewer);

        $this->assertNull($payload['incident']);
        $this->assertNull($payload['references']['incident']);
        $this->assertSame('restricted', $payload['linked_access']['incident']);
        // Codex's rule (kept, the stricter option): an H&S event linked to an incident needs incident read
        // access, so the event of an HR-confidential incident is withheld too.
        $this->assertNull($payload['health_safety']);
        $this->assertStringNotContainsString($incident->description, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertNotSame('/incidents/'.$incident->id, $payload['next_action']['href']);
    }

    public function test_incident_access_cannot_open_or_reveal_hs_work_outside_the_viewers_sites(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny', 'hazards.view', 'hazards.manage']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->atSite($site)->create());
        $event = HsEvent::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
            'acceptance_notes' => 'Private governance account.',
        ]);
        $payload = app(IncidentJourneyPresenter::class)->present(new IncidentJourney($incident, null, $event), $viewer);

        $this->assertNull($payload['health_safety']);
        $this->assertNull($payload['references']['health_safety']);
        // The presenter reads the incident's own H&S event (supplied models are identities only), so an unrelated
        // event elsewhere is simply not part of this journey: never readable, never revealed.
        $this->assertContains($payload['linked_access']['health_safety'], ['absent', 'restricted']);
        $this->assertNotSame('/health-safety/events/'.$event->id, $payload['next_action']['href']);
        $this->assertStringNotContainsString('Private governance account.', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_presented_hs_lifecycle_uses_current_closed_snapshot_instead_of_preloaded_pending_parent(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        // Incident-linked H&S work needs incident read too (H&S staff read incidents, Stephan 25 Oct).
        $viewer = $this->siteBoundUser($site, ['hazards.view', 'hazards.manage', 'incidents.viewAny']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->atSite($site)->create());
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->forClientIncident($incident)->create([
            'site_id' => $site->id, 'status' => HsEvent::STATUS_OPEN,
            'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE, 'owner_user_id' => $viewer->id,
        ]));
        $event->load('owner', 'activeInvestigation');
        $presenter = app(IncidentJourneyPresenter::class);
        $journey = new IncidentJourney($incident, null, $event);
        $this->assertSame('Accept H&S handover', $presenter->present($journey, $viewer)['next_action']['label']);
        HsEvent::whereKey($event->id)->update(['status' => HsEvent::STATUS_CLOSED, 'handover_status' => HsEvent::HANDOVER_ACCEPTED]);
        $payload = $presenter->present($journey, $viewer);
        $this->assertSame(HsEvent::STATUS_CLOSED, $payload['health_safety']['status']);
        $this->assertSame(HsEvent::HANDOVER_ACCEPTED, $payload['health_safety']['handover']['status']);
        $this->assertSame('Governance closed', $payload['journey_state']);
        $this->assertSame('complete', $payload['lifecycle'][2]['state']);
        $this->assertSame('View H&S event', $payload['next_action']['label']);
        $this->assertSame('/health-safety/events/'.$event->id, $payload['next_action']['href']);
    }

    public function test_presented_hs_actions_require_fresh_manage_evidence_and_unknown_link_is_never_complete(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        // Incident-linked H&S work needs incident read too (H&S staff read incidents, Stephan 25 Oct).
        $viewer = $this->siteBoundUser($site, ['hazards.view', 'hazards.manage', 'incidents.viewAny']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->atSite($site)->create());
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->forClientIncident($incident)->create([
            'site_id' => $site->id, 'status' => HsEvent::STATUS_OPEN,
            'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
        ]));
        $presenter = app(IncidentJourneyPresenter::class);
        $journey = new IncidentJourney($incident, null, $event);
        $this->assertSame('Accept H&S handover', $presenter->present($journey, $viewer)['next_action']['label']);
        $viewer->load('permissionOverrides', 'roles.permissions');
        $viewer->permissionOverrides()->updateExistingPivot(Permission::where('key', 'hazards.manage')->sole()->id, ['allowed' => false]);
        $payload = $presenter->present($journey, $viewer);
        $this->assertSame('readable', $payload['linked_access']['health_safety']);
        $this->assertSame('waiting', $payload['lifecycle'][2]['state']);
        $this->assertSame('View H&S event', $payload['next_action']['label']);
        $event->deleteQuietly();
        $payload = $presenter->present($journey, $viewer);
        $this->assertNull($payload['health_safety']);
        $this->assertSame('blocked', $payload['lifecycle'][2]['state']);
        $this->assertSame('Journey needs review', $payload['journey_state']);
        $this->assertSame('blocked', $payload['next_action']['stage']);
        $this->assertNull($payload['next_action']['href']);
    }

    /** @param list<string> $permissionKeys */
    public function test_incident_only_reader_keeps_lawful_payload_without_control_room_permission_or_cached_caller_body(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->atSite($site)->create(['title' => 'Current lawful incident']));
        $incident->title = 'Forged cached caller title';
        $incident->setRelation('followups', collect());
        $payload = app(IncidentJourneyPresenter::class)->present(new IncidentJourney($incident, null, null), $viewer);
        $this->assertSame('readable', $payload['linked_access']['incident']);
        $this->assertSame('Current lawful incident', $payload['incident']['narrative']['title']);
        $this->assertSame('absent', $payload['linked_access']['control_room']);
        $this->assertStringNotContainsString('Forged cached caller title', json_encode($payload, JSON_THROW_ON_ERROR));
        \Illuminate\Support\Facades\DB::table('client_incidents')->where('id', $incident->id)->update(['title' => 'New lawful source body']);
        $again = app(IncidentJourneyPresenter::class)->present(new IncidentJourney($incident, null, null), $viewer);
        $this->assertSame('New lawful source body', $again['incident']['narrative']['title']);
    }

    public function test_incident_only_original_metadata_and_site_guard_precede_private_body_sql(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->atSite($site)->create(['title' => 'Source body sentinel']));
        $armed = true; $statement = null;
        \Illuminate\Support\Facades\DB::connection()->beforeExecuting(function ($sql) use (&$armed, &$statement, $viewer): void {
            if (! $armed || ! preg_match('/^select\s+\*\s+from\s+[`"]?client_incidents/i', $sql)) { return; }
            $armed = false; $statement = $sql;
            $viewer->permissionOverrides()->updateExistingPivot(Permission::where('key', 'incidents.viewAny')->sole()->id, ['allowed' => false]);
        });
        try { $payload = app(IncidentJourneyPresenter::class)->present(new IncidentJourney($incident, null, null), $viewer); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertContains($error->getStatusCode(), [403, 404]); $payload = []; }
        finally { $armed = false; }
        $this->assertNotNull($statement, 'Must exercise actual private body SQL, not only inspect final props.');
        $this->assertStringContainsString('SHA2', $statement);
        $this->assertStringContainsString('exists', strtolower($statement));
        $this->assertStringNotContainsString('Source body sentinel', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_control_room_only_viewer_keeps_operational_record_without_private_incident_eager_load(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $viewer = $this->siteBoundUser($site, ['controlRoom.alerts.view']);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->triaging()->create(['site_id' => $site->id, 'client_id' => $client->id, 'source' => 'incident', 'context' => []]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->atSite($site)->create(['client_id' => $client->id,
            'control_room_alert_id' => $alert->id, 'hs_event_id' => null, 'title' => 'Private confidential journey', 'is_hr_confidential' => true]));
        $alert->withoutEvents(fn () => $alert->update(['context' => ['incident_id' => $incident->id]]));
        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });
        $payload = app(IncidentJourneyPresenter::class)->present(new IncidentJourney($incident, $alert, null), $viewer);
        $this->assertNull($payload['incident']);
        $this->assertSame('restricted', $payload['linked_access']['incident']);
        $this->assertSame('readable', $payload['linked_access']['control_room']);
        $this->assertSame((int) $alert->id, (int) $payload['control_room']['id']);
        $this->assertSame('unavailable', $payload['control_room']['notes_access']);
        $this->assertStringNotContainsString('Private confidential journey', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertFalse(collect($queries)->contains(fn ($sql) => str_contains($sql, 'incident_followups') || str_contains($sql, 'incident_attachments')));
    }

    private function siteBoundUser(Site $site, array $permissionKeys): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'organization_id' => $site->tenant_id]);
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
