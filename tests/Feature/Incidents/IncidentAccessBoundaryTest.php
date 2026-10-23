<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\IncidentFollowup;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\IncidentAccessService;
use App\Services\Tasks\Providers\ClientIncidentProvider;
use App\Services\Tasks\Providers\IncidentFollowupProvider;
use App\Support\Incidents\IncidentOccurrencePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IncidentAccessBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(Site $site, array $permissions = ['incidents.viewAny']): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => []]);
        $this->grant($user, $permissions);

        return $user;
    }

    private function grant(User $user, array $permissions): void
    {
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'test', 'module' => 'Test']);
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }

    private function incident(Site $site, array $attributes = []): ClientIncident
    {
        return ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'site_id' => $site->id,
            'client_id' => Client::factory()->create(['site_id' => $site->id])->id,
            'status' => 'submitted',
            ...$attributes,
        ]));
    }

    public function test_active_response_header_count_requires_current_source_read_and_site_authority(): void
    {
        $site = Site::factory()->create();
        $other = Site::factory()->create();
        $reporter = $this->viewer($site);
        $reader = $this->viewer($site, ['incidents.viewAny', 'controlRoom.alerts.view']);
        foreach ([
            ['site_id' => $site->id, 'status' => 'open', 'context' => []],
            ['site_id' => $site->id, 'status' => 'resolved', 'context' => []],
            ['site_id' => $other->id, 'status' => 'open', 'context' => []],
            ['site_id' => $site->id, 'status' => 'open', 'context' => ['normalized_data' => ['controlled_drug' => true]]],
        ] as $attributes) {
            $alert = ControlRoomAlert::factory()->create($attributes);
            $this->incident($site, ['control_room_alert_id' => $alert->id]);
        }
        $this->actingAs($reporter)->get('/incidents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('hero.attention.alerts.value', null)->where('rows.total', 4));
        $this->actingAs($reader)->get('/incidents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('hero.attention.alerts.value', 1));
        $permission = Permission::where('key', 'controlRoom.alerts.view')->sole();
        $reader->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        $this->actingAs($reader->fresh())->get('/incidents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('hero.attention.alerts.value', null));
    }

    public function test_register_counts_detail_tasks_export_and_download_share_the_record_boundary(): void
    {
        $site = Site::factory()->create();
        $other = Site::factory()->create();
        $viewer = $this->viewer($site, ['incidents.viewAny', 'incidents.update', 'incidents.export', 'reports.viewAny', 'sites.viewAll']);
        $own = $this->incident($site, ['description' => 'VISIBLE-HS-REPORT']);
        $foreign = $this->incident($other, ['description' => 'RESTRICTED-HS-REPORT']);
        $attachment = ClientIncidentAttachment::create([
            'incident_id' => $foreign->id,
            'uploaded_by' => $viewer->id,
            'disk' => 'local',
            'path' => 'test/hidden-incident.txt',
            'original_name' => 'hidden-incident.txt',
            'mime' => 'text/plain',
            'size' => 10,
        ]);
        $followup = IncidentFollowup::create(['client_incident_id' => $foreign->id, 'notes' => 'RESTRICTED-FOLLOWUP']);

        $this->actingAs($viewer)->get('/incidents?incident='.$foreign->id)
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('tabCounts.all', 1)->where('rows.data.0.id', $own->id)
            ->where('detail', null)->has('clients', 1)->where('clients.0.id', $own->client_id));
        $this->get('/incidents/'.$foreign->id)->assertForbidden();
        $this->get('/incidents/'.$foreign->id.'/attachments/'.$attachment->id.'/download')->assertForbidden();
        $this->assertFalse($viewer->can('update', $foreign));
        $this->assertSame(['incident-'.$own->id], array_column(app(ClientIncidentProvider::class)->authorizedTasks($viewer), 'id'));
        $this->assertSame([], app(IncidentFollowupProvider::class)->authorizedTasks($viewer, ['id' => $followup->id]));
        $csv = $this->get('/reports/incidents/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('VISIBLE-HS-REPORT', $csv);
        $this->assertStringNotContainsString('RESTRICTED-HS-REPORT', $csv);
    }

    public function test_recorded_site_does_not_move_with_a_person_and_assigned_access_requires_both_boundaries(): void
    {
        $oldSite = Site::factory()->create();
        $newSite = Site::factory()->create();
        $incident = $this->incident($oldSite);
        $incident->client->update(['site_id' => $newSite->id]);
        $oldWorker = $this->viewer($oldSite, ['incidents.viewAssigned']);
        $newWorker = $this->viewer($newSite, ['incidents.viewAssigned']);
        $incident->client->supportWorkers()->attach([$oldWorker->id, $newWorker->id]);
        $this->assertTrue($oldWorker->can('view', $incident));
        $this->assertFalse($newWorker->can('view', $incident));
        $this->assertSame([$incident->client_id], app(IncidentAccessService::class)->peopleForFilters($oldWorker)->pluck('id')->all());
        $incident->client->supportWorkers()->detach($oldWorker->id);
        $this->assertFalse($oldWorker->can('view', $incident));
    }

    public function test_legacy_review_then_shift_precedence_matches_list_filters_and_direct_access(): void
    {
        $recorded = Site::factory()->create();
        $current = Site::factory()->create();
        $viewer = $this->viewer($recorded);
        $incident = $this->incident($current, ['site_id' => null]);
        $review = HsEvent::factory()->forClientIncident($incident)->create(['site_id' => $recorded->id]);
        $incident->update(['hs_event_id' => $review->id]);
        $access = app(IncidentAccessService::class);
        $this->assertTrue($viewer->can('view', $incident));
        $this->assertFalse($access->forSiteIds(ClientIncident::query(), [$current->id])->whereKey($incident->id)->exists());

        $incident->update(['hs_event_id' => null, 'shift_id' => Shift::factory()->create(['client_id' => $incident->client_id, 'site_id' => $recorded->id])->id]);
        $this->assertTrue($viewer->can('view', $incident));
        $this->assertFalse($access->forSiteIds(ClientIncident::query(), [$current->id])->whereKey($incident->id)->exists());
    }

    public function test_unrelated_review_or_shift_cannot_supply_site_authority(): void
    {
        $site = Site::factory()->create();
        $other = Site::factory()->create();
        $viewer = $this->viewer($site);
        $incident = $this->incident($other, ['site_id' => null]);
        $unrelated = $this->incident($site);
        $review = HsEvent::factory()->forClientIncident($unrelated)->create(['site_id' => $site->id]);
        $incident->update(['hs_event_id' => $review->id]);
        $this->assertFalse($viewer->can('view', $incident));
        $incident->update(['hs_event_id' => null, 'shift_id' => Shift::factory()->create(['client_id' => $unrelated->client_id, 'site_id' => $site->id])->id]);
        $this->assertFalse($viewer->can('view', $incident));
    }

    public function test_explicit_global_health_safety_access_does_not_override_hr_privacy(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->viewer($site, ['incidents.viewAny', 'incidents.approve', 'healthSafety.viewAllSites']);
        $incident = $this->incident(Site::factory()->create());
        $this->assertTrue($viewer->can('view', $incident));
        $incident->forceFill(['is_hr_confidential' => true])->save();
        $this->assertFalse($viewer->can('view', $incident));
        $this->assertFalse($viewer->can('review', $incident));
        $this->assertSame([], app(ClientIncidentProvider::class)->authorizedTasks($viewer));
        $this->grant($viewer, ['hr.cases.view']);
        $this->assertTrue($viewer->can('view', $incident));
    }

    public function test_period_filters_use_half_open_nz_days_across_the_spring_clock_change(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->viewer($site, ['incidents.viewAny', 'incidents.export']);
        $before = $this->incident($site, ['occurred_at' => '2026-09-26 11:59:59', 'description' => 'BEFORE-NZ-DAY']);
        $start = $this->incident($site, ['occurred_at' => '2026-09-26 12:00:00', 'description' => 'START-NZ-DAY']);
        $end = $this->incident($site, ['occurred_at' => '2026-09-27 10:59:59', 'description' => 'END-NZ-DAY']);
        $after = $this->incident($site, ['occurred_at' => '2026-09-27 11:00:00', 'description' => 'AFTER-NZ-DAY']);
        $ids = IncidentOccurrencePeriod::apply(ClientIncident::query(), '2026-09-27', '2026-09-27')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$start->id, $end->id], $ids);
        $this->assertNotContains($before->id, $ids);
        $this->assertNotContains($after->id, $ids);
        $this->actingAs($viewer)->get('/incidents?from=2026-09-27&to=2026-09-27')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tabCounts.all', 2)->has('rows.data', 2));
        $csv = $this->get('/reports/incidents/export?from=2026-09-27&to=2026-09-27')->assertOk()->streamedContent();
        $this->assertStringContainsString('START-NZ-DAY', $csv);
        $this->assertStringContainsString('END-NZ-DAY', $csv);
        $this->assertStringNotContainsString('BEFORE-NZ-DAY', $csv);
        $this->assertStringNotContainsString('AFTER-NZ-DAY', $csv);
    }

    public function test_invalid_or_reversed_dates_are_rejected_and_a_to_only_filter_is_valid(): void
    {
        $viewer = $this->viewer(Site::factory()->create());
        $this->actingAs($viewer)->getJson('/incidents?from=2026-02-30')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson('/incidents?from=2026-10-07&to=2026-10-06')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->get('/incidents?to=2026-10-07')->assertOk();
    }

    public function test_incident_only_dashboard_destination_preserves_site_period_counts_and_source_authority(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->viewer($site);
        $inside = $this->incident($site, ['type' => 'injury', 'occurred_at' => '2026-09-10 00:00:00']);
        $this->incident($site, ['type' => 'near_miss', 'occurred_at' => '2026-09-10 00:00:00']);
        $this->incident($site, ['type' => 'injury', 'occurred_at' => '2026-09-02 00:00:00']);
        $this->incident(Site::factory()->create(), ['type' => 'injury', 'occurred_at' => '2026-09-10 00:00:00']);
        $this->incident($site, ['type' => 'injury', 'occurred_at' => '2026-09-10 00:00:00', 'is_hr_confidential' => true]);

        $query = http_build_query(['from' => '2026-09-03', 'to' => '2026-09-19', 'site_id' => $site->id, 'kind' => 'incidents', 'tab' => 'all']);
        $this->actingAs($viewer)->get('/incidents?'.$query)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.kind', 'incidents')->where('filters.site_id', $site->id)
            ->where('filters.from', '2026-09-03')->where('filters.to', '2026-09-19')
            ->where('tabCounts.all', 1)->where('tabCounts.near_misses', 0)
            ->has('rows.data', 1)->where('rows.data.0.id', $inside->id));
        $this->getJson('/incidents?kind=unsupported')->assertUnprocessable()->assertJsonValidationErrors('kind');
    }
}
