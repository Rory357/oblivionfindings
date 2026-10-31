<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\HsClosureException;
use App\Models\HsClosureExceptionDecision;
use App\Models\HsCorrectiveAction;
use App\Models\HsCorrectiveActionOwnerChange;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsCorrectiveActionOwnershipService;
use App\Services\HealthSafety\HsCorrectiveActionService;
use App\Services\HealthSafety\HsEventClosureService;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HsCorrectiveActionOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_handover_updates_the_existing_action_and_queue_with_a_full_receipt_and_two_private_notices(): void
    {
        [$site, $actor, $owner, $action] = $this->scenario();
        $eventBefore = $action->hsEvent->getAttributes();
        $before = $action->getAttributes();
        $payload = $this->payload($owner);
        $payload['reason'] = trim(str_repeat('Agreed continuity of the repair and evidence follow-up. ', 20));
        $url = $this->url($action);
        $this->actingAs($actor)->from($url.'?action=owner')->post($url.'/owner', $payload)
            ->assertRedirect($url.'?action=owner')->assertSessionHasNoErrors();
        $current = $action->fresh();
        $this->assertSame($owner->id, $current->assigned_to_user_id);
        $this->assertSame($actor->id, $current->assigned_by_user_id);
        $this->assertSame(1, $current->owner_revision);
        foreach (['status', 'due_date', 'completed_at', 'completion_notes', 'created_by', 'hs_event_id', 'source_control_room_task_id'] as $key) {
            $this->assertSame($before[$key] ?? null, $current->getAttributes()[$key] ?? null, $key);
        }
        $this->assertSame($eventBefore, $action->hsEvent->fresh()->getAttributes());
        $receipt = HsCorrectiveActionOwnerChange::firstOrFail();
        $this->assertSame($actor->name, $receipt->previous_owner_name);
        $this->assertSame($payload['reason'], $receipt->reason);
        $this->assertSame(2, DB::table('notifications')->count());
        $notices = DB::table('notifications')->pluck('data')->implode(' ');
        $this->assertStringNotContainsString($action->title, $notices);
        $this->assertStringNotContainsString($action->description, $notices);
        $this->assertStringNotContainsString($payload['reason'], $notices);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('workspace.action.owner.id', $owner->id)->where('workspace.ownership.revision', 1)
            ->where('workspace.ownership.history.0.reason', $payload['reason']));
        $this->get('/tasks/detail?source=corrective_action&id='.$action->id)->assertOk()
            ->assertJsonPath('item.assignee.id', $owner->id)->assertJsonPath('canAssign', false);
        $this->actingAs($owner)->get($url)->assertOk();
        $this->assertSame($site->id, $action->hsEvent->site_id);
    }

    public function test_retries_are_exact_and_stale_or_reused_requests_cannot_overwrite_a_handover(): void
    {
        [, $actor, $owner, $action] = $this->scenario();
        $payload = $this->payload($owner);
        $url = $this->url($action).'/owner';
        $this->actingAs($actor)->post($url, $payload)->assertSessionHasNoErrors();
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, HsCorrectiveActionOwnerChange::count());
        $this->assertSame(2, DB::table('notifications')->count());
        $this->postJson($url, [...$payload, 'reason' => 'Changed request.'])->assertUnprocessable()->assertJsonValidationErrors('request_uuid');
        $this->postJson($url, $this->payload($actor))->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        $this->assertSame($owner->id, $action->fresh()->assigned_to_user_id);
    }

    public function test_picker_and_commit_reject_workers_foreign_staff_expired_employment_external_accounts_and_missing_read_access(): void
    {
        [$site, $actor, $owner, $action] = $this->scenario();
        $worker = $this->staff($site, []);
        $foreign = $this->staff(Site::factory()->create());
        $inactive = $this->staff($site);
        $inactive->hrEmployeeProfile->update(['is_active' => false]);
        $external = $this->staff($site);
        $external->forceFill(['external_clinical_account' => true])->save();
        $noRead = $this->staff($site, ['hazards.manage']);
        $unapproved = $this->staff($site);
        $unapproved->update(['approved_at' => null]);
        $url = $this->url($action);
        $this->actingAs($actor)->getJson($url.'/owner-candidates')->assertOk()->assertExactJson(['options' => [['id' => $owner->id, 'name' => $owner->name]]]);
        foreach ([$worker, $foreign, $inactive, $external, $noRead, $unapproved] as $candidate) {
            $error = $this->postJson($url.'/owner', $this->payload($candidate))->assertUnprocessable()->assertJsonValidationErrors('owner_user_id')->json('errors.owner_user_id');
        }
        $unknown = $this->postJson($url.'/owner', [...$this->payload($owner), 'owner_user_id' => 999999999])->assertUnprocessable()->json('errors.owner_user_id');
        $this->assertSame($error, $unknown);
        $this->assertSame(0, HsCorrectiveActionOwnerChange::count());
        $this->postJson($url.'/owner', [...$this->payload($owner), 'reason' => '   '])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($url.'/owner', [...$this->payload($owner), 'owner_user_id' => null])->assertUnprocessable()->assertJsonValidationErrors('owner_user_id');
    }

    public function test_loaded_actor_and_candidate_grants_do_not_survive_revocation(): void
    {
        [, $actor, $owner, $action] = $this->scenario();
        $actor->load(['roles.permissions', 'permissionOverrides']);
        $permission = Permission::where('key', 'hazards.manage')->firstOrFail();
        DB::table('permission_user')->where('user_id', $actor->id)->where('permission_id', $permission->id)->update(['allowed' => false]);
        $this->actingAs($actor)->postJson($this->url($action).'/owner', $this->payload($owner))->assertForbidden();
        DB::table('permission_user')->where('user_id', $actor->id)->where('permission_id', $permission->id)->update(['allowed' => true]);
        $owner->load(['roles.permissions', 'permissionOverrides']);
        DB::table('permission_user')->where('user_id', $owner->id)->where('permission_id', $permission->id)->update(['allowed' => false]);
        $this->actingAs($actor->fresh())->postJson($this->url($action).'/owner', $this->payload($owner))->assertUnprocessable()->assertJsonValidationErrors('owner_user_id');
        $this->assertSame(0, HsCorrectiveActionOwnerChange::count());
    }

    public function test_cross_site_reader_cannot_assign_even_with_all_sites_visibility_and_lost_owner_is_flagged(): void
    {
        [$site, $actor, $owner, $action] = $this->scenario();
        $outsider = $this->staff(Site::factory()->create(), ['hazards.view', 'hazards.manage', 'healthSafety.viewAllSites']);
        $url = $this->url($action);
        $this->actingAs($outsider)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('workspace.ownership.can_assign', false)->has('workspace.ownership.history', 0));
        $this->getJson($url.'/owner-candidates')->assertForbidden();
        $this->postJson($url.'/owner', $this->payload($owner))->assertForbidden();
        $actor->hrEmployeeProfile->update(['primary_site_id' => $outsider->hrEmployeeProfile->primary_site_id]);
        $this->actingAs($owner)->get($url)->assertInertia(fn (Assert $page) => $page->where('workspace.ownership.state', 'replacement_needed'));
        $this->post($url.'/owner', $this->payload($owner))->assertSessionHasNoErrors();
        $this->assertSame($owner->id, $action->fresh()->assigned_to_user_id);
        $this->assertSame($site->id, $action->hsEvent->site_id);
    }

    public function test_reassignment_preserves_closed_review_but_closed_action_only_replays_its_own_receipt(): void
    {
        [$site, $actor, $owner, $action] = $this->scenario();
        foreach (['healthSafety.events.close', 'healthSafety.closureExceptions.request'] as $key) {
            $actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => true]]);
        }
        $owner->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'healthSafety.closureExceptions.approve')->firstOrFail()->id => ['allowed' => true]]);
        $event = HsEvent::factory()->worksafeNotNotifiable($actor)->create(['site_id' => $site->id,
            'owner_user_id' => $actor->id, 'created_by' => $actor->id, 'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED, 'investigation_required' => false]);
        $action->update(['hs_event_id' => $event->id]);
        $closure = app(HsEventClosureService::class);
        $exception = $closure->requestException($event, $actor->fresh(), [
            'category' => HsClosureException::CATEGORY_CORRECTIVE_ACTION_MONITORING,
            'reason' => 'The outstanding action remains independently owned and monitored during the agreed staff cover.',
            'evidence_reference' => 'OWNER-HANDOVER-REVIEW', 'scope' => ['corrective_actions'],
            'review_at' => now()->addDays(2)->toIso8601String(), 'expires_at' => now()->addDays(5)->toIso8601String(),
        ]);
        $closure->decideException($event, $exception, $owner->fresh(), HsClosureExceptionDecision::DECISION_APPROVED, 'The remaining monitored responsibility is independently reviewed.');
        $closure->closeEvent($event, 'Review closed under the approved monitoring exception.', $actor->fresh(), $exception->id);
        $before = $event->fresh()->getAttributes();
        $payload = $this->payload($owner);
        $url = $this->url($action).'/owner';
        $this->actingAs($actor)->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame($before, $event->fresh()->getAttributes());
        $action->update(['status' => HsCorrectiveAction::STATUS_CLOSED]);
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->postJson($url, $this->payload($actor, 1))->assertUnprocessable()->assertJsonValidationErrors('owner_user_id');
        $this->assertSame(1, HsCorrectiveActionOwnerChange::count());
    }

    public function test_former_owner_cannot_verify_and_previous_activity_keeps_its_actual_owner(): void
    {
        [$site, $actor, $owner, $action] = $this->scenario();
        $independent = $this->staff($site);
        $action->update(['created_by' => $independent->id]);
        $action->hsEvent->update(['created_by' => $independent->id]);
        $this->actingAs($actor);
        app(HsCorrectiveActionService::class)->complete($action, ['completed_by_user_id' => $owner->id, 'completion_notes' => 'Repair evidence retained.']);
        $url = $this->url($action);
        $this->post($url.'/owner', $this->payload($owner))->assertSessionHasNoErrors();
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('workspace.can.verify', false)
            ->where('workspace.action.history.0.label', 'Owner submitted evidence'));
        $this->from($url)->post('/health-safety/events/'.$action->hs_event_id.'/corrective-actions/'.$action->id.'/verify', ['evidence_reviewed' => true, 'effective' => true])->assertSessionHas('error');
        $this->assertSame(HsCorrectiveAction::STATUS_COMPLETED, $action->fresh()->status);
    }

    public function test_queue_and_start_cannot_bypass_the_recorded_handover(): void
    {
        [, $actor, $owner, $action] = $this->scenario();
        $action->update(['status' => HsCorrectiveAction::STATUS_OPEN]);
        $this->actingAs($actor)->post('/tasks/corrective_action/'.$action->id.'/assign', ['assignee_id' => $owner->id])->assertStatus(400);
        $this->post('/health-safety/events/'.$action->hs_event_id.'/corrective-actions/'.$action->id.'/start', ['assigned_to_user_id' => $owner->id])->assertSessionHas('error');
        $this->assertSame($actor->id, $action->fresh()->assigned_to_user_id);
        $this->assertSame(HsCorrectiveAction::STATUS_OPEN, $action->fresh()->status);
        $this->assertSame(0, HsCorrectiveActionOwnerChange::count());
    }

    public function test_notification_storage_failure_rolls_back_the_whole_handover(): void
    {
        [, $actor, $owner, $action] = $this->scenario();
        $this->mock(Dispatcher::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('Synthetic notification storage failure'));
        try {
            app(HsCorrectiveActionOwnershipService::class)->assign($actor, $action, $this->payload($owner));
            $this->fail('The transaction must surface the storage failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic notification storage failure', $error->getMessage());
        }
        $this->assertSame($actor->id, $action->fresh()->assigned_to_user_id);
        $this->assertSame(0, HsCorrectiveActionOwnerChange::count());
        $this->assertSame(0, AuditLog::where('action', 'health_safety.corrective_action.owner_changed')->count());
    }

    public function test_history_cannot_be_edited_or_removed_by_schema_rollback(): void
    {
        [, $actor, $owner, $action] = $this->scenario();
        $receipt = app(HsCorrectiveActionOwnershipService::class)->assign($actor, $action, $this->payload($owner));
        try {
            $receipt->update(['reason' => 'Replace evidence']);
            $this->fail('A handover receipt must be immutable.');
        } catch (\LogicException) {
            $this->assertNotSame('Replace evidence', $receipt->fresh()->reason);
        }
        $migration = require database_path('migrations/2026_10_08_090000_add_corrective_action_owner_history.php');
        try {
            $migration->down();
            $this->fail('Rollback must retain ownership evidence.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('ownership history exists', $error->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('hs_corrective_actions', 'owner_revision'));
        $this->assertSame(1, HsCorrectiveActionOwnerChange::count());
    }

    private function scenario(): array
    {
        $site = Site::factory()->create();
        $actor = $this->staff($site);
        $owner = $this->staff($site);
        $event = HsEvent::factory()->create(['site_id' => $site->id, 'status' => HsEvent::STATUS_CORRECTIVE_ACTION]);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'status' => HsCorrectiveAction::STATUS_IN_PROGRESS,
            'assigned_to_user_id' => $actor->id, 'title' => 'Private corrective action title', 'description' => 'Private source narrative']);

        return [$site, $actor, $owner, $action->fresh()];
    }

    private function staff(Site $site, array $permissions = ['hazards.view', 'hazards.manage']): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => []]);
        foreach ($permissions as $key) {
            $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => true]]);
        }

        return $user->fresh();
    }

    private function payload(User $owner, int $revision = 0): array
    {
        return ['owner_user_id' => $owner->id, 'expected_revision' => $revision, 'request_uuid' => (string) Str::uuid(), 'reason' => 'Agreed cover for the remaining repair and evidence.'];
    }

    private function url(HsCorrectiveAction $action): string
    {
        return '/health-safety/corrective-actions/'.$action->id;
    }
}
