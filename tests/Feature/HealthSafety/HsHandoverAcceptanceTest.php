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
use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\AlertWorkspaceService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HsHandoverAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_authorised_hs_user_accepts_a_submitted_incident_handover_without_changing_governance_status(): void
    {
        $site = Site::factory()->create();
        $actor = $this->siteBoundUser($site, ['hazards.manage']);
        $owner = $this->siteBoundUser($site, ['hazards.manage']);
        [$incident, $alert, $event] = $this->incidentJourney($site, $actor, [
            'status' => HsEvent::STATUS_INVESTIGATING,
        ]);
        $acceptedAt = now()->startOfSecond();
        $this->travelTo($acceptedAt);

        $this->actingAs($actor)
            ->from("/health-safety/events?event={$event->id}")
            ->post("/health-safety/events/{$event->id}/accept-handover", [
                'owner_user_id' => $owner->id,
                'acceptance_notes' => 'Reviewed the controls and accepted ownership.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $event->refresh();
        $this->assertSame(HsEvent::HANDOVER_ACCEPTED, $event->handover_status);
        $this->assertSame($owner->id, $event->owner_user_id);
        $this->assertSame($actor->id, $event->accepted_by_user_id);
        $this->assertTrue($event->accepted_at->equalTo($acceptedAt));
        $this->assertSame('Reviewed the controls and accepted ownership.', $event->acceptance_notes);
        $this->assertSame(HsEvent::STATUS_INVESTIGATING, $event->status);
        $this->assertSame($event->id, $incident->fresh()->hs_event_id);
        $this->assertSame($alert->id, $incident->fresh()->control_room_alert_id);
    }

    public function test_acceptance_is_monotonic_and_an_identical_retry_does_not_rewrite_the_audit_identity(): void
    {
        $site = Site::factory()->create();
        $firstActor = $this->siteBoundUser($site, ['hazards.manage']);
        $secondActor = $this->siteBoundUser($site, ['hazards.manage']);
        [, , $event] = $this->incidentJourney($site, $firstActor);

        $this->travelTo(now()->startOfSecond());
        $this->actingAs($firstActor)->post("/health-safety/events/{$event->id}/accept-handover", [
            'acceptance_notes' => 'First accepted handover.',
        ])->assertRedirect();
        $firstAcceptedAt = $event->fresh()->accepted_at;

        $this->travel(10)->minutes();
        $this->actingAs($secondActor)->post("/health-safety/events/{$event->id}/accept-handover", [
            'owner_user_id' => $secondActor->id,
            'acceptance_notes' => 'A retry must not replace the first acceptance.',
        ])->assertRedirect();

        $event->refresh();
        $this->assertSame($firstActor->id, $event->accepted_by_user_id);
        $this->assertSame($firstActor->id, $event->owner_user_id);
        $this->assertTrue($event->accepted_at->equalTo($firstAcceptedAt));
        $this->assertSame('First accepted handover.', $event->acceptance_notes);
    }

    public function test_acceptance_is_site_scoped_and_rejects_an_owner_from_another_site(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $actorA = $this->siteBoundUser($siteA, ['hazards.view', 'hazards.manage']);
        $actorB = $this->siteBoundUser($siteB, ['hazards.manage']);
        $supportWorkerA = $this->siteBoundUser($siteA, ['hazards.view']);
        $ownerB = $this->siteBoundUser($siteB, ['hazards.manage']);
        [, , $event] = $this->incidentJourney($siteA, $actorA);

        $this->actingAs($actorB)
            ->post("/health-safety/events/{$event->id}/accept-handover")
            ->assertNotFound();

        $this->actingAs($actorA)
            ->from("/health-safety/events?event={$event->id}")
            ->post("/health-safety/events/{$event->id}/accept-handover", [
                'owner_user_id' => $ownerB->id,
            ])
            ->assertSessionHasErrors(['owner_user_id']);

        $this->actingAs($actorA)
            ->from("/health-safety/events?event={$event->id}")
            ->post("/health-safety/events/{$event->id}/accept-handover", [
                'owner_user_id' => $supportWorkerA->id,
            ])
            ->assertSessionHasErrors(['owner_user_id']);

        $this->actingAs($actorA)
            ->get("/health-safety/events?event={$event->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.assignable_staff', fn ($staff) => collect($staff)->contains('id', $actorA->id)
                    && ! collect($staff)->contains('id', $supportWorkerA->id))
            );

        $this->assertSame(HsEvent::HANDOVER_AWAITING_ACCEPTANCE, $event->fresh()->handover_status);
        $this->assertNull($event->fresh()->accepted_at);
    }

    public function test_acceptance_requires_hs_manage_permission_and_an_awaiting_incident_handover(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['hazards.view']);
        $manager = $this->siteBoundUser($site, ['hazards.view', 'hazards.manage']);
        [, , $event] = $this->incidentJourney($site, $manager);
        $notRequired = HsEvent::factory()->create([
            'site_id' => $site->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);

        $this->actingAs($viewer)
            ->post("/health-safety/events/{$event->id}/accept-handover")
            ->assertForbidden();

        $this->actingAs($manager)
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$notRequired->id}/accept-handover")
            ->assertSessionHasErrors(['handover']);

        $this->assertSame(HsEvent::HANDOVER_NOT_REQUIRED, $notRequired->fresh()->handover_status);
    }

    public function test_acceptance_is_visible_in_hs_incident_and_control_room_payloads(): void
    {
        $site = Site::factory()->create();
        $actor = $this->siteBoundUser($site, ['hazards.manage']);
        [$incident, $alert, $event] = $this->incidentJourney($site, $actor);

        $this->actingAs($actor)->post("/health-safety/events/{$event->id}/accept-handover", [
            'acceptance_notes' => 'Accepted after reviewing operational evidence.',
        ])->assertRedirect();

        $viewer = $this->siteBoundUser($site, [
            'hazards.view',
            'incidents.viewAny',
            'controlRoom.viewAny',
        ]);

        $this->actingAs($viewer)
            ->get("/health-safety/events?event={$event->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.handover.status', HsEvent::HANDOVER_ACCEPTED)
                ->where('detail.handover.owner.id', $actor->id)
                ->where('detail.handover.accepted_by.id', $actor->id)
                ->where('detail.handover.notes', 'Accepted after reviewing operational evidence.')
                ->where('detail.lifecycle.health_safety', HsEvent::STATUS_OPEN)
                ->where('detail.lifecycle.incident', 'submitted')
                ->where('detail.lifecycle.control_room', 'open')
            );

        $this->actingAs($viewer)
            ->get("/incidents?incident={$incident->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.hs_event.handover.status', HsEvent::HANDOVER_ACCEPTED)
                ->where('detail.hs_event.handover.owner.id', $actor->id)
                ->where('detail.hs_event.handover.accepted_by.id', $actor->id)
                ->has('detail.hs_event.handover.accepted_at')
            );

        $workspace = app(AlertWorkspaceService::class)->build($viewer, $alert->id);
        $this->assertSame(HsEvent::HANDOVER_ACCEPTED, data_get($workspace, 'linked_hs_event.handover.status'));
        $this->assertSame($actor->id, data_get($workspace, 'linked_hs_event.handover.owner.id'));
        $this->assertSame($actor->id, data_get($workspace, 'linked_hs_event.handover.accepted_by.id'));
        $this->assertNotNull(data_get($workspace, 'linked_hs_event.handover.accepted_at'));
        $this->assertSame("/health-safety/events/{$event->id}", data_get($workspace, 'linked_hs_event.href'));

        $controlRoomOnly = $this->siteBoundUser($site, ['controlRoom.viewAny']);
        $controlRoomWorkspace = app(AlertWorkspaceService::class)->build($controlRoomOnly, $alert->id);
        $this->assertSame(HsEvent::HANDOVER_ACCEPTED, data_get($controlRoomWorkspace, 'linked_hs_event.handover.status'));
        $this->assertSame($actor->id, data_get($controlRoomWorkspace, 'linked_hs_event.handover.owner.id'));
        $this->assertNull(data_get($controlRoomWorkspace, 'linked_hs_event.href'));
    }

    public function test_awaiting_handover_is_a_discoverable_hs_worklist_lens(): void
    {
        $site = Site::factory()->create();
        $manager = $this->siteBoundUser($site, ['hazards.view', 'hazards.manage']);
        [, , $event] = $this->incidentJourney($site, $manager);

        $this->actingAs($manager)
            ->get('/health-safety/events?tab=handover')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tabCounts.handover', 1)
                ->where('hero.attention.handover_due', 1)
                ->has('events.data', 1)
                ->where('events.data.0.id', $event->id)
                ->where('events.data.0.handover.status', HsEvent::HANDOVER_AWAITING_ACCEPTANCE)
            );
    }

    public function test_handover_next_action_and_cross_module_links_are_capability_aware(): void
    {
        $site = Site::factory()->create();
        $manager = $this->siteBoundUser($site, ['hazards.view', 'hazards.manage']);
        $viewer = $this->siteBoundUser($site, ['hazards.view']);
        $crossModuleViewer = $this->siteBoundUser($site, [
            'hazards.view',
            'incidents.viewAny',
            'controlRoom.viewAny',
        ]);
        [$incident, $alert, $event] = $this->incidentJourney($site, $manager, [
            'worksafe_notifiable' => true,
            'worksafe_status' => HsEvent::WORKSAFE_PENDING,
        ]);
        $this->assertFalse($viewer->canDo('incidents.viewAny'));
        $this->assertFalse($viewer->canDo('incidents.viewAssigned'));
        $this->assertFalse($viewer->canDo('controlRoom.viewAny'));

        $this->actingAs($viewer)
            ->get("/health-safety/events?event={$event->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.handover.can_accept', false)
                ->where('detail.handover_summary.next_action', null)
                ->where('detail.source.url', null)
                ->where('detail.control_room_alert.reference_number', $alert->reference_number)
                ->where('detail.control_room_alert.url', null)
            );

        $this->actingAs($crossModuleViewer)
            ->get("/health-safety/events?event={$event->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.source.url', "/incidents/{$incident->id}")
                ->where('detail.control_room_alert.url', "/control-room/alerts/{$alert->id}")
            );

        $this->actingAs($manager)
            ->get("/health-safety/events?event={$event->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.handover_summary.next_action.label', 'Accept this H&S handover')
            );
    }

    public function test_incident_detail_emits_cross_module_urls_only_when_the_viewer_can_open_them(): void
    {
        $site = Site::factory()->create();
        $reporter = $this->siteBoundUser($site, ['hazards.manage']);
        [$incident, $alert, $event] = $this->incidentJourney($site, $reporter);
        $incidentOnlyViewer = $this->siteBoundUser($site, ['incidents.viewAny']);
        $crossModuleViewer = $this->siteBoundUser($site, [
            'incidents.viewAny',
            'hazards.view',
            'controlRoom.viewAny',
        ]);

        $this->actingAs($incidentOnlyViewer)
            ->get("/incidents?incident={$incident->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.control_room_alert.url', null)
                ->where('detail.hs_event.url', null)
                ->where('detail.hs_event.corrective_actions_url', null)
            );

        $this->actingAs($crossModuleViewer)
            ->get("/incidents?incident={$incident->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.control_room_alert.url', "/control-room/alerts/{$alert->id}")
                ->where('detail.hs_event.url', "/health-safety/events/{$event->id}")
                ->where('detail.hs_event.corrective_actions_url', "/health-safety/corrective-actions?event={$event->id}")
            );
    }

    public function test_hs_user_can_download_only_incident_evidence_from_the_accessible_handover(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('public')->put('incident_attachments/handover.txt', 'handover evidence');
        Storage::disk('local')->put('control-room/evidence-note.txt', 'control room evidence');
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['hazards.view']);
        [$incident, $alert, $event] = $this->incidentJourney($site, $viewer);
        $attachment = ClientIncidentAttachment::query()->create([
            'incident_id' => $incident->id,
            'uploaded_by' => $viewer->id,
            'disk' => 'public',
            'original_name' => 'handover.txt',
            'path' => 'incident_attachments/handover.txt',
            'mime' => 'text/plain',
            'mime_type' => 'text/plain',
            'size' => 17,
        ]);
        $otherIncident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create());
        $otherAttachment = ClientIncidentAttachment::query()->create([
            'incident_id' => $otherIncident->id,
            'uploaded_by' => $viewer->id,
            'disk' => 'public',
            'original_name' => 'other.txt',
            'path' => 'incident_attachments/handover.txt',
            'mime' => 'text/plain',
            'mime_type' => 'text/plain',
            'size' => 17,
        ]);
        $evidencePack = EvidencePack::query()->create([
            'alert_id' => $alert->id,
            'title' => 'Handover evidence',
            'status' => 'complete',
            'created_by_user_id' => $viewer->id,
        ]);
        $evidenceItem = EvidenceItem::query()->create([
            'evidence_pack_id' => $evidencePack->id,
            'type' => 'document',
            'title' => 'Control Room evidence note',
            'storage_path' => 'control-room/evidence-note.txt',
            'mime_type' => 'text/plain',
            'metadata' => ['original_name' => 'evidence-note.txt'],
        ]);

        $this->actingAs($viewer)
            ->get("/health-safety/events/{$event->id}/incident-attachments/{$attachment->id}/download")
            ->assertOk();

        $this->actingAs($viewer)
            ->get("/health-safety/events/{$event->id}/incident-attachments/{$otherAttachment->id}/download")
            ->assertNotFound();

        $this->actingAs($viewer)
            ->get("/health-safety/events/{$event->id}/control-room-evidence/{$evidenceItem->id}/download")
            ->assertOk();
    }

    public function test_hs_handover_payload_contains_the_complete_real_journey_contract(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('public')->put('incident_attachments/scene.txt', 'scene evidence');
        Storage::disk('local')->put('evidence/scene-note.txt', 'control room evidence');
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['hazards.view']);
        [$incident, $alert, $event] = $this->incidentJourney($site, $viewer);
        $incident->updateQuietly([
            'witnesses' => 'Hana Te Rangi',
            'potential_consequence' => 'Serious injury if the control failed.',
        ]);
        ClientIncidentAttachment::query()->create([
            'incident_id' => $incident->id,
            'uploaded_by' => $viewer->id,
            'disk' => 'public',
            'original_name' => 'scene.txt',
            'path' => 'incident_attachments/scene.txt',
            'mime' => 'text/plain',
            'mime_type' => 'text/plain',
            'size' => 14,
        ]);
        $pack = EvidencePack::query()->create([
            'alert_id' => $alert->id,
            'title' => 'Scene evidence pack',
            'status' => 'complete',
            'item_count' => 1,
            'created_by_user_id' => $viewer->id,
        ]);
        EvidenceItem::query()->create([
            'evidence_pack_id' => $pack->id,
            'type' => 'document',
            'title' => 'Preservation note',
            'storage_path' => 'evidence/scene-note.txt',
            'mime_type' => 'text/plain',
            'file_size' => 21,
            'captured_at' => now(),
            'captured_by_user_id' => $viewer->id,
        ]);
        $playbook = Playbook::factory()->create(['name' => 'Serious incident response']);
        $run = PlaybookRun::query()->create([
            'playbook_id' => $playbook->id,
            'alert_id' => $alert->id,
            'status' => PlaybookRun::STATUS_COMPLETED,
            'context' => ['outcome' => 'Scene secured and escalation completed.'],
        ]);
        $alert->updateQuietly(['playbook_run_id' => $run->id]);
        Communication::query()->create([
            'alert_id' => $alert->id,
            'channel' => 'phone_call',
            'direction' => 'outbound',
            'purpose' => 'handover',
            'content' => 'Duty manager briefed on immediate controls.',
            'status' => 'sent',
            'sent_at' => now(),
            'initiated_by_user_id' => $viewer->id,
        ]);
        AlertTask::query()->create([
            'alert_id' => $alert->id,
            'title' => 'Preserve the scene recording',
            'assigned_to_user_id' => $viewer->id,
            'created_by_user_id' => $viewer->id,
            'status' => AlertTask::STATUS_IN_PROGRESS,
            'priority' => 'high',
            'due_at' => now()->addHour(),
            'sort_order' => 1,
        ]);

        $this->actingAs($viewer)
            ->get("/health-safety/events?event={$event->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.handover_summary.incident_reference', $incident->reference_number)
                ->where('detail.handover_summary.alert_reference', $alert->reference_number)
                ->where('detail.handover_summary.narrative', 'A factual incident narrative for handover.')
                ->where('detail.handover_summary.immediate_controls', 'Made the area safe and checked the person.')
                ->where('detail.handover_summary.witnesses', 'Hana Te Rangi')
                ->where('detail.handover_summary.potential_consequence', 'Serious injury if the control failed.')
                ->where('detail.handover_summary.attachments.0.name', 'scene.txt')
                ->where('detail.handover_summary.control_room_evidence.0.title', 'Scene evidence pack')
                ->where('detail.handover_summary.control_room_evidence.0.items.0.title', 'Preservation note')
                ->where('detail.handover_summary.playbook.name', 'Serious incident response')
                ->where('detail.handover_summary.playbook.outcome', 'Scene secured and escalation completed.')
                ->where('detail.handover_summary.communications.0.content', 'Duty manager briefed on immediate controls.')
                ->where('detail.handover_summary.operational_tasks.0.title', 'Preserve the scene recording')
                ->where('detail.handover_summary.site_name', $site->name)
            );
    }

    /**
     * @param  array<string, mixed>  $eventOverrides
     * @return array{ClientIncident, ControlRoomAlert, HsEvent}
     */
    private function incidentJourney(Site $site, User $reporter, array $eventOverrides = []): array
    {
        $client = Client::factory()->create(['site_id' => $site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()
            ->atSite($site)
            ->submitted()
            ->create([
                'client_id' => $client->id,
                'reported_by' => $reporter->id,
                'submitted_at' => now(),
                'description' => 'A factual incident narrative for handover.',
                'immediate_action_taken' => 'Made the area safe and checked the person.',
            ]));
        $alert = ControlRoomAlert::factory()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);
        $event = HsEvent::factory()
            ->forClientIncident($incident)
            ->awaitingHandoverAcceptance($reporter)
            ->create(array_merge([
                'control_room_alert_id' => $alert->id,
            ], $eventOverrides));

        $incident->updateQuietly([
            'hs_event_id' => $event->id,
            'control_room_alert_id' => $alert->id,
        ]);

        return [$incident->fresh(), $alert, $event];
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
