<?php

namespace Tests\Feature\HealthSafety;

use App\Models\RestraintEvent;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\Tasks\Providers\RestraintReviewProvider;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use Illuminate\Support\Facades\DB;

class RestraintReviewTaskContextTest extends RestraintEventCommandTestCase
{
    public function test_review_only_task_opens_permanent_review_workspace_with_bounded_tasks_origin_and_no_narrative(): void
    {
        $source = $this->event();
        $viewer = $this->staff(['restraints.review', 'clients.viewAny']);
        $provider = new RestraintReviewProvider;
        $items = $provider->authorizedTasks($viewer, ['return_to' => '/tasks?sources[]=restraint_review']);
        $this->assertCount(1, $items);
        $this->assertSame($source->id, $items[0]->numericId());
        $this->assertStringStartsWith('/health-safety/restraints/events/'.$source->id.'/review?', $items[0]->link);
        $this->assertStringContainsString('return_to=%2Ftasks', $items[0]->link);
        $this->assertNull($items[0]->description);
        $this->assertStringNotContainsString('Factual trigger', json_encode($items[0]->toArray()));
        $this->assertStringNotContainsString('Sensitive factual source', json_encode($items[0]->toArray()));
        $this->assertSame([], $provider->authorizedTasks($this->staff(['restraints.view', 'clients.viewAny'])));
        $this->assertSame([], $provider->authorizedTasks($this->staff(['restraints.create', 'clients.viewAny'])));
    }

    public function test_current_review_revision_and_named_ownership_drive_feed_and_navigation_parity(): void
    {
        $source = $this->event();
        $viewer = $this->staff(['restraints.review', 'clients.viewAny']);
        $this->service()->assignReview($source, $this->actor, $this->command($source, ['review_owner_id' => $viewer->id, 'review_due_at' => now()->subHour()->toIso8601String(), 'reason' => 'Named actual ownership']));
        $provider = new RestraintReviewProvider;
        $feed = $provider->authorizedTasks($viewer);
        $nav = HealthSafetyReadContext::within($viewer, RestraintAccessService::PERMISSIONS, fn ($context) => $provider->authorizedNavigationTasksInContext($viewer, $context));
        $this->assertSame($feed[0]->id, $nav[0]->id);
        $this->assertSame($feed[0]->bucket, $nav[0]->bucket);
        $this->assertSame($feed[0]->dueAt, $nav[0]->dueAt);
        $this->assertSame($viewer->id, $nav[0]->assignee['id']);
        $this->assertTrue($feed[0]->isOverdue());
        $this->assertTrue($nav[0]->isOverdue());
        foreach (['description', 'client', 'site', 'link', 'ref', 'createdAt'] as $field) {
            $this->assertNull($nav[0]->{$field});
        }
        $this->service()->review($source, $this->actor, $this->command($source, ['reviewed_by' => $this->actor->id, 'reviewed_at' => now()->subMinute()->toIso8601String(), 'review_notes' => 'Actual completed source review']));
        $this->assertSame([], $provider->authorizedTasks($viewer));
        $done = $provider->authorizedTasks($viewer, ['include_done' => true]);
        $this->assertSame(TaskItem::BUCKET_DONE, $done[0]->bucket);
        $historicalAt = $source->fresh()->reviewed_at;
        $this->service()->correct($source, $this->actor, $this->command($source, ['person_response' => 'Later actual fact', 'reason' => 'Later factual evidence']));
        $this->assertSame($historicalAt->toIso8601String(), $source->fresh()->reviewed_at->toIso8601String());
        $this->assertSame(TaskItem::BUCKET_OPEN, $provider->authorizedTasks($viewer)[0]->bucket);
    }

