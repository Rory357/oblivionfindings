<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsCorrectiveActionService;
use App\Support\HealthSafety\HsCorrectiveActionPresenter;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HsCorrectiveActionPresentationTest extends TestCase
{
    use RefreshDatabase;

    private HsCorrectiveActionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->service = app(HsCorrectiveActionService::class);
    }

    public function test_event_and_register_share_the_complete_verifier_evidence_contract(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $owner = $this->staffAtSite($site, 'health_safety_officer');
        $verifier = $this->staffAtSite($site, 'health_safety_officer');
        $alert = ControlRoomAlert::factory()->triaging()->create();
        $event = HsEvent::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
            'control_room_alert_id' => $alert->id,
            'status' => HsEvent::STATUS_CORRECTIVE_ACTION,
        ]);
        $investigation = HsInvestigation::factory()->completed()->create([
            'hs_event_id' => $event->id,
            'recommendations' => [[
                'description' => 'Install permanent anti-slip surfacing.',
                'priority' => HsCorrectiveAction::PRIORITY_HIGH,
            ]],
        ]);
        $task = AlertTask::query()->create([
            'alert_id' => $alert->id,
            'title' => 'Make the loading bay safe',
            'created_by_user_id' => $verifier->id,
            'status' => AlertTask::STATUS_TRANSFERRED,
            'priority' => HsCorrectiveAction::PRIORITY_HIGH,
            'transferred_at' => now(),
            'transferred_by_user_id' => $verifier->id,
        ]);
        $action = HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id,
            'hs_investigation_id' => $investigation->id,
            'source_control_room_task_id' => $task->id,
            'organization_id' => 1,
            'recommendation_index' => 0,
            'assigned_to_user_id' => $owner->id,
            'assigned_by_user_id' => $verifier->id,
            'assigned_at' => now(),
            'due_date' => '2026-08-31',
            'status' => HsCorrectiveAction::STATUS_OPEN,
        ]);
        $task->update(['transferred_to_hs_corrective_action_id' => $action->id]);

        $this->actingAs($owner);
        $this->service->start($action);
        $action->attachments()->create([
            'uploaded_by' => $owner->id,
            'original_name' => 'after-photo.jpg',
            'path' => "health-safety/corrective-actions/{$action->id}/after-photo.jpg",
            'disk' => 'private',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
            'description' => 'Wide-angle completion photo.',
        ]);
        $this->service->complete($action->fresh(), [
            'completion_notes' => 'Installed anti-slip surfacing.',
            'completion_evidence_paths' => ['legacy/contractor-sign-off.pdf'],
            'completed_by_user_id' => $owner->id,
        ]);

        $this->actingAs($verifier);
        $this->service->returnForRework(
            $action->fresh(),
            'Add a wider-angle photo.',
        );

        $this->actingAs($owner);
        $this->service->complete($action->fresh(), [
            'completion_notes' => 'Installed surfacing and added the wider-angle photo.',
            'completed_by_user_id' => $owner->id,
        ]);

        $assertContract = fn (Assert $page, string $root): Assert => $page
            ->where("{$root}.owner.id", $owner->id)
            ->where("{$root}.owner.name", $owner->name)
            ->where("{$root}.due_date", '2026-08-31')
            ->where(
                "{$root}.recommendation",
                'Install permanent anti-slip surfacing.',
            )
            ->where("{$root}.source_task.id", $task->id)
            ->where("{$root}.source_task.title", $task->title)
            ->where(
                "{$root}.evidence.completion_notes",
                'Installed surfacing and added the wider-angle photo.',
            )
            ->where(
                "{$root}.evidence.attachments.0.original_name",
                'after-photo.jpg',
            )
            ->where(
                "{$root}.evidence.legacy_paths.0",
                'legacy/contractor-sign-off.pdf',
            )
            ->where("{$root}.evidence.completed_by.id", $owner->id)
            ->where("{$root}.evidence.completed_by.name", $owner->name)
            ->where("{$root}.evidence.load_state", 'loaded')
            ->where("{$root}.rework.latest_reason", 'Add a wider-angle photo.')
            ->where("{$root}.history", function ($history): bool {
                $labels = collect($history)->pluck('label');

                return $labels->contains('Action created')
                    && $labels->contains('Owner started action')
                    && $labels->contains('Owner submitted evidence')
                    && $labels->contains('Action returned for rework')
                    && $labels->contains('Owner resubmitted evidence');
            });

        $this->actingAs($verifier)
            ->get("/health-safety/events/{$event->id}")
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $assertContract(
                    $page,
                    'detail.corrective_actions.0',
                ),
            );

        $this->actingAs($verifier)
            ->get('/health-safety/corrective-actions?tab=awaiting_verification')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $assertContract($page, 'actions.data.0'),
            );

        $this->actingAs($owner)
            ->get("/health-safety/events/{$event->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where(
                    'detail.corrective_actions.0.rework.latest_reason',
                    'Add a wider-angle photo.',
                )
                ->where('detail.corrective_actions.0.can_verify', false)
            );

        $this->actingAs($verifier);
        $this->service->verify($action->fresh(), [
            'verified_by_user_id' => $verifier->id,
            'evidence_reviewed' => true,
            'effectiveness_confirmed' => true,
            'verification_notes' => 'The completed control is effective.',
        ]);
        $this->service->close($action->fresh(), $verifier->id);

        $this->get("/health-safety/events/{$event->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where(
                    'detail.corrective_actions.0.evidence.attachments.0.can_remove',
                    false,
                )
                ->where(
                    'detail.corrective_actions.0.history',
                    function ($history): bool {
                        $labels = collect($history)->pluck('label');

                        return $labels->contains('Action independently verified')
                            && $labels->contains('Action closed');
                    },
                )
            );
    }

    public function test_http_verification_requires_acknowledgement_and_maps_the_effective_decision(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $owner = $this->staffAtSite($site);
        $verifier = $this->staffAtSite($site, 'health_safety_officer');
        $event = HsEvent::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
        ]);
        $action = HsCorrectiveAction::factory()->completed()->create([
            'hs_event_id' => $event->id,
            'organization_id' => 1,
            'assigned_to_user_id' => $owner->id,
            'completed_by_user_id' => $owner->id,
        ]);
        $url = "/health-safety/events/{$event->id}/corrective-actions/{$action->id}/verify";

        $this->actingAs($verifier)
            ->post($url, [
                'effective' => true,
                'verification_notes' => 'The repair is effective.',
            ])
            ->assertSessionHasErrors('evidence_reviewed');

        $this->actingAs($verifier)
            ->post($url, [
                'evidence_reviewed' => true,
                'effective' => false,
                'verification_notes' => 'Further monitoring is required.',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('hs_corrective_actions', [
            'id' => $action->id,
            'status' => HsCorrectiveAction::STATUS_VERIFIED,
            'verified_by_user_id' => $verifier->id,
            'effectiveness_confirmed' => false,
            'verification_notes' => 'Further monitoring is required.',
        ]);
    }

    public function test_cross_site_manager_cannot_blind_verify_evidence(): void
    {
        $eventSite = Site::factory()->create(['tenant_id' => 1]);
        $otherSite = Site::factory()->create(['tenant_id' => 1]);
        $owner = $this->staffAtSite($eventSite, 'health_safety_officer');
        $crossSiteManager = $this->staffAtSite(
            $otherSite,
            'health_safety_officer',
        );
        $event = HsEvent::factory()->create([
            'organization_id' => 1,
            'site_id' => $eventSite->id,
        ]);
        $action = HsCorrectiveAction::factory()->completed()->create([
            'hs_event_id' => $event->id,
            'organization_id' => 1,
            'assigned_to_user_id' => $owner->id,
            'completed_by_user_id' => $owner->id,
        ]);

        $this->actingAs($crossSiteManager)
            ->get("/health-safety/events/{$event->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.corrective_actions.0.evidence.load_state', 'unavailable')
                ->where('detail.corrective_actions.0.can_verify', false)
                ->where(
                    'detail.can.manage_corrective_action_lifecycle',
                    false,
                )
            );

        $this->actingAs($crossSiteManager)
            ->post(
                "/health-safety/events/{$event->id}/corrective-actions/{$action->id}/verify",
                [
                    'evidence_reviewed' => true,
                    'effective' => true,
                ],
            )
            ->assertNotFound();

        $this->assertSame(
            HsCorrectiveAction::STATUS_COMPLETED,
            $action->fresh()->status,
        );
    }

    public function test_http_completion_accepts_a_retained_attachment_without_notes(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $owner = $this->staffAtSite($site, 'health_safety_officer');
        $event = HsEvent::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
        ]);
        $action = HsCorrectiveAction::factory()->inProgress()->create([
            'hs_event_id' => $event->id,
            'organization_id' => 1,
            'assigned_to_user_id' => $owner->id,
        ]);
        $action->attachments()->create([
            'uploaded_by' => $owner->id,
            'original_name' => 'retained-photo.jpg',
            'path' => "health-safety/corrective-actions/{$action->id}/retained-photo.jpg",
            'disk' => 'private',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
        ]);

        $this->actingAs($owner)
            ->post(
                "/health-safety/events/{$event->id}/corrective-actions/{$action->id}/complete",
                [],
            )
            ->assertSessionHas('success');

        $action->refresh();
        $this->assertSame(HsCorrectiveAction::STATUS_COMPLETED, $action->status);
        $this->assertSame($owner->id, $action->completed_by_user_id);
        $this->assertNotNull($action->completed_at);
    }

    public function test_unavailable_evidence_state_removes_metadata_and_disables_verification(): void
    {
        $owner = User::factory()->create();
        $verifier = User::factory()->create();
        $action = HsCorrectiveAction::factory()->completed()->create([
            'assigned_to_user_id' => $owner->id,
            'completed_by_user_id' => $owner->id,
            'completion_notes' => 'Sensitive completion detail.',
        ]);
        $action->attachments()->create([
            'uploaded_by' => $owner->id,
            'original_name' => 'private-photo.jpg',
            'path' => "health-safety/corrective-actions/{$action->id}/private-photo.jpg",
            'disk' => 'private',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
        ]);
        $action->load([
            'assignedTo:id,name',
            'completedBy:id,name',
            'verifiedBy:id,name',
            'hsInvestigation:id,recommendations',
            'sourceControlRoomTask:id,title',
            'attachments.uploader:id,name',
            'auditLogs.user:id,name',
        ]);

        $payload = app(HsCorrectiveActionPresenter::class)->present(
            $action,
            $verifier,
            true,
            true,
            HsCorrectiveActionPresenter::EVIDENCE_UNAVAILABLE,
        );

        $this->assertFalse($payload['can_verify']);
        $this->assertSame('unavailable', $payload['evidence']['load_state']);
        $this->assertNull($payload['evidence']['completion_notes']);
        $this->assertNull($payload['evidence']['completed_by']);
        $this->assertSame([], $payload['evidence']['attachments']);
        $this->assertNull($payload['rework']['latest_reason']);
        $this->assertSame([], $payload['history']);
    }

    public function test_history_uses_actor_neutral_labels_when_a_manager_is_not_the_owner(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $viewer = User::factory()->create();
        $action = HsCorrectiveAction::factory()->create([
            'assigned_to_user_id' => $owner->id,
            'status' => HsCorrectiveAction::STATUS_OPEN,
        ]);

        $this->actingAs($manager);
        $this->service->start($action);
        $this->service->complete($action->fresh(), [
            'completion_notes' => 'Manager submitted the completion evidence.',
            'completed_by_user_id' => $manager->id,
        ]);
        $action->refresh()->load([
            'assignedTo:id,name',
            'completedBy:id,name',
            'verifiedBy:id,name',
            'hsInvestigation:id,recommendations',
            'sourceControlRoomTask:id,title',
            'attachments.uploader:id,name',
            'auditLogs.user:id,name',
        ]);

        $payload = app(HsCorrectiveActionPresenter::class)->present(
            $action,
            $viewer,
            true,
            true,
        );
        $labels = collect($payload['history'])->pluck('label');

        $this->assertTrue($labels->contains('Action started'));
        $this->assertTrue($labels->contains('Evidence submitted'));
        $this->assertFalse($labels->contains('Owner started action'));
        $this->assertFalse($labels->contains('Owner submitted evidence'));
    }

    private function staffAtSite(
        Site $site,
        ?string $roleName = null,
    ): User {
        $user = User::factory()->create([
            'organization_id' => 1,
            'approved_at' => now(),
            'role' => $roleName ?? 'support_worker',
        ]);
        if ($roleName && $role = Role::query()->where('name', $roleName)->first()) {
            $user->roles()->attach($role);
        }
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }
}
