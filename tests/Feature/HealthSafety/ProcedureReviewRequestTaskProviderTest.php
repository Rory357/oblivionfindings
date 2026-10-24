<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\MyTasksController;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\ProcedureReviewRequest;
use App\Models\Role;
use App\Models\SafeWorkProcedure;
use App\Models\SafeWorkProcedureVersion;
use App\Models\Site;
use App\Models\User;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Providers\ProcedureReviewRequestProvider;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProcedureReviewRequestTaskProviderTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $executor;

    private ProcedureReviewRequestProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house']);
        $this->executor = $this->worker();
        $this->provider = new ProcedureReviewRequestProvider;
    }

    public function test_registered_provider_projects_canonical_states_without_persisted_tasks_or_invented_deadlines(): void
    {
        $requested = $this->review();
        $started = $this->review(['status' => 'in_review']);
        foreach (['resolved', 'declined', 'cancelled'] as $status) {
            $this->review(['status' => $status]);
        }
        $this->assertInstanceOf(ProcedureReviewRequestProvider::class, app(TaskAggregator::class)->providerFor('procedure_review_request'));
        $rows = $this->provider->authorizedTasks($this->executor);
        $this->assertSame([$requested->id, $started->id], array_map(fn ($item) => $item->numericId(), $rows));
        $this->assertSame([TaskItem::BUCKET_OPEN, TaskItem::BUCKET_IN_PROGRESS], array_column($rows, 'bucket'));
        $all = $this->provider->authorizedTasks($this->executor, ['include_done' => true]);
        $this->assertCount(5, $all);
        $this->assertSame(3, count(array_filter($all, fn ($item) => $item->bucket === TaskItem::BUCKET_DONE)));
        foreach ($all as $item) {
            $this->assertNull($item->dueAt);
            $this->assertFalse($item->isOverdue());
            $this->assertSame($this->executor->id, $item->assignee['id']);
        }
        $this->assertDatabaseCount('procedure_review_requests', 5);
        $this->assertFalse($this->provider instanceof AssignableTaskProvider);
        $this->assertFalse($this->provider instanceof HasModelClass);
    }

    public function test_exact_executor_and_source_tuple_are_enforced_on_list_and_direct_item(): void
    {
        $other = $this->worker();
        $mine = $this->review();
        $foreignExecutor = $this->review(['assigned_to_user_id' => $other->id, 'note' => 'Hidden executor note']);
        $foreignSite = Site::factory()->create();
        $badTuple = $this->review(['source_site_id' => $foreignSite->id, 'note' => 'Hidden source note']);
        $foreignEvent = HsEvent::factory()->create(['site_id' => $foreignSite->id]);
        $privateSource = $this->review(['hs_event_id' => $foreignEvent->id, 'source_site_id' => $foreignSite->id]);
        $aggregate = new TaskAggregator([$this->provider]);
        $this->assertSame([$mine->id], array_map(fn ($item) => $item->numericId(), $this->provider->authorizedTasks($this->executor)));
        foreach ([$foreignExecutor, $badTuple, $privateSource] as $hidden) {
            $this->assertNull($aggregate->findItemFor($this->executor, 'procedure_review_request', $hidden->id));
        }
        $this->assertStringNotContainsString('Hidden', json_encode($aggregate->itemsFor($this->executor)));
    }

    public function test_current_permissions_staff_and_site_removal_override_a_previously_loaded_executor(): void
    {
        $review = $this->review();
        $this->assertCount(1, $this->provider->authorizedTasks($this->executor));
        $permission = Permission::where('key', 'procedures.manage')->firstOrFail();
        $this->executor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $this->assertSame([], $this->provider->authorizedTasks($this->executor, ['id' => $review->id]));
        $this->executor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => true]);
        $this->executor->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertSame([], $this->provider->authorizedTasks($this->executor));
        $this->executor->hrEmployeeProfile()->update(['is_active' => true, 'primary_site_id' => Site::factory()->create()->id]);
        $this->assertSame([], $this->provider->authorizedTasks($this->executor));
    }

    public function test_portal_identity_with_lingering_staff_profile_and_grants_is_not_an_executor(): void
    {
        $this->review();
        $role = Role::where('name', 'client')->firstOrFail();
        $this->executor->roles()->syncWithoutDetaching([$role->id]);
        $this->assertSame([], $this->provider->authorizedTasks($this->executor));
    }

    public function test_requested_copy_must_be_approved_same_procedure_and_currently_in_audience(): void
    {
        $good = $this->review();
        $other = $this->review();
        $other->forceFill(['requested_version_id' => $good->requested_version_id])->saveQuietly();
        $unapproved = $this->review();
        $unapproved->requestedVersion->forceFill(['approved_by' => null, 'approved_at' => null])->saveQuietly();
        $outOfAudience = $this->review();
        $version = $outOfAudience->requestedVersion;
        $snapshot = $version->content_snapshot;
        $snapshot['applicable_roles'] = ['not_this_worker'];
        $version->forceFill(['content_snapshot' => $snapshot])->saveQuietly();
        $this->assertSame([$good->id], array_map(fn ($item) => $item->numericId(), $this->provider->authorizedTasks($this->executor)));
    }

    public function test_task_uses_requested_immutable_title_and_archived_outcome_remains_exact_historical_read(): void
    {
        $review = $this->review(['status' => 'resolved']);
        $record = $review->procedure;
        $successor = SafeWorkProcedureVersion::create(['safe_work_procedure_id' => $record->id, 'version_number' => $record->current_version + 1,
            'content_snapshot' => [...$review->requestedVersion->content_snapshot, 'title' => 'New approved copy'], 'document_manifest' => [],
            'changed_by' => $this->executor->id, 'provenance' => 'controlled', 'approved_by' => $this->executor->id, 'approved_at' => now()]);
        $record->forceFill(['published_version_id' => $successor->id, 'current_version' => $successor->version_number])->saveQuietly();
        $republished = $this->provider->authorizedTasks($this->executor, ['include_done' => true])[0];
        parse_str(parse_url($republished->link, PHP_URL_QUERY), $republishedQuery);
        $this->assertSame((string) $review->requested_version_id, $republishedQuery['version']);
        $this->assertSame('Review procedure: Approved source instructions', $republished->title);
        $record->forceFill(['title' => 'Secret unapproved successor', 'status' => 'archived', 'published_version_id' => null])->saveQuietly();
        $items = $this->provider->authorizedTasks($this->executor, ['include_done' => true, 'return_to' => '/tasks?assigned=me&done=1']);
        $this->assertCount(1, $items);
        $this->assertSame('Review procedure: Approved source instructions', $items[0]->title);
        parse_str(parse_url($items[0]->link, PHP_URL_QUERY), $query);
        $this->assertSame((string) $review->requested_version_id, $query['version']);
        $this->assertSame((string) $review->id, $query['request']);
        $this->assertSame('connections', $query['section']);
        $this->assertSame('/tasks?assigned=me&done=1', $query['return_to']);
        $this->actingAs($this->executor)->get($items[0]->link)->assertOk()->assertInertia(fn (Assert $page) => $page->where('detail.status', 'archived')->where('detail.viewed_revision.version_id', $review->requested_version_id));
        $this->assertSame([], $this->provider->authorizedTasks($this->executor));
    }

    public function test_live_task_requires_current_working_authority_and_joint_corrective_action_read(): void
    {
        $outsideDraft = $this->review();
        $outsideDraft->procedure->forceFill(['applicable_sites' => [$this->site->id, Site::factory()->create()->id]])->saveQuietly();
        $wrongAction = $this->review();
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => HsEvent::factory()->create(['site_id' => $this->site->id])->id]);
        $wrongAction->forceFill(['hs_corrective_action_id' => $action->id])->saveQuietly();
        $archived = $this->review();
        $archived->procedure->forceFill(['status' => 'archived', 'published_version_id' => null])->saveQuietly();
        $this->assertSame([], $this->provider->authorizedTasks($this->executor));
    }

    public function test_tasks_drawer_and_my_day_feed_link_to_the_same_request_with_bounded_returns(): void
    {
        $review = $this->review();
        $this->app->instance(TaskAggregator::class, new TaskAggregator([$this->provider]));
        $response = $this->actingAs($this->executor)->getJson('/tasks/detail?source=procedure_review_request&id='.$review->id.'&return_to='.urlencode('/tasks?assigned=me&q=procedure'));
        $response->assertOk()->assertJsonPath('canAssign', false)->assertJsonPath('canSplit', false)->assertJsonPath('timeline', []);
        $href = $response->json('item.link');
        parse_str(parse_url($href, PHP_URL_QUERY), $query);
        // Main's /tasks return sanitiser keeps the given parameter order; the parameters themselves must match.
        $this->assertSame('/tasks', parse_url($query['return_to'], PHP_URL_PATH));
        parse_str((string) parse_url($query['return_to'], PHP_URL_QUERY), $returnQuery);
        $this->assertEqualsCanonicalizing(['q' => 'procedure', 'assigned' => 'me'], $returnQuery);
        $this->assertSame((string) $review->id, $query['request']);
        $this->get($href)->assertOk();
        $this->post('/tasks/procedure_review_request/'.$review->id.'/assign', ['assignee_id' => $this->worker()->id])->assertStatus(400);
        $this->assertSame($this->executor->id, (int) $review->refresh()->assigned_to_user_id);
        $this->app->instance('request', Request::create('/my-day'));
        $this->app->instance(TaskAggregator::class, new TaskAggregator([$this->provider]));
        $controller = app(MyTasksController::class);
        $method = new \ReflectionMethod($controller, 'getMyAggregatedTasks');
        $feed = $method->invoke($controller, $this->executor);
        $this->assertSame(1, $feed['total']);
        parse_str(parse_url($feed['items'][0]['link'], PHP_URL_QUERY), $myDayQuery);
        $this->assertSame('/my-day', $myDayQuery['return_to']);
        $this->assertSame((string) $review->id, $myDayQuery['request']);
        $this->app->instance(TaskAggregator::class, new TaskAggregator([$this->provider]));
        $response = $this->actingAs($this->executor)->get('/my-day')->assertOk()->assertInertia(fn (Assert $page) => $page->component('my-day/index')->has('myTasks.items'));
        $actual = collect($response->viewData('page')['props']['myTasks']['items'])->firstWhere('id', 'procedure_review_request-'.$review->id);
        $this->assertNotNull($actual);
        parse_str(parse_url($actual['link'], PHP_URL_QUERY), $actualQuery);
        $this->assertSame('/my-day', $actualQuery['return_to']);
        $this->assertSame((string) $review->id, $actualQuery['request']);
        $unsafe = $this->provider->authorizedTasks($this->executor, ['return_to' => 'https://example.com/private'])[0];
        $this->assertStringNotContainsString('example.com', $unsafe->link);
        $other = $this->worker();
        $review->forceFill(['assigned_to_user_id' => $other->id])->saveQuietly();
        $this->assertNull(app(TaskAggregator::class)->findItemFor($this->executor, 'procedure_review_request', $review->id));
    }

    private function worker(): User
    {
        $user = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
        $ids = Permission::whereIn('key', ['procedures.view', 'procedures.manage', 'hazards.view'])->pluck('id');
        $user->permissionOverrides()->sync($ids->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]));
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);

        return $user;
    }

    private function review(array $attributes = []): ProcedureReviewRequest
    {
        $procedure = SafeWorkProcedure::factory()->approved()->create(['title' => 'Approved source instructions', 'applicable_roles' => [], 'applicable_sites' => [$this->site->id], 'created_by' => $this->executor->id, 'approved_by' => $this->executor->id])->refresh();
        $event = HsEvent::factory()->create(['site_id' => $this->site->id]);

        return ProcedureReviewRequest::create([...['safe_work_procedure_id' => $procedure->id, 'hs_event_id' => $event->id, 'source_site_id' => $event->site_id, 'requested_version_id' => $procedure->published_version_id, 'requested_by_user_id' => $this->executor->id, 'assigned_to_user_id' => $this->executor->id, 'note' => 'Review actual source instructions', 'idempotency_key' => (string) Str::uuid(), 'fingerprint' => hash('sha256', (string) $event->id), 'requested_at' => now(), 'status' => 'requested', 'lock_version' => 1], ...$attributes]);
    }
}
