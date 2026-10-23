<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Presenters\TrackingWorkspacePresenter;
use App\Http\Controllers\MyTasksController;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\LoneWorkerSession;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\HealthSafety\LoneWorkerSessionConnectionsService;
use App\Services\HealthSafety\LoneWorkerSessionService;
use App\Services\HealthSafety\LoneWorkerSignalService;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use App\Services\Tracking\PeopleLocationService;
use App\Support\HealthSafety\LoneWorkerSessionReturn;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class LoneWorkerSessionWorkProjectionTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $owner;

    private User $manager;

    private array $hiddenActions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house']);
        $this->owner = $this->worker();
        $this->manager = $this->worker(true);
        $tasks = Mockery::mock(TaskAggregator::class);
        $tasks->shouldReceive('itemsFor')->andReturn([]);
        $tasks->shouldReceive('filterItems')->andReturn([]);
        $tasks->shouldReceive('navigationBadgeFor')->andReturn(['view' => true, 'badge' => 0, 'degraded' => false]);
        $tasks->shouldReceive('findItemFor')->andReturnUsing(function ($viewer, $source, $id) {
            if ($source !== 'corrective_action' || in_array((int) $id, $this->hiddenActions, true)) {
                return null;
            }
            $action = HsCorrectiveAction::find($id);

            return $action ? new TaskItem(id: 'corrective_action-'.$id, source: 'corrective_action', sourceLabel: 'Corrective actions', ref: $action->reference_number,
                title: $action->title, status: $action->status, bucket: TaskItem::BUCKET_OPEN, severity: 'medium', assignee: null, link: '/health-safety/corrective-actions/'.$id.'?section=evidence') : null;
        });
        $this->app->instance(TaskAggregator::class, $tasks);
    }

    public function test_actual_my_day_getter_uses_current_owned_source_without_requiring_module_view(): void
    {
        $record = $this->sessionSource();
        $row = $this->myDay($this->owner);
        $this->assertSame($record->id, $row['id']);
        $this->assertNull($row['last_check_in_at']);
        $this->assertSame('no_check_in', $row['observation']['state']);
        $this->assertTrue($row['can']['check_in']);
        $this->assertFalse($row['can']['manage']);
        $this->assertSame(LoneWorkerSessionReturn::href($record->id, '/my-day'), $row['href']);
        $this->assertNull($this->myDay($this->manager));
    }

    public function test_my_day_omits_currently_revoked_worker_site_and_poisoned_source_context(): void
    {
        $record = $this->sessionSource();
        $this->assertNotNull($this->myDay($this->owner));
        $record->update(['site_id' => Site::factory()->create()->id]);
        $this->assertNull($this->myDay($this->owner));
        $record->update(['site_id' => $this->site->id]);
        HrEmployeeProfile::where('user_id', $this->owner->id)->update(['is_active' => false]);
        $this->assertNull($this->myDay($this->owner));
    }

    public function test_my_day_excludes_ended_rows_even_when_the_legacy_status_still_looks_live(): void
    {
        $this->sessionSource(['ended_at' => now()]);
        $this->assertNull($this->myDay($this->owner));
    }

    public function test_connections_require_actual_exact_origin_and_actual_control_room_destination(): void
    {
        $record = $this->sessionSource();
        $alert = $this->alert($record);
        $fake = ControlRoomAlert::factory()->create(['source' => 'lone_worker', 'alert_type' => $alert->alert_type, 'site_id' => $this->site->id, 'client_id' => null, 'context' => $alert->context]);
        $rows = $this->connections($record);
        $this->assertSame([$alert->id], array_column($rows['control_room'], 'id'));
        $this->assertNotContains($fake->id, array_column($rows['control_room'], 'id'));
        parse_str(parse_url($rows['control_room'][0]['href'], PHP_URL_QUERY), $query);
        $this->assertSame(LoneWorkerSessionReturn::href($record->id, '/tasks?assigned=me', 'connections'), $query['return_to']);
        $this->assertStringNotContainsString('Private response note', json_encode($rows));
        $alert->originSignal->update(['normalized_data' => ['source_module' => 'lone_worker', 'lone_worker_session_id' => $record->id + 10]]);
        $this->assertSame([], $this->connections($record)['control_room']);
    }

    public function test_stale_viewer_cannot_retain_response_links_after_destination_permission_revocation(): void
    {
        $record = $this->sessionSource();
        $this->alert($record);
        $this->assertCount(1, $this->connections($record)['control_room']);
        $this->grant($this->manager, 'controlRoom.viewAny', false);
        $this->grant($this->manager, 'controlRoom.alerts.view', false);
        $this->grant($this->manager, 'controlRoom.alerts.manage', false);
        $this->assertSame([], $this->connections($record)['control_room']);
        $this->assertSame([], $this->connections($record, $this->owner)['control_room']);
        $this->assertNotNull($this->myDay($this->owner));
    }

    public function test_review_and_corrective_work_remain_source_scoped_and_task_readable_independently(): void
    {
        $record = $this->sessionSource();
        $alert = $this->alert($record);
        $event = HsEvent::factory()->create(['control_room_alert_id' => $alert->id, 'source_type' => ControlRoomAlert::class, 'source_id' => $alert->id, 'site_id' => $this->site->id, 'client_id' => null]);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'title' => 'Independent central remedy']);
        $poisoned = HsEvent::factory()->create(['control_room_alert_id' => null, 'source_type' => ControlRoomAlert::class, 'source_id' => $alert->id, 'site_id' => Site::factory()->create()->id]);
        $rows = $this->connections($record);
        $this->assertSame([$event->id], array_column($rows['safety_reviews'], 'id'));
        $this->assertNotContains($poisoned->id, array_column($rows['safety_reviews'], 'id'));
        $this->assertSame([$action->id], array_column($rows['corrective_actions'], 'id'));
        parse_str(parse_url($rows['corrective_actions'][0]['href'], PHP_URL_QUERY), $query);
        $this->assertSame('evidence', $query['section']);
        $this->assertSame(LoneWorkerSessionReturn::href($record->id, '/tasks?assigned=me', 'connections'), $query['return_to']);
        $this->hiddenActions[] = $action->id;
        $rows = $this->connections($record);
        $this->assertCount(1, $rows['safety_reviews']);
        $this->assertSame([], $rows['corrective_actions']);
    }

    public function test_people_location_source_link_preserves_actual_bounded_origin(): void
    {
        $record = $this->sessionSource();
        $this->grant($this->manager, 'assets.telemetry.view', true);
        $origin = '/operations/people-locations/people?population=staff&site='.$this->site->id.'&selected=s'.$this->owner->id.'&source='.$record->id;
        $method = new ReflectionMethod(PeopleLocationService::class, 'staff');
        $row = $method->invoke(app(PeopleLocationService::class), $this->manager->fresh(), $record, $origin);
        $this->assertSame(LoneWorkerSessionReturn::href($record->id, $origin, 'location'), $row['profileUrl']);
        $this->assertNull($row['position']);
    }

    public function test_tracking_source_link_reauthorizes_session_and_uses_actual_worker_filter_when_no_session(): void
    {
        $record = $this->sessionSource();
        $method = new ReflectionMethod(TrackingWorkspacePresenter::class, 'canonicalHref');
        $presenter = app(TrackingWorkspacePresenter::class);
        $origin = '/security-devices/tracking?tab=personal-safety&attention=stale';
        $href = $method->invoke($presenter, $this->manager, 'personal-safety', null, $this->owner, $record, null, $origin);
        $this->assertSame(LoneWorkerSessionReturn::href($record->id, $origin, 'location'), $href);
        $this->assertSame('/health-safety/lone-workers?user_id='.$this->owner->id, $method->invoke($presenter, $this->manager, 'personal-safety', null, $this->owner, null, null, $origin));
        HrEmployeeProfile::where('user_id', $this->owner->id)->update(['is_active' => false]);
        $this->assertNull($method->invoke($presenter, $this->manager, 'personal-safety', null, $this->owner, $record, null, $origin));
    }

    public function test_independent_review_and_task_readable_remedy_remain_available_when_control_room_entry_is_denied(): void
    {
        $record = $this->sessionSource();
        $alert = $this->alert($record);
        $event = HsEvent::factory()->create(['control_room_alert_id' => $alert->id, 'source_type' => ControlRoomAlert::class, 'source_id' => $alert->id, 'site_id' => $this->site->id, 'client_id' => null]);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id]);
        foreach (['controlRoom.viewAny', 'controlRoom.alerts.view', 'controlRoom.alerts.manage'] as $key) {
            $this->grant($this->manager, $key, false);
        }
        $rows = $this->connections($record);
        $this->assertSame([], $rows['control_room']);
        $this->assertSame([$event->id], array_column($rows['safety_reviews'], 'id'));
        $this->assertSame([$action->id], array_column($rows['corrective_actions'], 'id'));
        $this->assertStringNotContainsString('Private response note', json_encode($rows));
        $alert->originSignal->update(['site_id' => Site::factory()->create()->id]);
        $rows = $this->connections($record);
        $this->assertSame([], $rows['safety_reviews']);
        $this->assertSame([], $rows['corrective_actions']);
    }

    public function test_independent_incident_reader_gets_only_exact_incident_while_control_room_remains_denied(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $record = $this->sessionSource(['client_id' => $client->id]);
        $alert = $this->alert($record);
        $incident = ClientIncident::factory()->create(['control_room_alert_id' => $alert->id, 'site_id' => $this->site->id, 'client_id' => $client->id, 'reported_by' => $this->owner->id, 'portal_visible' => false]);
        $this->grant($this->manager, 'incidents.viewAny', true);
        foreach (['controlRoom.viewAny', 'controlRoom.alerts.view', 'controlRoom.alerts.manage'] as $key) {
            $this->grant($this->manager, $key, false);
        }
        $rows = $this->connections($record);
        $this->assertSame([], $rows['control_room']);
        $this->assertSame([$incident->id], array_column($rows['incidents'], 'id'));
        $this->assertStringContainsString('/incidents/'.$incident->id.'?', $rows['incidents'][0]['href']);
        $this->grant($this->manager, 'incidents.viewAny', false);
        $this->grant($this->manager, 'incidents.viewAssigned', false);
        $this->assertSame([], $this->connections($record)['incidents']);
    }

    public function test_my_day_retains_exact_actor_bound_confirmation_after_end_without_active_session(): void
    {
        $record = $this->sessionSource();
        $key = (string) Str::uuid();
        $confirmed = app(LoneWorkerSessionService::class)->end($record, $this->manager, ['expected_version' => 1, 'idempotency_key' => $key]);
        $candidate = ['id' => $record->id, 'lock_version' => (int) $confirmed->lock_version, 'idempotency_key' => $key];
        $proof = [...$candidate, 'command' => 'end', 'removed' => false];
        $this->actingAs($this->manager)->withSession(['lone_worker_confirmation' => $candidate])->get('/my-day')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('my-day/index')
            ->where('active_lone_worker_session', null)->where('lone_worker_confirmation', $proof));
        $this->actingAs($this->owner)->withSession(['lone_worker_confirmation' => $candidate])->get('/my-day')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('lone_worker_confirmation', null));
    }

    public function test_actual_related_record_routes_preserve_session_section_and_filtered_origin(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $record = $this->sessionSource(['client_id' => $client->id]);
        $alert = $this->alert($record);
        $event = HsEvent::factory()->create(['control_room_alert_id' => $alert->id, 'source_type' => ControlRoomAlert::class,
            'source_id' => $alert->id, 'site_id' => $this->site->id, 'client_id' => $client->id]);
        HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id]);
        ClientIncident::factory()->create(['control_room_alert_id' => $alert->id, 'site_id' => $this->site->id,
            'client_id' => $client->id, 'reported_by' => $this->owner->id, 'portal_visible' => false]);
        $this->grant($this->manager, 'incidents.viewAny', true);
        $origin = '/health-safety/lone-workers?tab=sessions&site_id='.$this->site->id.'&status=active&period=all';
        $back = LoneWorkerSessionReturn::href($record->id, $origin, 'connections');
        $rows = app(LoneWorkerSessionConnectionsService::class)->forViewer($record, $this->manager, $origin);
        foreach (['incidents' => 'incidents/show', 'safety_reviews' => 'health-safety/events/show',
            'corrective_actions' => 'health-safety/corrective-actions/show', 'control_room' => 'control-room/show'] as $domain => $component) {
            $this->assertCount(1, $rows[$domain]);
            $href = $rows[$domain][0]['href'];
            $response = $this->actingAs($this->manager)->get($href)->assertOk();
            $response->assertInertia(fn (Assert $page) => $page->component($component));
            $page = $response->viewData('page');
            parse_str(parse_url($page['url'], PHP_URL_QUERY), $query);
            $this->assertSame($back, $query['return_to']);
            if ($domain === 'control_room') {
                $this->assertSame($back, $page['props']['return_to']);
            }
        }
        $this->get($back)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('health-safety/lone-workers/show')->where('section', 'connections')->where('return_to', $origin));
    }

    public function test_actual_control_room_destination_and_register_readback_keep_filtered_canonical_alert_status(): void
    {
        $record = $this->sessionSource();
        $alert = $this->alert($record);
        foreach (['open', 'ack', 'triaging', 'confirmed', 'resolved', 'closed', 'dismissed'] as $status) {
            $alert->forceFill(['status' => $status])->saveQuietly();
            $origin = '/health-safety/lone-workers?tab=alerts&site_id='.$this->site->id.'&status='.$status.'&period=all';
            $destination = app(ControlRoomAlertAccessService::class)->destinationFor($alert->fresh(), $this->manager, $origin);
            $this->assertNotNull($destination['href']);
            parse_str(parse_url($destination['href'], PHP_URL_QUERY), $query);
            $this->assertSame($origin, $query['return_to']);
            $back = LoneWorkerSessionReturn::href($record->id, $origin, 'response');
            $legacyDestination = app(ControlRoomAlertAccessService::class)->destinationFor($alert->fresh(), $this->manager, $back);
            $this->actingAs($this->manager)->get($origin.'&alert=cr_'.$alert->id)->assertRedirect($legacyDestination['href']);
            $this->get($legacyDestination['href'])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('control-room/show')->where('return_to', $back));
            $this->get($back)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/lone-workers/show')->where('section', 'response')->where('return_to', $origin));
            $this->get($destination['href'])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('control-room/show')->where('return_to', $origin));
            $this->get($origin)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/lone-workers/index')->where('tab', 'alerts')->where('filters.status', $status)
                ->where('alerts.data.0.id', 'cr_'.$alert->id)->where('alerts.data.0.canonical_status', $status));
        }
    }

    public function test_legacy_alert_navigation_requires_positive_id_and_exact_readable_persisted_origin(): void
    {
        $record = $this->sessionSource();
        $alert = $this->alert($record);
        $path = '/health-safety/lone-workers?tab=alerts&period=all&alert=';
        $this->actingAs($this->manager)->get($path.'cr_1x')->assertNotFound();
        $this->get($path.'cr_0')->assertNotFound();
        $this->get($path.'cr_999999')->assertNotFound();
        $this->get('/health-safety/lone-workers?alert[]=cr_'.$alert->id)->assertNotFound();
        $fake = ControlRoomAlert::factory()->create(['source' => 'lone_worker', 'site_id' => $this->site->id, 'context' => $alert->context]);
        $this->get($path.'cr_'.$fake->id)->assertNotFound();
        $this->grant($this->manager, 'controlRoom.viewAny', false);
        $this->grant($this->manager, 'controlRoom.alerts.view', false);
        $this->grant($this->manager, 'controlRoom.alerts.manage', false);
        $this->get($path.'cr_'.$alert->id)->assertNotFound();
    }

    public function test_register_private_client_surname_cannot_match_or_enter_export_rows_without_current_exact_read_authority(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'PrivateFirstHS15', 'last_name' => 'PrivateSurnameHS15']);
        $record = $this->sessionSource(['client_id' => $client->id]);
        $this->alert($record);
        $this->grant($this->manager, 'clients.viewAny', false);
        $this->grant($this->manager, 'clients.viewAssigned', true);
        foreach (['sessions', 'alerts'] as $tab) {
            $path = '/health-safety/lone-workers?tab='.$tab.'&period=all';
            $filtered = $this->actingAs($this->manager)->getJson($path.'&q=PrivateSurnameHS15')->assertOk();
            $filtered->assertJsonPath($tab.'.total', 0)->assertJsonPath($tab.'.data', []);
            $visible = $this->getJson($path)->assertOk();
            $visible->assertJsonPath($tab.'.total', 1);
            $row = $visible->json($tab.'.data.0');
            $this->assertNull($tab === 'sessions' ? $row['client'] : $row['session']['client']);
            if ($tab === 'sessions') {
                $this->assertNull($row['client_id']);
                $this->assertNull($row['client_name']);
            }
            $this->assertStringNotContainsString('PrivateSurnameHS15', $visible->getContent());
        }
        $client->supportWorkers()->syncWithoutDetaching([$this->manager->id]);
        foreach (['sessions', 'alerts'] as $tab) {
            $path = '/health-safety/lone-workers?tab='.$tab.'&period=all&q=PrivateSurnameHS15';
            $response = $this->getJson($path)->assertOk()->assertJsonPath($tab.'.total', 1);
            $row = $response->json($tab.'.data.0');
            $this->assertSame('PrivateFirstHS15 PrivateSurnameHS15', $tab === 'sessions' ? $row['client']['name'] : $row['session']['client']['name']);
        }
        $client->supportWorkers()->detach($this->manager->id);
        $this->getJson('/health-safety/lone-workers?period=all&q=PrivateSurnameHS15')->assertOk()->assertJsonPath('sessions.total', 0);
    }

    private function worker(bool $manage = false): User
    {
        $user = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
        foreach (['hazards.view' => $manage, 'hazards.manage' => $manage, 'healthSafety.viewAllSites' => false, 'assets.telemetry.view' => false,
            'controlRoom.viewAny' => $manage, 'controlRoom.alerts.view' => $manage, 'controlRoom.alerts.manage' => $manage, 'reports.viewAny' => false, 'medications.controlled.view' => false] as $key => $allowed) {
            $this->grant($user, $key, $allowed);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);

        return $user;
    }

    private function grant(User $user, string $key, bool $allowed): void
    {
        $id = Permission::where('key', $key)->firstOrFail()->id;
        $user->permissionOverrides()->syncWithoutDetaching([$id => ['allowed' => $allowed]]);
    }

    private function sessionSource(array $values = []): LoneWorkerSession
    {
        $record = LoneWorkerSession::create(['user_id' => $this->owner->id, 'site_id' => $this->site->id, 'started_at' => now()->subMinutes(10),
            'expected_end_at' => now()->addHours(2), 'check_in_interval_minutes' => 30, 'status' => 'active', 'created_by' => $this->owner->id, 'updated_by' => $this->owner->id, ...$values]);
        $record->forceFill(['lock_version' => 1])->saveQuietly();

        return $record;
    }

    private function alert(LoneWorkerSession $record): ControlRoomAlert
    {
        $context = ['source_module' => 'lone_worker', 'signal_type' => LoneWorkerSignalService::TYPE_EMERGENCY, 'lone_worker_session_id' => $record->id, 'worker_user_id' => $record->user_id, 'site_id' => $record->site_id, 'client_id' => $record->client_id];
        $source = SignalSource::firstOrCreate(['slug' => 'lone_worker'], ['name' => 'Lone-worker safety', 'status' => 'active']);
        $signal = Signal::create(['signal_source_id' => $source->id, 'signal_type_code' => LoneWorkerSignalService::TYPE_EMERGENCY, 'site_id' => $record->site_id,
            'client_id' => $record->client_id, 'occurred_at' => now(), 'payload' => [], 'normalized_data' => $context, 'status' => 'processed', 'idempotency_key' => 'test-session-'.$record->id]);
        $alert = ControlRoomAlert::factory()->create(['source' => 'lone_worker', 'origin_signal_id' => $signal->id, 'alert_type' => LoneWorkerSignalService::canonicalAlertType(LoneWorkerSignalService::TYPE_EMERGENCY),
            'site_id' => $record->site_id, 'client_id' => $record->client_id, 'context' => ['signal_type_code' => LoneWorkerSignalService::TYPE_EMERGENCY, 'normalized_data' => $context], 'notes' => 'Private response note']);
        $signal->update(['alert_id' => $alert->id]);

        return $alert;
    }

    private function connections(LoneWorkerSession $record, ?User $viewer = null): array
    {
        return app(LoneWorkerSessionConnectionsService::class)->forViewer($record, $viewer ?? $this->manager, '/tasks?assigned=me');
    }

    private function myDay(User $viewer): ?array
    {
        return (new ReflectionMethod(MyTasksController::class, 'getActiveLoneWorkerSession'))->invoke(app(MyTasksController::class), $viewer);
    }
}
