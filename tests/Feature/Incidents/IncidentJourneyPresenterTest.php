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
        $this->assertSame(['in_progress', 'complete', 'waiting'], array_column($payload['lifecycle'], 'state'));
        $this->assertSame('Accept H&S handover', $payload['next_action']['label']);
        $this->assertSame('/health-safety/events/'.$hsEvent->id, $payload['next_action']['href']);

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('CR-'.$alert->id.'"', $json);
        $this->assertStringNotContainsString('INC-'.$incident->id.'"', $json);
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
        $this->assertSame('/incidents?incident='.$incident->id, $payload['next_action']['href']);
        $this->assertStringNotContainsString('INC-'.$incident->id, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $permissionKeys */
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
