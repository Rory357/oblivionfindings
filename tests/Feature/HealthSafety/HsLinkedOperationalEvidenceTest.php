<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
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
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny', 'hazards.view', 'controlRoom.alerts.view']);
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
                ->where('detail.linked_operational_evidence.source.href', "/control-room/alerts/{$alert->id}")
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
                ->where('detail.linked_operational_evidence.source.href', "/control-room/alerts/{$alert->id}")
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
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny', 'hazards.view', 'controlRoom.alerts.view']);
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
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny', 'hazards.view', 'controlRoom.alerts.view']);
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
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny', 'controlRoom.alerts.view']);
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

    public function test_parent_access_does_not_disclose_control_room_evidence_or_allow_its_downloads(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('control-room/scene-record.txt', 'scene record');
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny', 'hazards.view']);
        [$incident, $alert, $event] = $this->journey($site, $viewer);
        [, , $item] = $this->operationalEvidence($alert, $event, $viewer);

        foreach (["/incidents/{$incident->id}", "/health-safety/events?event={$event->id}"] as $url) {
            $response = $this->actingAs($viewer)->get($url)->assertOk();
            $detail = data_get($response->viewData('page'), 'props.detail');
            $this->assertNull($detail['control_room_alert']);
            $this->assertSame([
                'label' => 'Linked Control Room evidence',
                'read_only' => true,
                'access' => 'restricted',
                'source' => null,
                'notes' => [],
                'tasks' => [],
                'evidence_packs' => [],
                'communications' => [],
            ], $detail['linked_operational_evidence']);
            foreach (['CR-2026-2401', 'Loading bay isolated', 'Scene preservation record', 'Duty manager update'] as $secret) {
                $this->assertStringNotContainsString($secret, json_encode($detail, JSON_THROW_ON_ERROR));
            }
        }
        $this->get("/incidents/{$incident->id}/control-room-evidence/{$item->id}/download")->assertForbidden();
        $this->get("/health-safety/events/{$event->id}/control-room-evidence/{$item->id}/download")->assertForbidden();
    }

    public function test_source_permission_is_rechecked_after_a_download_link_has_been_issued(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('control-room/scene-record.txt', 'scene record');
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny', 'hazards.view', 'controlRoom.alerts.view']);
        [$incident, $alert, $event] = $this->journey($site, $viewer);
        [, , $item] = $this->operationalEvidence($alert, $event, $viewer);
        $urls = [
            "/incidents/{$incident->id}/control-room-evidence/{$item->id}/download",
            "/health-safety/events/{$event->id}/control-room-evidence/{$item->id}/download",
        ];
        foreach ($urls as $url) {
            $this->actingAs($viewer)->get($url)->assertOk();
        }

        $permission = Permission::query()->where('key', 'controlRoom.alerts.view')->firstOrFail();
        $viewer->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        foreach ($urls as $url) {
            $this->actingAs($viewer->fresh())->get($url)->assertForbidden();
        }
    }

    public function test_controlled_medication_source_does_not_inherit_incident_or_hs_access(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('control-room/scene-record.txt', 'scene record');
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny', 'hazards.view', 'controlRoom.alerts.view']);
        [$incident, $alert, $event] = $this->journey($site, $viewer);
        [, , $item] = $this->operationalEvidence($alert, $event, $viewer);
        $alert->updateQuietly(['context' => ['normalized_data' => ['controlled_drug' => true]]]);

        $payload = app(LinkedOperationalEvidencePresenter::class)->present($alert, $viewer, function (): never {
            $this->fail('A restricted source must not produce any download links.');
        });
        $this->assertSame('restricted', $payload['access']);
        $this->assertNull($payload['source']);
        $this->actingAs($viewer)->get("/incidents/{$incident->id}/control-room-evidence/{$item->id}/download")->assertForbidden();
        $this->get("/health-safety/events/{$event->id}/control-room-evidence/{$item->id}/download")->assertForbidden();
    }

    public function test_control_room_read_access_does_not_disclose_a_transferred_hs_action_reference(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny', 'controlRoom.alerts.view']);
        [, $alert, $event] = $this->journey($site, $viewer);
        $this->operationalEvidence($alert, $event, $viewer);

        $payload = app(LinkedOperationalEvidencePresenter::class)->present($alert, $viewer, fn (): null => null);
        $this->assertSame('readable', $payload['access']);
        $this->assertSame('transferred', $payload['tasks'][1]['transfer']['state']);
        $this->assertNull($payload['tasks'][1]['transfer']['corrective_action_reference']);
        $this->assertStringNotContainsString('CA-2026-0240', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_incident_detail_retains_the_closure_dependency_without_disclosing_restricted_hs_details(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny']);
        [$incident, , $event] = $this->journey($site, $viewer);
        $event->updateQuietly([
            'worksafe_reference' => 'PrivateWorkSafeReference',
            'acceptance_notes' => 'Private acceptance account.',
        ]);
        $response = $this->actingAs($viewer)->get("/incidents/{$incident->id}")->assertOk();
        $detail = data_get($response->viewData('page'), 'props.detail');
        $this->assertSame('restricted', $detail['linked_access']['health_safety']);
        $this->assertNull($detail['hs_event']);
        $this->assertNull($detail['worksafe_reference']);
        $this->assertFalse($detail['close_gate']['allowed']);
        $requirement = collect($detail['close_gate']['requirements'])->firstWhere('key', 'health_safety_governance');
        $this->assertFalse($requirement['complete']);
        $this->assertNull($requirement['href']);
        foreach ([$event->reference_number, 'PrivateWorkSafeReference', 'Private acceptance account.'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($detail, JSON_THROW_ON_ERROR));
        }
    }

    public function test_hs_detail_and_downloads_respect_the_incident_confidentiality_boundary(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('incident/private.txt', 'private staff account');
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['incidents.viewAny', 'hazards.view']);
        [$incident, , $event] = $this->journey($site, $viewer);
        $incident->forceFill(['is_hr_confidential' => true, 'description' => 'PrivateStaffNarrative'])->saveQuietly();
        $this->assertTrue((bool) $incident->fresh()->is_hr_confidential);
        $attachment = ClientIncidentAttachment::query()->create([
            'incident_id' => $incident->id,
            'uploaded_by' => $viewer->id,
            'disk' => 'local',
            'path' => 'incident/private.txt',
            'original_name' => 'PrivateStaffFilename.txt',
            'mime' => 'text/plain',
        ]);
        IncidentFollowup::query()->create([
            'client_incident_id' => $incident->id,
            'notes' => 'PrivateStaffFollowup',
            'created_by' => $viewer->id,
        ]);
        $response = $this->actingAs($viewer)->get("/health-safety/events?event={$event->id}")->assertOk();
        $detail = data_get($response->viewData('page'), 'props.detail');
        $this->assertSame('restricted', $detail['linked_access']['incident']);
        $this->assertNull($detail['source']);
        $this->assertNull($detail['description']);
        $this->assertEmpty($detail['attachments']);
        $this->assertEmpty($detail['incident_followups']);
        foreach (['PrivateStaffNarrative', 'PrivateStaffFilename', 'PrivateStaffFollowup', $incident->reference_number] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($detail, JSON_THROW_ON_ERROR));
        }
        $this->get("/health-safety/events/{$event->id}/incident-attachments/{$attachment->id}/download")->assertForbidden();

        $permission = Permission::query()->where('key', 'hr.cases.view')->firstOrFail();
        $viewer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $this->actingAs($viewer->fresh())->get("/health-safety/events/{$event->id}/incident-attachments/{$attachment->id}/download")->assertOk();
    }

    /** @return array{ClientIncident, ControlRoomAlert, HsEvent} */
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
                'reference_number' => 'INC-PRIVACY-2401',
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