    public function test_reused_aggregator_rechecks_current_client_permission_hr_and_site_without_authority_cache(): void
    {
        $this->event();
        $viewer = $this->staff(['restraints.review', 'clients.viewAssigned']);
        $this->client->supportWorkers()->attach($viewer->id);
        $aggregate = new TaskAggregator([new RestraintReviewProvider]);
        $this->assertCount(1, $aggregate->itemsFor($viewer));
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $this->client->supportWorkers()->detach($viewer->id);
        $this->assertSame([], $aggregate->itemsFor($viewer));
        $this->client->supportWorkers()->attach($viewer->id);
        $viewer->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertSame([], $aggregate->itemsFor($viewer));
        $viewer->hrEmployeeProfile()->update(['is_active' => true]);
        $this->site->update(['is_active' => false]);
        $this->assertSame([], $aggregate->itemsFor($viewer));
        $this->site->update(['is_active' => true]);
        $this->revoke($viewer, 'restraints.review');
        $this->assertSame([], $aggregate->itemsFor($viewer));
        $this->assertFalse($aggregate->navigationBadgeFor($viewer)['view']);
    }

    public function test_exact_find_and_archived_source_denial_keep_inactive_owner_identity_out_of_task_rows(): void
    {
        $source = $this->event();
        $owner = $this->staff(['restraints.review', 'clients.viewAny']);
        $this->service()->assignReview($source, $this->actor, $this->command($source, ['review_owner_id' => $owner->id, 'reason' => 'Current owner']));
        $owner->hrEmployeeProfile()->update(['is_active' => false]);
        $aggregate = new TaskAggregator([new RestraintReviewProvider]);
        $item = $aggregate->findItemFor($this->actor, 'restraint_review', $source->id);
        $this->assertNotNull($item);
        $this->assertNull($item->assignee);
        $this->assertStringNotContainsString($owner->name, json_encode($item->toArray()));
        $source->delete();
        $this->assertNull($aggregate->findItemFor($this->actor, 'restraint_review', $source->id));
        $this->assertSame([], $aggregate->itemsFor($this->actor, ['include_done' => true]));
    }

    public function test_navigation_materializes_same_top300_and_never_selects_event_narrative_columns(): void
    {
        RestraintEvent::withoutEvents(fn () => RestraintEvent::factory()->count(301)->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'created_by' => $this->actor->id,
            'source_schema_version' => 1, 'recorded_client_id' => $this->client->id, 'recorded_site_id' => $this->site->id, 'stay_id' => null, 'recorded_stay_id' => null,
            'source_revision' => 1, 'reviewed_source_revision' => null, 'review_owner_id' => $this->actor->id, 'review_due_at' => now()->subHour()]));
        $provider = new RestraintReviewProvider;
        $feed = $provider->authorizedTasks($this->actor);
        DB::enableQueryLog();
        $nav = HealthSafetyReadContext::within($this->actor, RestraintAccessService::PERMISSIONS, fn ($context) => $provider->authorizedNavigationTasksInContext($this->actor, $context));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(300, $feed);
        $this->assertCount(300, $nav);
        $this->assertSame(array_map(fn ($item) => $item->id, $feed), array_map(fn ($item) => $item->id, $nav));
        $sourceQueries = array_filter($queries, fn ($query) => str_contains($query['query'], 'from `restraint_events`'));
        $this->assertNotEmpty($sourceQueries);
        foreach ($sourceQueries as $query) {
            $this->assertStringNotContainsString('select *', strtolower($query['query']));
            $this->assertStringNotContainsString('trigger_description', $query['query']);
            $this->assertStringNotContainsString('restraint_description', $query['query']);
        }
        $this->assertSame(300, (new TaskAggregator([$provider]))->navigationBadgeFor($this->actor)['badge']);
    }

    public function test_foreign_actor_or_unprepared_context_is_rejected_and_scalar_navigation_cannot_escape_as_authority(): void
    {
        $this->event();
        $other = $this->staff(['restraints.review', 'clients.viewAny']);
        $provider = new RestraintReviewProvider;
        $this->expectException(\LogicException::class);
        HealthSafetyReadContext::within($this->actor, RestraintAccessService::PERMISSIONS,
            fn ($context) => $provider->authorizedNavigationTasksInContext($other, $context));
    }
}
