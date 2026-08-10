<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoom\Communication;
use App\Models\ControlRoom\EvidenceItem;
use App\Models\ControlRoom\EvidencePack;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\IncidentFollowup;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Support\Incidents\LinkedOperationalEvidencePresenter;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HsLinkedOperationalEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_incident_and_hs_details_receive_the_same_read_only_control_room_evidence(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('control-room/scene-record.txt', 'scene record');

        $site = Site::factory()->create(['name' => 'Kauri House']);
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny', 'hazards.view']);
        [$incident, $alert, $event] = $this->journey($site, $viewer);
        [$openTask, $transferredTask, $item] = $this->operationalEvidence($alert, $event, $viewer);
        IncidentFollowup::query()->create([
            'client_incident_id' => $incident->id,
            'notes' => 'Check the updated falls plan with the care team.',
            'assigned_to_user_id' => $viewer->id,
            'created_by' => $viewer->id,
            'due_at' => now()->addDay(),
        ]);

        $incidentResponse = $this->actingAs($viewer)
            ->get("/incidents?incident={$incident->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.linked_operational_evidence.label', 'Linked Control Room evidence')
                ->where('detail.linked_operational_evidence.read_only', true)
                ->where('detail.linked_operational_evidence.source.reference', 'CR-2026-2401')
                ->where('detail.linked_operational_evidence.source.href', null)
                ->where('detail.linked_operational_evidence.source.site.name', 'Kauri House')
                ->where('detail.linked_operational_evidence.source.client.name', 'Mereana Ropata')
                ->has('detail.linked_operational_evidence.source.triggered_at')
                ->has('detail.linked_operational_evidence.source.created_at')
                ->has('detail.linked_operational_evidence.source.updated_at')
                ->where('detail.linked_operational_evidence.notes.0.purpose', 'immediate_controls')
                ->where('detail.linked_operational_evidence.notes.0.content', 'Loading bay isolated and first aid started.')
                ->where('detail.linked_operational_evidence.tasks.0.id', $openTask->id)
                ->where('detail.linked_operational_evidence.tasks.0.owner.id', $viewer->id)
                ->has('detail.linked_operational_evidence.tasks.0.due_at')
                ->where('detail.linked_operational_evidence.tasks.0.transfer.state', 'open')
                ->where('detail.linked_operational_evidence.tasks.1.id', $transferredTask->id)
                ->where('detail.linked_operational_evidence.tasks.1.transfer.state', 'transferred')
                ->where('detail.linked_operational_evidence.tasks.1.transfer.corrective_action_reference', 'CA-2026-0240')
                ->where('detail.linked_operational_evidence.evidence_packs.0.items.0.download_url', "/incidents/{$incident->id}/control-room-evidence/{$item->id}/download")
                ->where('detail.linked_operational_evidence.communications.0.subject', 'Duty manager update')
            );

        $hsResponse = $this->actingAs($viewer)
            ->get("/health-safety/events?event={$event->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.linked_operational_evidence.label', 'Linked Control Room evidence')
                ->where('detail.linked_operational_evidence.read_only', true)
                ->where('detail.linked_operational_evidence.source.reference', 'CR-2026-2401')
                ->where('detail.linked_operational_evidence.source.href', null)
                ->where('detail.linked_operational_evidence.notes.0.purpose', 'immediate_controls')
                ->where('detail.linked_operational_evidence.tasks.0.id', $openTask->id)
                ->where('detail.linked_operational_evidence.tasks.1.transfer.corrective_action_reference', 'CA-2026-0240')
                ->where('detail.linked_operational_evidence.evidence_packs.0.items.0.download_url', "/health-safety/events/{$event->id}/control-room-evidence/{$item->id}/download")
                ->where('detail.linked_operational_evidence.communications.0.subject', 'Duty manager update')
                ->where('detail.incident_followups.0.notes', 'Check the updated falls plan with the care team.')
                ->where('detail.incident_followups.0.assigned_to', $viewer->name)
            );

        $incidentEvidence = data_get($incidentResponse->viewData('page'), 'props.detail.linked_operational_evidence');
        $hsEvidence = data_get($hsResponse->viewData('page'), 'props.detail.linked_operational_evidence');
        data_set($incidentEvidence, 'evidence_packs.0.items.0.download_url', null);
        data_set($hsEvidence, 'evidence_packs.0.items.0.download_url', null);
        $this->assertSame($incidentEvidence, $hsEvidence);
    }

    public function test_parent_scoped_downloads_require_authentication_and_exact_journey_membership(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('control-room/scene-record.txt', 'scene record');

        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny', 'hazards.view']);
        [$incident, $alert, $event] = $this->journey($site, $viewer);
        [, , $item] = $this->operationalEvidence($alert, $event, $viewer);

        $otherAlert = ControlRoomAlert::factory()->create(['site_id' => $site->id]);
        $otherPack = EvidencePack::query()->create([
            'alert_id' => $otherAlert->id,
            'title' => 'Unrelated evidence',
            'status' => 'complete',
            'item_count' => 1,
        ]);
        $otherItem = EvidenceItem::query()->create([
            'evidence_pack_id' => $otherPack->id,
            'type' => 'document',
            'title' => 'Unrelated file',
            'storage_path' => 'control-room/scene-record.txt',
            'mime_type' => 'text/plain',
        ]);

        $this->get("/incidents/{$incident->id}/control-room-evidence/{$item->id}/download")
            ->assertRedirect('/login');

        $this->actingAs($viewer)
            ->get("/incidents/{$incident->id}/control-room-evidence/{$item->id}/download")
            ->assertOk();
        $this->actingAs($viewer)
            ->get("/health-safety/events/{$event->id}/control-room-evidence/{$item->id}/download")
            ->assertOk();

        $this->actingAs($viewer)
            ->get("/incidents/{$incident->id}/control-room-evidence/{$otherItem->id}/download")
            ->assertNotFound();
        $this->actingAs($viewer)
            ->get("/health-safety/events/{$event->id}/control-room-evidence/{$otherItem->id}/download")
            ->assertNotFound();
    }

    public function test_legacy_context_only_journeys_render_and_download_the_same_linked_evidence(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('control-room/scene-record.txt', 'scene record');

        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny', 'hazards.view']);
        [$incident, $alert, $event] = $this->journey($site, $viewer);
        [, , $item] = $this->operationalEvidence($alert, $event, $viewer);

        $incident->updateQuietly(['control_room_alert_id' => null]);
        $event->updateQuietly(['control_room_alert_id' => null]);
        $alert->updateQuietly([
            'context' => [
                'incident_id' => $incident->id,
            ],
        ]);

        $this->actingAs($viewer)
            ->get("/incidents?incident={$incident->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.control_room_alert.id', $alert->id)
                ->where('detail.linked_operational_evidence.source.reference', 'CR-2026-2401')
                ->where('detail.linked_operational_evidence.evidence_packs.0.items.0.download_url', "/incidents/{$incident->id}/control-room-evidence/{$item->id}/download")
            );

        $this->actingAs($viewer)
            ->get("/health-safety/events?event={$event->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.control_room_alert.id', $alert->id)
                ->where('detail.linked_operational_evidence.source.reference', 'CR-2026-2401')
                ->where('detail.linked_operational_evidence.evidence_packs.0.items.0.download_url', "/health-safety/events/{$event->id}/control-room-evidence/{$item->id}/download")
            );

        $this->actingAs($viewer)
            ->get("/incidents/{$incident->id}/control-room-evidence/{$item->id}/download")
            ->assertOk();
        $this->actingAs($viewer)
            ->get("/health-safety/events/{$event->id}/control-room-evidence/{$item->id}/download")
            ->assertOk();
    }

    public function test_linked_communications_keep_the_latest_twenty_in_chronological_order(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny']);
        [, $alert] = $this->journey($site, $viewer);
        $start = now()->subHour()->startOfSecond();

        foreach (range(1, 21) as $index) {
            $at = $start->copy()->addMinutes($index);
            Communication::unguarded(fn () => Communication::query()->create([
                'alert_id' => $alert->id,
                'channel' => 'phone_call',
                'direction' => 'outbound',
                'purpose' => 'handover',
                'subject' => sprintf('Update %02d', $index),
                'content' => "Communication {$index}",
                'status' => 'sent',
                'sent_at' => $at,
                'created_at' => $at,
                'updated_at' => $at,
            ]));
        }

        $payload = app(LinkedOperationalEvidencePresenter::class)->present(
            $alert,
            $viewer,
            fn (): null => null,
        );

        $this->assertCount(20, $payload['communications']);
        $this->assertSame('Update 02', $payload['communications'][0]['subject']);
        $this->assertSame('Update 21', $payload['communications'][19]['subject']);
    }

    /**
     * @return array{ClientIncident, ControlRoomAlert, HsEvent}
     */
    private function journey(Site $site, User $reporter): array
    {
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'first_name' => 'Mereana',
            'last_name' => 'Ropata',
        ]);
        $alert = ControlRoomAlert::factory()->create([
            'reference_number' => 'CR-2026-2401',
            'site_id' => $site->id,
            'client_id' => $client->id,
            'triggered_at' => '2026-07-16 08:00:00',
        ]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()
            ->atSite($site)
            ->submitted()
            ->create([
                'client_id' => $client->id,
                'reported_by' => $reporter->id,
                'control_room_alert_id' => $alert->id,
            ]));
        $event = HsEvent::factory()->forClientIncident($incident)->create([
            'control_room_alert_id' => $alert->id,
        ]);
        $incident->updateQuietly(['hs_event_id' => $event->id]);

        return [$incident->fresh(), $alert, $event];
    }

    /**
     * @return array{AlertTask, AlertTask, EvidenceItem}
     */
    private function operationalEvidence(
        ControlRoomAlert $alert,
        HsEvent $event,
        User $actor,
    ): array {
        OperatorNote::query()->create([
            'alert_id' => $alert->id,
            'type' => OperatorNote::TYPE_ACTION,
            'purpose' => OperatorNote::PURPOSE_IMMEDIATE_CONTROLS,
            'content' => 'Loading bay isolated and first aid started.',
            'user_id' => $actor->id,
        ]);
        $openTask = AlertTask::query()->create([
            'alert_id' => $alert->id,
            'title' => 'Call the on-call nurse',
            'status' => AlertTask::STATUS_IN_PROGRESS,
            'priority' => 'high',
            'assigned_to_user_id' => $actor->id,
            'due_at' => now()->addHour(),
            'sort_order' => 1,
        ]);
        $transferredTask = AlertTask::query()->create([
            'alert_id' => $alert->id,
            'title' => 'Retain the loading-bay recording',
            'status' => AlertTask::STATUS_TRANSFERRED,
            'priority' => 'critical',
            'sort_order' => 2,
        ]);
        $action = HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id,
            'source_control_room_task_id' => $transferredTask->id,
            'reference_number' => 'CA-2026-0240',
            'title' => 'Review the retained loading-bay recording',
            'assigned_to_user_id' => $actor->id,
        ]);
        $transferredTask->update([
            'transferred_to_hs_corrective_action_id' => $action->id,
            'transferred_at' => now(),
            'transferred_by_user_id' => $actor->id,
        ]);
        $pack = EvidencePack::query()->create([
            'alert_id' => $alert->id,
            'title' => 'Loading-bay evidence',
            'status' => 'complete',
            'item_count' => 1,
        ]);
        $item = EvidenceItem::query()->create([
            'evidence_pack_id' => $pack->id,
            'type' => 'document',
            'title' => 'Scene preservation record',
            'description' => 'The duty manager retained the recording.',
            'storage_path' => 'control-room/scene-record.txt',
            'mime_type' => 'text/plain',
            'file_size' => 12,
            'captured_at' => now(),
            'captured_by_user_id' => $actor->id,
        ]);
        Communication::query()->create([
            'alert_id' => $alert->id,
            'channel' => 'phone_call',
            'direction' => 'outbound',
            'purpose' => 'handover',
            'subject' => 'Duty manager update',
            'content' => 'Duty manager confirmed the immediate controls.',
            'status' => 'sent',
            'sent_at' => now(),
            'initiated_by_user_id' => $actor->id,
        ]);

        return [$openTask, $transferredTask, $item];
    }

    /** @param list<string> $permissionKeys */
    private function siteBoundUser(Site $site, array $permissionKeys): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
        ]);
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
