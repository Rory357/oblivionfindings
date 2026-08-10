<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\AlertWorklistQuery;
use App\Services\ControlRoom\ControlRoomDeskService;
use App\Services\ControlRoom\ControlRoomReportService;
use App\Services\Tasks\TaskAggregator;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ControlRoomDeskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_dashboard_exposes_the_live_desk_contract_and_defers_analytics(): void
    {
        $site = Site::factory()->create(['name' => 'Kōwhai House']);
        $viewer = $this->siteBoundUser($site, ['controlRoom.viewAny', 'controlRoom.alerts.manage']);

        $this->mock(TaskAggregator::class, function ($mock): void {
            $mock->shouldReceive('sourcesFor')
                ->once()
                ->andReturn([['key' => 'control_room_alerts', 'label' => 'Control Room alerts']]);
            $mock->shouldReceive('badgeCountFor')->once()->andReturn(7);
        });

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($viewer)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/index')
                ->has('hero')
                ->has('worklist.data')
                ->has('queues')
                ->has('handover')
                ->has('activity')
                ->has('filters')
                ->has('freshness')
                ->has('sites')
                ->has('staff')
                ->has('can')
                ->where('auth.can.tasks.view', true)
                ->where('auth.can.tasks.badge', 7)
                ->missing('analytics')
            );

        $permissionQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'permission_user')
            || str_contains($query['query'], 'role_permission'));
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(10, $permissionQueries->count(), $permissionQueries->pluck('query')->implode("\n"));
    }

    public function test_worklist_matches_the_shared_priority_query_and_excludes_dismissed_and_snoozed_alerts(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['controlRoom.viewAny']);
        $critical = ControlRoomAlert::factory()->critical()->create([
            'site_id' => $site->id,
            'reference_number' => 'CR-2026-0201',
            'created_by_user_id' => $viewer->id,
        ]);
        $high = ControlRoomAlert::factory()->high()->create([
            'site_id' => $site->id,
            'reference_number' => 'CR-2026-0202',
            'created_by_user_id' => $viewer->id,
        ]);
        ControlRoomAlert::factory()->critical()->create([
            'site_id' => $site->id,
            'status' => ControlRoomAlert::STATUS_DISMISSED,
            'created_by_user_id' => $viewer->id,
        ]);
        ControlRoomAlert::factory()->critical()->create([
            'site_id' => $site->id,
            'snoozed_until' => now()->addHour(),
            'created_by_user_id' => $viewer->id,
        ]);

        $expected = app(AlertWorklistQuery::class)
            ->forUser($viewer, ['site_id' => $site->id])
            ->limit(25)
            ->pluck('control_room_alerts.id')
            ->all();

        $version = app(HandleInertiaRequests::class)->version(request());
        $response = $this->actingAs($viewer)
            ->get('/control-room?site_id='.$site->id, [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $version,
                'X-Inertia-Partial-Component' => 'control-room/index',
                'X-Inertia-Partial-Data' => 'hero,worklist,handover',
            ])
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('props.hero.active', 2)
            ->assertJsonPath('props.hero.critical', 1)
            ->assertJsonPath('props.hero.unassigned', 2)
            ->assertJsonPath('props.handover.needs_incident', 2);

        $this->assertSame($expected, collect($response->json('props.worklist.data'))->pluck('id')->all());
        $this->assertSame([$critical->id, $high->id], $expected);
    }

    public function test_last_24_hour_response_average_uses_responded_at_and_remains_null_when_unavailable(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['controlRoom.viewAny']);
        $alert = ControlRoomAlert::factory()->create([
            'site_id' => $site->id,
            'triggered_at' => now()->subMinutes(60),
            'acknowledged_at' => now()->subMinutes(59),
            'created_by_user_id' => $viewer->id,
        ]);
        AlertSla::query()->create([
            'alert_id' => $alert->id,
            'acknowledged_at' => now()->subMinutes(59),
            'responded_at' => now()->subMinutes(30),
        ]);

        $withResponse = app(ControlRoomDeskService::class)->live($viewer, ['site_id' => $site->id]);
        $this->assertEqualsWithDelta(30.0, $withResponse['hero']['last_24_hours']['avg_response_minutes'], 1.0);

        AlertSla::query()->delete();
        app()->forgetInstance(ControlRoomDeskService::class);
        $withoutResponse = app(ControlRoomDeskService::class)->live($viewer, ['site_id' => $site->id]);
        $this->assertNull($withoutResponse['hero']['last_24_hours']['avg_response_minutes']);
    }

    public function test_queue_pressure_filter_opens_the_matching_live_worklist(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['controlRoom.viewAny']);
        $immediate = TriageQueue::query()->create([
            'name' => 'Immediate response',
            'code' => 'immediate_response_filter',
            'tier' => 1,
            'is_active' => true,
        ]);
        $routine = TriageQueue::query()->create([
            'name' => 'Routine response',
            'code' => 'routine_response_filter',
            'tier' => 2,
            'is_active' => true,
        ]);
        $expected = ControlRoomAlert::factory()->create([
            'site_id' => $site->id,
            'queue_id' => $immediate->id,
            'created_by_user_id' => $viewer->id,
        ]);
        ControlRoomAlert::factory()->create([
            'site_id' => $site->id,
            'queue_id' => $routine->id,
            'created_by_user_id' => $viewer->id,
        ]);

        $live = app(ControlRoomDeskService::class)->live($viewer, [
            'site_id' => $site->id,
            'queue_id' => $immediate->id,
        ]);

        $this->assertSame([$expected->id], collect($live['worklist']['data'])->pluck('id')->all());
    }

    public function test_live_snapshot_stays_within_query_budget_and_partial_reload_never_calls_analytics(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['controlRoom.viewAny']);
        $queue = TriageQueue::query()->create([
            'name' => 'Immediate response',
            'code' => 'immediate_response',
            'tier' => 1,
            'is_active' => true,
        ]);
        ControlRoomAlert::factory()->count(3)->create([
            'site_id' => $site->id,
            'queue_id' => $queue->id,
            'created_by_user_id' => $viewer->id,
        ]);

        $desk = app(ControlRoomDeskService::class);
        $desk->prepareViewerAccess($viewer);
        $desk->filters($viewer, ['site_id' => $site->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $desk->live($viewer, ['site_id' => $site->id]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(18, count($queries), collect($queries)->pluck('query')->implode("\n"));

        $this->mock(ControlRoomReportService::class, function ($mock): void {
            $mock->shouldNotReceive('alertVolume');
            $mock->shouldNotReceive('slaCompliance');
            $mock->shouldNotReceive('escalationAnalysis');
            $mock->shouldNotReceive('slaDailyTrend');
            $mock->shouldNotReceive('siteComparison');
        });
        app()->forgetInstance(ControlRoomDeskService::class);

        $version = app(HandleInertiaRequests::class)->version(request());
        $this->actingAs($viewer)
            ->get('/control-room', [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $version,
                'X-Inertia-Partial-Component' => 'control-room/index',
                'X-Inertia-Partial-Data' => 'hero,worklist,queues,handover,activity,freshness',
            ])
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'control-room/index')
            ->assertJsonMissingPath('props.analytics');
    }

    public function test_handover_summary_counts_real_canonical_journey_states(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['controlRoom.viewAny']);
        $alert = ControlRoomAlert::factory()->create([
            'site_id' => $site->id,
            'created_by_user_id' => $viewer->id,
        ]);
        HsEvent::factory()->create([
            'site_id' => $site->id,
            'control_room_alert_id' => $alert->id,
            'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
        ]);

        $live = app(ControlRoomDeskService::class)->live($viewer, ['site_id' => $site->id]);

        $this->assertSame(1, $live['handover']['awaiting_health_safety']);
        $this->assertSame(1, $live['handover']['needs_incident']);
    }

    /** @param list<string> $permissionKeys */
    private function siteBoundUser(Site $site, array $permissionKeys): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $user->roles()->syncWithoutDetaching([
            Role::query()->where('name', 'support_worker')->firstOrFail()->id,
        ]);
        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->sync($permissionIds->mapWithKeys(
            fn ($permissionId) => [$permissionId => ['allowed' => true]],
        ));
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'position_role' => 'support_worker',
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $user;
    }
}
