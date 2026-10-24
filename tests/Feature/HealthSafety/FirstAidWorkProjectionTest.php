<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\FirstAidRecord;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\FirstAidConnectionsService;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use App\Support\HealthSafety\FirstAidReturn;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

/** Source-only relationship projections; no copied central action or response workflow. */
class FirstAidWorkProjectionTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $viewer;

    private array $hiddenActions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house']);
        $this->viewer = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $this->viewer->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
        foreach (['hazards.view' => true, 'hazards.manage' => true, 'healthSafety.viewAllSites' => false, 'clients.viewAny' => false, 'clients.viewAssigned' => false,
            'incidents.viewAny' => false, 'incidents.viewAssigned' => false, 'controlRoom.viewAny' => false, 'controlRoom.alerts.view' => false, 'controlRoom.alerts.manage' => false, 'reports.viewAny' => false] as $key => $allowed) {
            $this->grant($key, $allowed);
        }
        $tasks = Mockery::mock(TaskAggregator::class);
        $tasks->shouldReceive('navigationBadgeFor')->andReturn(['view' => true, 'badge' => 0, 'degraded' => false]);
        $tasks->shouldReceive('navigationBadgeInContext')->andReturn(['view' => true, 'badge' => 0, 'degraded' => false]);
        $tasks->shouldReceive('findItemFor')->andReturnUsing(function ($viewer, $source, $id) {
            if ($source !== 'corrective_action' || in_array((int) $id, $this->hiddenActions, true)) {
                return null;
            }
            $action = HsCorrectiveAction::find($id);

            return $action ? new TaskItem(id: 'corrective_action-'.$id, source: 'corrective_action', sourceLabel: 'Corrective actions', ref: null,
                title: $action->title, status: $action->status, bucket: TaskItem::BUCKET_OPEN, severity: 'medium', assignee: null,
                link: '/health-safety/corrective-actions/'.$id.'?section=overview') : null;
        });
        $this->app->instance(TaskAggregator::class, $tasks);
    }

    public function test_reviews_require_exact_source_category_site_and_recorded_subject_tuple(): void
    {
        $record = $this->record();
        $own = $this->event($record);
        $this->event($record, ['site_id' => Site::factory()->create()->id]);
        $this->event($record, ['source_type' => ClientIncident::class]);
        $this->event($record, ['event_category' => HsEvent::CATEGORY_DRILL_FAILURE]);
        $this->event($record, ['staff_id' => User::factory()->create()->id]);
        $this->event($record, ['client_id' => Client::factory()->create(['site_id' => $this->site->id])->id]);
        $connections = $this->connections($record);
        $this->assertSame([$own->id], array_column($connections['safety_reviews'], 'id'));
        $this->assertSame([], $connections['equipment']);
        $this->assertSame([], $connections['control_room']);
    }

    public function test_all_authorized_source_reviews_and_central_actions_are_returned_without_silent_caps(): void
    {
        $record = $this->record();
        for ($i = 0; $i < 23; $i++) {
            $this->event($record);
        }
        $event = $this->event($record);
        for ($i = 0; $i < 51; $i++) {
            HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id]);
        }
        $connections = $this->connections($record);
        $this->assertCount(24, $connections['safety_reviews']);
        $this->assertCount(51, $connections['corrective_actions']);
        $this->assertNull($connections['corrective_actions'][0]['reference_number']);
        $this->assertNull($connections['corrective_actions'][0]['assigned_to']);
    }

    public function test_central_actions_require_actual_task_item_read_and_keep_existing_query_and_source_return(): void
    {
        $record = $this->record();
        $event = $this->event($record);
        $visible = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'due_date' => '2026-10-09']);
        $hidden = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'title' => 'Private central action']);
        $this->hiddenActions = [$hidden->id];
        $rows = $this->connections($record)['corrective_actions'];
        $this->assertSame([$visible->id], array_column($rows, 'id'));
        $this->assertSame('2026-10-09', $rows[0]['due_date']);
        parse_str(parse_url($rows[0]['href'], PHP_URL_QUERY), $query);
        $this->assertSame('overview', $query['section']);
        $this->assertSame(FirstAidReturn::href($record->id, '/health-safety/first-aid?tab=followup&period=all', 'connections'), $query['return_to']);
        $this->assertStringNotContainsString('Private central action', json_encode($rows));
        $this->assertSame('open', $event->refresh()->status);
    }

    public function test_incident_visibility_is_independent_and_a_legacy_unmatched_incident_never_becomes_valid_provenance(): void
    {
        $client = $this->client();
        $record = $this->record(['treated_person_type' => 'client', 'client_id' => $client->id]);
        $incident = ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id, 'title' => 'Independent incident']);
        $record->forceFill(['related_incident_id' => $incident->id, 'incident_reported' => true])->saveQuietly();
        $this->assertNull($this->connections($record)['incident']);
        $this->grant('incidents.viewAssigned', true);
        $this->assertSame($incident->id, $this->connections($record)['incident']['id']);
        $foreign = ClientIncident::factory()->create(['client_id' => Client::factory()->create(['site_id' => $this->site->id])->id, 'site_id' => $this->site->id, 'title' => 'Unrelated private incident']);
        $record->forceFill(['related_incident_id' => $foreign->id])->saveQuietly();
        $this->assertNull($this->connections($record)['incident']);
        $staff = $this->record(['related_incident_id' => $incident->id]);
        $this->assertNull($this->connections($staff)['incident']);
        $this->assertTrue($record->refresh()->incident_reported);
    }

    public function test_related_incident_review_remains_distinct_and_requires_authorized_matching_incident_source(): void
    {
        $client = $this->client();
        $incident = ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id]);
        $record = $this->record(['treated_person_type' => 'client', 'client_id' => $client->id, 'related_incident_id' => $incident->id]);
        $own = $this->event($record);
        $linked = HsEvent::factory()->create(['source_type' => ClientIncident::class, 'source_id' => $incident->id, 'site_id' => $this->site->id, 'client_id' => $client->id,
            'staff_id' => $incident->reported_by, 'event_category' => $incident->type === 'near_miss' ? HsEvent::CATEGORY_NEAR_MISS : HsEvent::CATEGORY_INCIDENT]);
        $this->assertSame([$own->id], array_column($this->connections($record)['safety_reviews'], 'id'));
        $this->grant('incidents.viewAssigned', true);
        $this->assertEqualsCanonicalizing([$own->id, $linked->id], array_column($this->connections($record)['safety_reviews'], 'id'));
        $this->assertSame('open', $own->refresh()->status);
        $this->assertSame('open', $linked->refresh()->status);
    }

    public function test_actual_review_destination_retains_connections_section_and_filtered_source_origin(): void
    {
        $record = $this->record();
        $event = $this->event($record);
        $href = $this->connections($record)['safety_reviews'][0]['href'];
        $response = $this->actingAs($this->viewer)->get($href)->assertOk()->assertInertia(fn (Assert $page) => $page->component('health-safety/events/show'));
        $page = $response->viewData('page');
        parse_str(parse_url($page['url'], PHP_URL_QUERY), $query);
        $this->assertSame(FirstAidReturn::href($record->id, '/health-safety/first-aid?tab=followup&period=all', 'connections'), $query['return_to']);
        $this->assertSame('/health-safety/events/'.$event->id, parse_url($href, PHP_URL_PATH));
    }

    public function test_control_room_destination_requires_its_own_current_permission_and_actual_review_fk(): void
    {
        $record = $this->record();
        $alert = ControlRoomAlert::factory()->create(['site_id' => $this->site->id, 'source' => 'health_safety', 'notes' => 'Private response notes', 'context' => ['normalized_data' => ['source_module' => 'health_safety']]]);
        $this->event($record, ['control_room_alert_id' => $alert->id]);
        $this->assertSame([], $this->connections($record)['control_room']);
        $this->grant('controlRoom.viewAny', true);
        $this->grant('controlRoom.alerts.view', true);
        $rows = $this->connections($record)['control_room'];
        $this->assertSame([$alert->id], array_column($rows, 'id'));
        $this->assertStringNotContainsString('Private response notes', json_encode($rows));
        parse_str(parse_url($rows[0]['href'], PHP_URL_QUERY), $query);
        $this->assertSame(FirstAidReturn::href($record->id, '/health-safety/first-aid?tab=followup&period=all', 'connections'), $query['return_to']);
        $this->actingAs($this->viewer)->get($rows[0]['href'])->assertOk()->assertInertia(fn (Assert $page) => $page->component('control-room/show')->where('return_to', FirstAidReturn::href($record->id, '/health-safety/first-aid?tab=followup&period=all', 'connections')));
        $this->grant('controlRoom.alerts.view', false);
        $this->assertSame([], $this->connections($record)['control_room']);
        $this->assertCount(1, $this->connections($record)['safety_reviews']);
    }

    public function test_source_profile_links_are_actual_permitted_profiles_and_archival_does_not_publish_new_work(): void
    {
        $client = $this->client();
        $record = $this->record(['treated_person_type' => 'client', 'client_id' => $client->id]);
        $this->grant('sites.viewAny', true);
        $this->grant('sites.type.house.view', true);
        $connections = $this->connections($record);
        $this->assertSame('/sites/'.$this->site->id.'?tab=first_aid', $connections['site']['href']);
        $this->assertSame('/operations/clients/'.$client->id.'?tab=first_aid', $connections['person']['href']);
        $event = $this->event($record);
        $record->delete();
        $this->assertSame([$event->id], array_column($this->connections($record)['safety_reviews'], 'id'));
        $this->assertSame('open', $event->refresh()->status);
    }

    public function test_wrong_client_alert_fk_is_not_relabelled_as_a_response_for_a_staff_treatment(): void
    {
        $record = $this->record();
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $alert = ControlRoomAlert::factory()->create(['site_id' => $this->site->id, 'client_id' => $client->id, 'source' => 'health_safety', 'notes' => 'Unrelated response']);
        $event = $this->event($record, ['control_room_alert_id' => $alert->id]);
        $this->grant('controlRoom.viewAny', true);
        $this->grant('controlRoom.alerts.view', true);
        $connections = $this->connections($record);
        $this->assertSame([], $connections['control_room']);
        $this->assertSame([$event->id], array_column($connections['safety_reviews'], 'id'));
        $this->assertStringNotContainsString('Unrelated response', json_encode($connections));
    }

    private function grant(string $key, bool $allowed): void
    {
        $this->viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => $allowed]]);
    }

    private function record(array $fields = []): FirstAidRecord
    {
        $record = FirstAidRecord::factory()->create(['site_id' => $this->site->id, 'first_aider_id' => $this->viewer->id, 'created_by' => $this->viewer->id, 'treated_person_type' => 'staff', 'treated_person_id' => null, 'ambulance_called' => false, 'treatment_outcome' => 'returned_to_activity', ...$fields]);
        $record->forceFill(['lock_version' => 1])->saveQuietly();

        return $record;
    }

    private function client(): Client
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $client->supportWorkers()->attach($this->viewer->id);
        $this->grant('clients.viewAssigned', true);

        return $client;
    }

    private function event(FirstAidRecord $record, array $fields = []): HsEvent
    {
        return HsEvent::factory()->create(['site_id' => $record->site_id, 'source_type' => FirstAidRecord::class, 'source_id' => $record->id,
            'event_category' => HsEvent::CATEGORY_INJURY, 'client_id' => $record->treated_person_type === 'client' ? $record->client_id : null,
            'staff_id' => $record->treated_person_type === 'client' ? null : $record->treated_person_id, 'idempotency_key' => (string) Str::uuid(), ...$fields]);
    }

    private function connections(FirstAidRecord $record): array
    {
        return app(FirstAidConnectionsService::class)->forViewer($record, $this->viewer, '/health-safety/first-aid?tab=followup&period=all');
    }
}
