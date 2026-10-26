<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\HsClosureException;
use App\Models\HsClosureExceptionDecision;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsCorrectiveActionWorkspace;
use App\Services\HealthSafety\HsEventClosureService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HsCorrectiveActionWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_permanent_action_uses_exact_record_and_separate_source_access(): void
    {
        $site = Site::factory()->create();
        $manager = $this->staff($site, ['hazards.view', 'hazards.manage']);
        $alert = ControlRoomAlert::factory()->create(['site_id' => $site->id]);
        $event = HsEvent::factory()->create(['site_id' => $site->id, 'control_room_alert_id' => $alert->id]);
        $task = AlertTask::create(['alert_id' => $alert->id, 'title' => 'Private operational source title', 'status' => 'open', 'priority' => 'medium', 'created_by_user_id' => $manager->id]);
        $action = HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id, 'source_control_room_task_id' => $task->id,
            'status' => 'in_progress', 'assigned_to_user_id' => $manager->id,
            'description' => 'Private copied operational description',
        ]);
        $this->actingAs($manager)->get($this->url($action))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('health-safety/corrective-actions/show')
            ->where('workspace.action.id', $action->id)
            ->where('workspace.action.owner.id', $manager->id)
            ->where('workspace.action.source.type', 'restricted')
            ->where('workspace.action.source_task', null)
            ->where('workspace.action.description', null)
            ->where('workspace.source_task_url', null)
            ->where('workspace.can.complete', true)
            ->where('workspace.review.url', "/health-safety/events/{$event->id}?section=actions")
            ->missing('detail'));

        $this->grant($manager, 'controlRoom.alerts.view');
        $this->actingAs($manager->fresh())->get($this->url($action))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('workspace.action.source_task.title', $task->title)
            ->where('workspace.action.description', $action->description)
            ->where('workspace.source_task_url', "/control-room/alerts/{$alert->id}?section=tasks&return_to=".rawurlencode($this->url($action))));
        $this->get("/control-room/alerts/{$alert->id}?section=tasks&return_to=".rawurlencode($this->url($action)))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('return_to', $this->url($action)));
    }

    public function test_read_only_and_cross_site_readers_do_not_gain_evidence_or_write_authority(): void
    {
        $site = Site::factory()->create();
        $reader = $this->staff($site, ['hazards.view']);
        $event = HsEvent::factory()->create(['site_id' => $site->id]);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'status' => 'in_progress', 'completion_notes' => 'Private submitted evidence']);
        $this->actingAs($reader)->get($this->url($action))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('workspace.action.evidence.load_state', 'unavailable')
            ->where('workspace.action.evidence.completion_notes', null)
            ->where('workspace.can', ['start' => false, 'complete' => false, 'verify' => false, 'return' => false, 'close' => false]));
        $other = $this->staff(Site::factory()->create(), ['hazards.view', 'hazards.manage']);
        $this->actingAs($other)->get($this->url($action))->assertNotFound();
        $this->grant($other, 'healthSafety.viewAllSites');
        $this->actingAs($other->fresh())->get($this->url($action))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('workspace.can.complete', false)
            ->where('workspace.action.evidence.can_upload', false)
            ->where('workspace.action.evidence.completion_notes', null));
        $worker = $this->staff($site);
        $this->actingAs($worker)->get($this->url($action))->assertForbidden();
    }

    public function test_mismatched_source_identity_withholds_the_link_without_repairing_history(): void
    {
        $site = Site::factory()->create();
        $manager = $this->staff($site, ['hazards.view', 'hazards.manage', 'controlRoom.alerts.view']);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->create(['site_id' => $site->id, 'client_id' => null]);
        $event = HsEvent::factory()->create(['site_id' => $site->id, 'client_id' => $client->id, 'control_room_alert_id' => $alert->id]);
        $task = AlertTask::create(['alert_id' => $alert->id, 'title' => 'Source with incompatible identity', 'status' => 'open', 'priority' => 'medium', 'created_by_user_id' => $manager->id]);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'source_control_room_task_id' => $task->id, 'description' => 'Copied incompatible source description']);
        $this->actingAs($manager)->get($this->url($action))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('workspace.action.source.type', 'unavailable')
            ->where('workspace.action.source_task', null)
            ->where('workspace.source_task_url', null)
            ->where('workspace.action.description', null));
        $this->assertSame($task->id, $action->fresh()->source_control_room_task_id);
        $this->assertNull($alert->fresh()->client_id);
        $this->assertSame($client->id, $event->fresh()->client_id);
    }

    public function test_corrupt_investigation_link_does_not_disclose_another_reviews_findings(): void
    {
        $site = Site::factory()->create();
        $manager = $this->staff($site, ['hazards.view', 'hazards.manage']);
        $event = HsEvent::factory()->create(['site_id' => $site->id]);
        $foreign = HsInvestigation::factory()->create(['recommendations' => [['description' => 'Private foreign finding']]]);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'hs_investigation_id' => $foreign->id, 'recommendation_index' => 0]);
        $this->actingAs($manager)->get($this->url($action))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('workspace.action.recommendation', null)
            ->where('workspace.action.hs_investigation_id', null)
            ->where('workspace.action.recommendation_index', null));
        $this->assertSame($foreign->id, $action->fresh()->hs_investigation_id);
    }

    public function test_existing_commands_return_to_action_page_and_keep_verification_independent(): void
    {
        $site = Site::factory()->create();
        $owner = $this->staff($site, ['hazards.view', 'hazards.manage']);
        $verifier = $this->staff($site, ['hazards.view', 'hazards.manage']);
        $event = HsEvent::factory()->create(['site_id' => $site->id, 'status' => 'corrective_action', 'created_by' => $owner->id]);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'status' => 'open', 'assigned_to_user_id' => $owner->id, 'created_by' => $owner->id]);
        $url = $this->url($action);
        $base = "/health-safety/events/{$event->id}/corrective-actions/{$action->id}";
        $this->actingAs($owner)->from($url)->post("{$base}/start")->assertRedirect($url)->assertSessionHas('success');
        $this->from($url)->post("{$base}/complete", ['completion_notes' => 'Completed repair, with recorded evidence.'])->assertRedirect($url)->assertSessionHas('success');
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('workspace.can.verify', false)->where('workspace.action.status', 'completed'));
        $this->assertSame('corrective_action', $event->fresh()->status);
        $this->actingAs($verifier)->get($url)->assertInertia(fn (Assert $page) => $page->where('workspace.can.verify', true));
        $this->from($url)->post("{$base}/return", ['reason' => 'Add the inspection outcome.'])->assertRedirect($url)->assertSessionHas('success');
        $this->actingAs($owner)->get($url)->assertInertia(fn (Assert $page) => $page->where('workspace.can.complete', true)->where('workspace.action.rework.latest_reason', 'Add the inspection outcome.'));
        $this->from($url)->post("{$base}/complete", ['completion_notes' => 'Repair and inspection completed.'])->assertSessionHas('success');
        $this->actingAs($verifier)->from($url)->post("{$base}/verify", ['evidence_reviewed' => true, 'effective' => true, 'verification_notes' => 'Control is effective.'])->assertRedirect($url)->assertSessionHas('success');
        $this->from($url)->post("{$base}/close")->assertRedirect($url)->assertSessionHas('success');
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('workspace.action.status', 'closed')->where('workspace.action.evidence.can_upload', false));
        $this->assertNotSame('closed', $event->fresh()->status);
    }

    private function url(HsCorrectiveAction $action): string
    {
        return "/health-safety/corrective-actions/{$action->id}";
    }

    public function test_approved_review_closure_does_not_strand_the_independent_action(): void
    {
        $site = Site::factory()->create();
        $owner = $this->staff($site, ['hazards.view', 'hazards.manage', 'healthSafety.events.close', 'healthSafety.closureExceptions.request']);
        $verifier = $this->staff($site, ['hazards.view', 'hazards.manage', 'healthSafety.closureExceptions.approve']);
        $event = HsEvent::factory()->worksafeNotNotifiable($owner)->create([
            'site_id' => $site->id, 'owner_user_id' => $owner->id, 'created_by' => $owner->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED, 'investigation_required' => false,
        ]);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'status' => 'open', 'assigned_to_user_id' => $owner->id, 'created_by' => $owner->id]);
        $closure = app(HsEventClosureService::class);
        $exception = $closure->requestException($event, $owner, [
            'category' => HsClosureException::CATEGORY_CORRECTIVE_ACTION_MONITORING,
            'reason' => 'The remaining independent action is being monitored under its recorded owner and due date.',
            'evidence_reference' => 'HS-ACTION-CONTINUATION', 'scope' => ['corrective_actions'],
            'review_at' => now()->addDays(2)->toIso8601String(), 'expires_at' => now()->addDays(5)->toIso8601String(),
        ]);
        $closure->decideException($event, $exception, $verifier, HsClosureExceptionDecision::DECISION_APPROVED, 'Independently reviewed the remaining action and its monitoring arrangements.');
        $closure->closeEvent($event, 'Review closed with approved monitored action still outstanding.', $owner, $exception->id);
        $receipt = AuditLog::query()->where('auditable_type', $event->getMorphClass())->where('auditable_id', $event->id)->where('action', 'healthSafety.event.closedWithException')->firstOrFail();
        $beforeEvent = $event->fresh()->getAttributes();
        $beforeReceipt = $receipt->getAttributes();
        $beforeException = $exception->fresh()->getAttributes();
        $url = $this->url($action);
        $base = "/health-safety/events/{$event->id}/corrective-actions/{$action->id}";

        $this->actingAs($owner)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('workspace.review.status', 'closed')->where('workspace.can.start', true));
        $this->from($url)->post("{$base}/start")->assertRedirect($url)->assertSessionHas('success');
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('workspace.can.complete', true));
        $this->from($url)->post("{$base}/complete", ['completion_notes' => 'The monitored repair is now complete.'])->assertSessionHas('success');
        $this->actingAs($verifier)->get($url)->assertInertia(fn (Assert $page) => $page->where('workspace.can.verify', true)->where('workspace.can.return', true));
        $this->from($url)->post("{$base}/verify", ['evidence_reviewed' => true, 'effective' => true])->assertSessionHas('success');
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('workspace.can.close', true));
        $this->from($url)->post("{$base}/close")->assertRedirect($url)->assertSessionHas('success');
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('workspace.action.status', 'closed')->where('workspace.can', ['start' => false, 'complete' => false, 'verify' => false, 'return' => false, 'close' => false]));
        $this->assertSame($beforeEvent, $event->fresh()->getAttributes());
        $this->assertSame($beforeReceipt, $receipt->fresh()->getAttributes());
        $this->assertSame($beforeException, $exception->fresh()->getAttributes());
    }

    public function test_direct_reader_rechecks_loaded_module_authority_before_source_discovery(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->staff($site, ['hazards.view']);
        $event = HsEvent::factory()->createQuietly(['site_id' => $site->id]);
        $action = HsCorrectiveAction::factory()->createQuietly(['hs_event_id' => $event->id]);
        $service = app(HsCorrectiveActionWorkspace::class);
        $this->assertSame($action->id, $service->forViewer($action, $viewer)['action']['id']);
        $viewer->load('roles.permissions', 'permissionOverrides');
        $viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hazards.view')->sole()->id => ['allowed' => false]]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            try {
                $service->forViewer($action, $viewer);
                $this->fail('Expected fresh module denial.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertDoesNotMatchRegularExpression('/\bfrom\s+[`"]?(?:hs_events|first_aid_records|restraint_events|workplace_injuries)[`"]?\b/i', implode(' ', array_column($queries, 'query')));
        $this->grant($viewer, 'hazards.view');
        $this->assertSame($action->id, $service->forViewer($action, $viewer)['action']['id']);
    }

    public function test_management_revocation_keeps_read_but_removes_unassigned_private_evidence_and_commands(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->staff($site, ['hazards.view', 'hazards.manage']);
        $event = HsEvent::factory()->createQuietly(['site_id' => $site->id]);
        $owner = $this->staff($site);
        $action = HsCorrectiveAction::factory()->createQuietly(['hs_event_id' => $event->id, 'assigned_to_user_id' => $owner->id,
            'status' => HsCorrectiveAction::STATUS_IN_PROGRESS, 'completion_notes' => 'Retained participant-only evidence']);
        $service = app(HsCorrectiveActionWorkspace::class);
        $this->assertSame('Retained participant-only evidence', $service->forViewer($action, $viewer)['action']['evidence']['completion_notes']);
        $viewer->load('roles.permissions', 'permissionOverrides');
        $viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'hazards.manage')->sole()->id => ['allowed' => false]]);
        $read = $service->forViewer($action, $viewer);
        $this->assertSame($action->id, $read['action']['id']);
        $this->assertNull($read['action']['evidence']['completion_notes']);
        $this->assertSame('unavailable', $read['action']['evidence']['load_state']);
        $this->assertFalse($read['can']['complete']);
        $this->assertFalse($read['action']['evidence']['can_upload']);
        $this->grant($viewer, 'hazards.manage');
        $this->assertTrue($service->forViewer($action, $viewer)['can']['complete']);
    }

    public function test_loaded_action_is_concealed_after_its_exact_parent_site_changes(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->staff($site, ['hazards.view', 'hazards.manage']);
        $event = HsEvent::factory()->createQuietly(['site_id' => $site->id]);
        $action = HsCorrectiveAction::factory()->createQuietly(['hs_event_id' => $event->id]);
        $service = app(HsCorrectiveActionWorkspace::class);
        $this->assertSame($event->id, $service->forViewer($action, $viewer)['review']['id']);
        $action->load('hsEvent');
        $destination = Site::factory()->create();
        $event->updateQuietly(['site_id' => $destination->id]);
        try {
            $service->forViewer($action, $viewer);
            $this->fail('Expected current parent Site concealment.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $error) {
            $this->assertSame(HsCorrectiveAction::class, $error->getModel());
        }
        $viewer->hrEmployeeProfile()->update(['secondary_site_ids' => [$destination->id]]);
        $this->assertSame($event->id, $service->forViewer($action, $viewer)['review']['id']);
    }

    private function staff(Site $site, array $permissions = []): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => []]);
        foreach ($permissions as $permission) {
            $this->grant($user, $permission);
        }

        return $user->fresh();
    }

    private function grant(User $user, string $key): void
    {
        $permission = Permission::where('key', $key)->firstOrFail();
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
}
