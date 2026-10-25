<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\MyTasksController;
use App\Models\ControlRoomAlert;
use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillFinding;
use App\Models\EmergencyDrillParticipant;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\EmergencyDrillConnectionsService;
use App\Services\Sites\Calendar\Providers\DrillObligationProvider;
use App\Services\Sites\Calendar\SiteCalendarAggregator;
use App\Services\Sites\Profile\SiteProfileSafetyPresenter;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Providers\EmergencyDrillFindingProvider;
use App\Services\Tasks\Providers\EmergencyDrillProvider;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use App\Support\HealthSafety\EmergencyDrillReturn;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmergencyDrillWorkProjectionTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $worker;

    private EmergencyDrillProvider $drills;

    private EmergencyDrillFindingProvider $findings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house']);
        $this->worker = $this->staff();
        $this->drills = new EmergencyDrillProvider;
        $this->findings = new EmergencyDrillFindingProvider;
    }

    public function test_registered_source_projections_keep_canonical_states_and_do_not_allow_generic_assignment(): void
    {
        $scheduled = $this->drill();
        $running = $this->drill(['status' => 'in_progress']);
        $this->drill(['status' => 'completed']);
        $this->drill(['status' => 'cancelled']);
        $open = $this->finding($scheduled);
        $this->finding($running, ['status' => 'resolved']);
        $this->finding($scheduled, ['status' => 'accepted']);
        $this->assertInstanceOf(EmergencyDrillProvider::class, app(TaskAggregator::class)->providerFor('emergency_drill'));
        $this->assertInstanceOf(EmergencyDrillFindingProvider::class, app(TaskAggregator::class)->providerFor('emergency_drill_finding'));
        $rows = $this->drills->authorizedTasks($this->worker);
        $this->assertEqualsCanonicalizing([$scheduled->id, $running->id], array_map(fn ($row) => $row->numericId(), $rows));
        $this->assertEqualsCanonicalizing([TaskItem::BUCKET_OPEN, TaskItem::BUCKET_IN_PROGRESS], array_column($rows, 'bucket'));
        $this->assertCount(4, $this->drills->authorizedTasks($this->worker, ['include_done' => true]));
        $this->assertSame([$open->id], array_map(fn ($row) => $row->numericId(), $this->findings->authorizedTasks($this->worker)));
        $this->assertCount(3, $this->findings->authorizedTasks($this->worker, ['include_done' => true]));
        $this->assertFalse($this->drills instanceof AssignableTaskProvider);
        $this->assertFalse($this->findings instanceof AssignableTaskProvider);
        $this->assertDatabaseCount('emergency_drills', 4);
        $this->assertDatabaseCount('emergency_drill_findings', 3);
    }

    public function test_unrelated_owner_site_or_stale_staff_does_not_leak_on_list_or_direct_item(): void
    {
        $mine = $this->drill();
        $other = $this->staff();
        $unrelated = $this->drill(['conducted_by' => $other->id, 'title' => 'Private other coordinator']);
        $foreign = $this->drill(['site_id' => Site::factory()->create()->id, 'title' => 'Private other Site']);
        $finding = $this->finding($mine);
        $otherFinding = $this->finding($mine, ['assigned_to' => $other->id, 'description' => 'Private other finding']);
        $aggregate = new TaskAggregator([$this->drills, $this->findings]);
        foreach ([$unrelated, $foreign] as $record) {
            $this->assertNull($aggregate->findItemFor($this->worker, 'emergency_drill', $record->id));
        }
        $this->assertNull($aggregate->findItemFor($this->worker, 'emergency_drill_finding', $otherFinding->id));
        $this->assertNotNull($aggregate->findItemFor($this->worker, 'emergency_drill_finding', $finding->id));
        $this->assertStringNotContainsString('Private other', json_encode($aggregate->itemsFor($this->worker)));
        $this->worker->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertSame([], $this->drills->authorizedTasks($this->worker));
        $this->assertSame([], $this->findings->authorizedTasks($this->worker));
        $this->worker->hrEmployeeProfile()->update(['is_active' => true]);
        $permission = Permission::where('key', 'hazards.view')->firstOrFail();
        $this->worker->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $this->assertSame([], $this->findings->authorizedTasks($this->worker));
    }

    public function test_blocked_remedy_work_stays_visible_and_source_due_days_are_not_invented_or_shifted(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08T01:00:00+13:00')->utc());
        $drill = $this->drill(['scheduled_at' => now()->subMinute()]);
        $event = HsEvent::factory()->create(['site_id' => $this->site->id, 'source_type' => EmergencyDrill::class, 'source_id' => $drill->id, 'event_category' => HsEvent::CATEGORY_DRILL_FAILURE]);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'status' => 'open']);
        $finding = $this->finding($drill, ['due_date' => '2026-10-08', 'hs_corrective_action_id' => $action->id]);
        $undated = $this->finding($drill);
        $rows = $this->findings->authorizedTasks($this->worker);
        $today = collect($rows)->first(fn ($row) => $row->numericId() === $finding->id);
        $this->assertNotNull($today);
        $this->assertStringStartsWith('2026-10-08T23:59:59+13:00', $today->dueAt);
        $this->assertFalse($today->isOverdue());
        $this->assertNull(collect($rows)->first(fn ($row) => $row->numericId() === $undated->id)->dueAt);
        $this->assertTrue($this->drills->authorizedTasks($this->worker)[0]->isOverdue());
        $this->assertSame('open', $finding->refresh()->status);
        $this->assertSame('open', $action->refresh()->status);
    }

    public function test_tasks_drawer_and_my_day_open_the_exact_source_finding_with_bounded_return(): void
    {
        $drill = $this->drill();
        $finding = $this->finding($drill);
        $this->app->instance(TaskAggregator::class, new TaskAggregator([$this->drills, $this->findings]));
        $response = $this->actingAs($this->worker)->getJson('/tasks/detail?source=emergency_drill_finding&id='.$finding->id.'&return_to='.urlencode('/tasks?assigned=me&q=drill'));
        $response->assertOk()->assertJsonPath('canAssign', false)->assertJsonPath('canSplit', false);
        $href = $response->json('item.link');
        parse_str(parse_url($href, PHP_URL_QUERY), $query);
        $this->assertSame('findings', $query['section']);
        $this->assertSame((string) $finding->id, $query['finding']);
        // A supported origin comes back unchanged, in its own key order (SafeWorkProcedureReturn, e692a5d90).
        $this->assertSame('/tasks?assigned=me&q=drill', $query['return_to']);
        $this->get($href)->assertOk()->assertInertia(fn (Assert $page) => $page->where('detail.id', $drill->id));
        $this->post('/tasks/emergency_drill_finding/'.$finding->id.'/assign', ['assignee_id' => $this->staff()->id])->assertStatus(400);
        $this->assertSame($this->worker->id, $finding->refresh()->assigned_to);
        $this->app->instance('request', Request::create('/my-day'));
        $this->app->instance(TaskAggregator::class, new TaskAggregator([$this->drills, $this->findings]));
        $method = new \ReflectionMethod(app(MyTasksController::class), 'getMyAggregatedTasks');
        $feed = $method->invoke(app(MyTasksController::class), $this->worker);
        $row = collect($feed['items'])->firstWhere('id', 'emergency_drill_finding-'.$finding->id);
        $this->assertNotNull($row);
        parse_str(parse_url($row['link'], PHP_URL_QUERY), $myDay);
        $this->assertSame('/my-day', $myDay['return_to']);
        $this->assertSame((string) $finding->id, $myDay['finding']);
        $this->app->instance(TaskAggregator::class, new TaskAggregator([$this->drills, $this->findings]));
        $response = $this->actingAs($this->worker)->get('/my-day')->assertOk()->assertInertia(fn (Assert $page) => $page->component('my-day/index')->has('myTasks.items'));
        $actual = collect($response->viewData('page')['props']['myTasks']['items'])->firstWhere('id', 'emergency_drill_finding-'.$finding->id);
        $this->assertNotNull($actual);
        parse_str(parse_url($actual['link'], PHP_URL_QUERY), $actualQuery);
        $this->assertSame('/my-day', $actualQuery['return_to']);
        $this->assertSame((string) $finding->id, $actualQuery['finding']);
        $this->assertStringNotContainsString('example.com', $this->drills->authorizedTasks($this->worker, ['return_to' => 'https://example.com'])[0]->link);
    }

    public function test_calendar_requires_real_current_viewer_and_links_to_the_canonical_record(): void
    {
        $drill = $this->drill(['scheduled_at' => now()->addDay()]);
        $foreign = $this->drill(['site_id' => Site::factory()->create()->id]);
        $provider = new DrillObligationProvider;
        $start = now()->subDay();
        $end = now()->addMonth();
        $this->assertSame([], $provider->obligationsForViewer([$this->site->id], $start, $end, null));
        $this->assertSame([], $provider->obligationsForViewer([$this->site->id], $start, $end, $this->worker->id));
        $rows = $provider->obligationsForViewer([$this->site->id, $foreign->site_id], $start, $end, $this->worker);
        $this->assertCount(1, $rows);
        $this->assertSame(EmergencyDrillReturn::href($drill->id, '/sites/'.$this->site->id.'/calendar'), $rows[0]->link);
        $permission = Permission::where('key', 'hazards.view')->firstOrFail();
        $this->worker->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $this->assertSame([], $provider->obligationsForViewer([$this->site->id], $start, $end, $this->worker));
    }

    public function test_actual_global_calendar_json_serializes_authorized_drill_title_without_malformed_utf8(): void
    {
        $drill = $this->drill(['scheduled_at' => now()->addDay(), 'title' => 'Evacuation — Whānau assembly']);
        $this->grant($this->worker, 'calendar.view', true);
        $query = http_build_query(['start' => now()->subDay()->toIso8601String(), 'end' => now()->addMonth()->toIso8601String(), 'sources' => ['drill'], 'site_ids' => [$this->site->id]], '', '&', PHP_QUERY_RFC3986);
        $response = $this->actingAs($this->worker)->getJson('/calendar/items?'.$query)->assertOk();
        $row = collect($response->json('events'))->firstWhere('id', 'drill-'.$drill->id);
        $this->assertNotNull($row);
        $this->assertSame('Evacuation — Whānau assembly — drill', $row['title']);
        $this->assertSame(EmergencyDrillReturn::href($drill->id, '/sites/'.$this->site->id.'/calendar'), $row['link']);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function test_calendar_drill_range_preserves_nz_month_midnight_as_exact_instants(): void
    {
        $this->assertExactCalendarRange('2026-11-01T00:00:00+13:00', '2026-11-01T23:59:59+13:00');
    }

    public function test_calendar_drill_range_preserves_nz_dst_day_without_inflating_to_utc_dates(): void
    {
        $this->assertExactCalendarRange('2026-09-27T00:00:00+12:00', '2026-09-27T23:59:59+13:00');
    }

    private function assertExactCalendarRange(string $from, string $to): void
    {
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);
        $before = $this->drill(['scheduled_at' => $start->copy()->subSecond()->utc(), 'title' => 'Before exact range']);
        $first = $this->drill(['scheduled_at' => $start->copy()->utc(), 'title' => 'Exact first instant']);
        $last = $this->drill(['scheduled_at' => $end->copy()->utc(), 'title' => 'Exact last instant']);
        $after = $this->drill(['scheduled_at' => $end->copy()->addSecond()->utc(), 'title' => 'After exact range']);
        $this->grant($this->worker, 'calendar.view', true);
        $query = http_build_query(['start' => $from, 'end' => $to, 'sources' => ['drill'], 'site_ids' => [$this->site->id]], '', '&', PHP_QUERY_RFC3986);
        $response = $this->actingAs($this->worker)->getJson('/calendar/items?'.$query)->assertOk();
        $rows = collect($response->json('events'));
        $this->assertEqualsCanonicalizing(['drill-'.$first->id, 'drill-'.$last->id], $rows->pluck('id')->all());
        $this->assertNotContains('drill-'.$before->id, $rows->pluck('id'));
        $this->assertNotContains('drill-'.$after->id, $rows->pluck('id'));
        $this->assertTrue(Carbon::parse($rows->firstWhere('id', 'drill-'.$first->id)['start'])->equalTo($start));
        $this->assertTrue(Carbon::parse($rows->firstWhere('id', 'drill-'.$last->id)['start'])->equalTo($end));
        $this->assertSame($from, $start->toIso8601String());
        $this->assertSame($to, $end->toIso8601String());
    }

    public function test_token_calendar_feed_preserves_established_reader_without_granting_source_access_from_calendar(): void
    {
        $this->drill(['scheduled_at' => now()->addDay(), 'title' => 'Authorized subscribed drill']);
        $token = Str::random(40);
        $this->worker->forceFill(['calendar_feed_token' => $token])->saveQuietly();
        $this->grant($this->worker, 'calendar.view', true);
        $this->get('/calendar/feed/'.$token.'.ics')->assertOk()->assertSee('Authorized subscribed drill', false);
        $this->grant($this->worker, 'hazards.view', false);
        $this->get('/calendar/feed/'.$token.'.ics')->assertOk()->assertDontSee('Authorized subscribed drill', false);
        $this->worker->hrEmployeeProfile()->update(['is_active' => false]);
        $this->grant($this->worker, 'hazards.view', true);
        $this->get('/calendar/feed/'.$token.'.ics')->assertOk()->assertDontSee('Authorized subscribed drill', false);
        $aggregate = app(SiteCalendarAggregator::class);
        $this->assertSame([], $aggregate->itemsForRange([$this->site->id], now()->subDay(), now()->addMonth(), ['sources' => ['drill'], 'user_id' => $this->worker->id, 'viewer' => null]));
    }

    public function test_site_panel_has_current_scope_honest_attendance_and_all_records_without_silent_limit(): void
    {
        $drill = $this->drill(['status' => 'completed', 'total_participants' => 8]);
        EmergencyDrillParticipant::create(['emergency_drill_id' => $drill->id, 'user_id' => $this->worker->id, 'attended' => false]);
        $this->finding($drill, ['status' => 'closed']);
        for ($i = 0; $i < 101; $i++) {
            $this->drill();
        }
        $foreign = Site::factory()->create();
        $this->drill(['site_id' => $foreign->id, 'title' => 'Other Site secret']);
        $panel = app(SiteProfileSafetyPresenter::class)->drills($this->worker, $this->site);
        $this->assertFalse($panel['locked']);
        $this->assertCount(102, $panel['items']);
        $row = collect($panel['items'])->firstWhere('id', $drill->id);
        $this->assertSame(1, $row['assigned_count']);
        $this->assertSame(0, $row['attended_count']);
        $this->assertSame(0, $row['open_findings']);
        $this->assertSame(EmergencyDrillReturn::href($drill->id, '/sites/'.$this->site->id.'?tab=drills'), $row['href']);
        $this->assertTrue(app(SiteProfileSafetyPresenter::class)->drills($this->worker, $foreign)['locked']);
        $this->assertStringNotContainsString('Other Site secret', json_encode($panel));
    }

    public function test_review_and_control_room_navigation_return_to_exact_authorized_finding_and_original_worklist(): void
    {
        $this->grant($this->worker, 'controlRoom.alerts.view', true);
        $drill = $this->drill();
        $finding = $this->finding($drill);
        $event = HsEvent::factory()->create(['site_id' => $this->site->id, 'source_type' => EmergencyDrill::class, 'source_id' => $drill->id, 'event_category' => HsEvent::CATEGORY_DRILL_FAILURE]);
        $back = EmergencyDrillReturn::href($drill->id, '/tasks?assigned=me', 'findings', $finding->id);
        $this->actingAs($this->worker)->get('/health-safety/events/'.$event->id.'?return_to='.rawurlencode($back))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('detail.source.url', $back));
        $alert = ControlRoomAlert::factory()->create(['site_id' => $this->site->id, 'context' => ['normalized_data' => ['source_module' => 'health_safety', 'signal_type' => 'hs_drill_failure', 'emergency_drill_id' => $drill->id]]]);
        $this->grant($this->worker, 'controlRoom.alerts.view', false);
        $this->assertSame([], app(EmergencyDrillConnectionsService::class)->forViewer($drill, $this->worker)['control_room']);
        $this->grant($this->worker, 'controlRoom.alerts.view', true);
        $links = app(EmergencyDrillConnectionsService::class)->forViewer($drill, $this->worker, '/tasks?assigned=me');
        $this->assertSame([$alert->id], array_column($links['control_room'], 'id'));
        parse_str(parse_url($links['control_room'][0]['href'], PHP_URL_QUERY), $query);
        $this->assertSame(EmergencyDrillReturn::href($drill->id, '/tasks?assigned=me', 'connections'), $query['return_to']);
        $this->get($links['control_room'][0]['href'])->assertOk()->assertInertia(fn (Assert $page) => $page->where('return_to', $query['return_to']));
    }

    private function staff(): User
    {
        $user = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
        foreach (['hazards.view' => true, 'hazards.manage' => false, 'healthSafety.viewAllSites' => false] as $key => $allowed) {
            $this->grant($user, $key, $allowed);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'start_date' => '2020-01-01', 'end_date' => null, 'is_active' => true]);

        return $user;
    }

    private function grant(User $user, string $key, bool $allowed): void
    {
        $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => $allowed]]);
    }

    private function drill(array $fields = []): EmergencyDrill
    {
        return EmergencyDrill::factory()->create(['site_id' => $this->site->id, 'created_by' => $this->worker->id, 'conducted_by' => $this->worker->id, 'lock_version' => 1, ...$fields]);
    }

    private function finding(EmergencyDrill $drill, array $fields = []): EmergencyDrillFinding
    {
        return EmergencyDrillFinding::create(['emergency_drill_id' => $drill->id, 'finding_type' => 'observation', 'description' => 'Assigned source finding', 'status' => 'open', 'assigned_to' => $this->worker->id, ...$fields]);
    }
}
