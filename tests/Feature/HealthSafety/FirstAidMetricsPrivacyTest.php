<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\FirstAidRecord;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\FirstAidMetricsService;
use App\Services\HealthSafety\HsAnalyticsService;
use App\Services\HealthSafety\HsKpiService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FirstAidMetricsPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-08T01:00:00Z'));
        $this->site = Site::factory()->create();
        $this->viewer = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $this->viewer->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'start_date' => '2020-01-01', 'end_date' => null, 'is_active' => true]);
        foreach (['hazards.view' => true, 'hazards.create' => false, 'hazards.manage' => false, 'healthSafety.viewAllSites' => false, 'clients.viewAny' => false, 'clients.viewAssigned' => true, 'clinical.accessAllSites' => false, 'sites.viewAll' => false] as $key => $allowed) {
            $this->grant($key, $allowed);
        }
    }

    public function test_actual_dashboard_analytics_register_and_csv_share_denied_primary_client_boundary(): void
    {
        $staff = $this->source(['treated_person_name' => 'Visible recorded staff']);
        $before = $this->analytics();
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $private = $this->source(['treated_person_type' => 'client', 'client_id' => $client->id, 'treated_person_name' => 'Private subject marker', 'injury_illness_type' => 'fracture', 'treatment_outcome' => 'sent_to_hospital', 'ambulance_called' => true, 'treatment_given' => 'Private treatment marker']);
        $this->actingAs($this->viewer)->get('/health-safety')->assertOk()->assertInertia(fn (Assert $page) => $page->where('first_aid', ['treatments' => 1, 'ambulance' => 0, 'hospital' => 0]));
        $after = $this->analytics();
        $this->assertSame(['by_type' => [['type' => 'cut', 'count' => 1]], 'by_outcome' => [['outcome' => 'returned_to_activity', 'count' => 1]],
            'source_access' => 'readable', 'scope' => 'current_readable_sources'], $after['first_aid_data']);
        $this->assertSame($before['hero_stats'], $after['hero_stats']);
        $this->getJson('/health-safety/first-aid?period=all')->assertOk()->assertJsonPath('records.total', 1)->assertJsonPath('records.data.0.id', $staff->id);
        $csv = $this->get('/health-safety/first-aid/export?period=all')->assertOk()->streamedContent();
        $this->assertStringContainsString('Visible recorded staff', $csv);
        $this->assertStringNotContainsString('Private subject marker', $csv);
        $this->assertStringNotContainsString('Private treatment marker', $csv);
        $this->getJson('/health-safety/first-aid/'.$private->id)->assertNotFound();
    }

    public function test_exact_client_read_retains_counts_but_foreign_archived_malformed_and_outside_window_sources_do_not_contribute(): void
    {
        $this->source();
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $client->supportWorkers()->attach($this->viewer->id);
        $this->source(['treated_person_type' => 'client', 'client_id' => $client->id, 'treated_person_name' => 'Authorized clinical marker', 'injury_illness_type' => 'fracture', 'treatment_outcome' => 'sent_to_hospital', 'ambulance_called' => true]);
        $foreign = Site::factory()->create();
        $foreignClient = Client::factory()->create(['site_id' => $foreign->id]);
        $foreignClient->supportWorkers()->attach($this->viewer->id);
        $this->source(['site_id' => $foreign->id, 'treated_person_type' => 'client', 'client_id' => $foreignClient->id]);
        $this->source(['treated_person_type' => 'staff', 'client_id' => $client->id]);
        $this->source(['treated_person_type' => 'client', 'client_id' => null]);
        $this->source(['treated_person_type' => 'client', 'client_id' => $foreignClient->id]);
        $this->source(['treatment_date' => now()->subDays(40)]);
        $this->source()->delete();
        $metrics = $this->metrics();
        $this->assertSame('readable', $metrics['source_access']);
        $this->assertSame('current_readable_sources', $metrics['scope']);
        $this->assertSame(['treatments' => 2, 'ambulance' => 1, 'hospital' => 1], $metrics['activity']);
        $this->assertSame(['cut' => 1, 'fracture' => 1], array_column($metrics['by_type'], 'count', 'type'));
        $this->assertSame(['returned_to_activity' => 1, 'sent_to_hospital' => 1], array_column($metrics['by_outcome'], 'count', 'outcome'));
        $this->actingAs($this->viewer)->get('/health-safety')->assertOk()->assertInertia(fn (Assert $page) => $page->where('first_aid', $metrics['activity']));
        $this->assertSame($metrics['by_type'], $this->analytics()['first_aid_data']['by_type']);
        $this->assertStringContainsString(trim($client->first_name.' '.$client->last_name), $this->get('/health-safety/first-aid/export?period=30d')->assertOk()->streamedContent());
    }

    public function test_cached_client_permission_is_rechecked_by_services_actual_metrics_and_export(): void
    {
        $this->source();
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $client->supportWorkers()->attach($this->viewer->id);
        $this->source(['treated_person_type' => 'client', 'client_id' => $client->id, 'treated_person_name' => 'Revoked private subject', 'injury_illness_type' => 'burn']);
        $this->viewer->load('permissionOverrides', 'hrEmployeeProfile');
        $this->assertTrue($this->viewer->canDo('clients.viewAssigned'));
        $this->assertSame(2, $this->metrics()['activity']['treatments']);
        $this->grant('clients.viewAssigned', false);
        $this->assertSame(1, $this->metrics()['activity']['treatments']);
        $this->actingAs($this->viewer)->get('/health-safety')->assertOk()->assertInertia(fn (Assert $page) => $page->where('first_aid.treatments', 1));
        $this->assertSame([['type' => 'cut', 'count' => 1]], $this->analytics()['first_aid_data']['by_type']);
        $this->assertStringNotContainsString('Revoked private subject', $this->get('/health-safety/first-aid/export?period=all')->assertOk()->streamedContent());
    }

    public function test_current_assignment_and_exact_client_site_are_rechecked_with_reused_service_and_viewer(): void
    {
        $this->source();
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $client->supportWorkers()->attach($this->viewer->id);
        $this->source(['treated_person_type' => 'client', 'client_id' => $client->id]);
        $this->viewer->load('assignedClients', 'hrEmployeeProfile', 'permissionOverrides');
        $this->assertSame(2, $this->metrics()['activity']['treatments']);
        $client->supportWorkers()->detach($this->viewer->id);
        $this->assertSame(1, $this->metrics()['activity']['treatments']);
        $client->supportWorkers()->attach($this->viewer->id);
        $this->assertSame(2, $this->metrics()['activity']['treatments']);
        $client->forceFill(['site_id' => Site::factory()->create()->id])->saveQuietly();
        $this->assertSame(1, $this->metrics()['activity']['treatments']);
    }

    public function test_cached_site_access_and_source_site_activity_cannot_survive_current_revocation(): void
    {
        $this->source();
        $this->viewer->load('hrEmployeeProfile', 'permissionOverrides');
        $this->assertSame(1, $this->metrics()['activity']['treatments']);
        $this->viewer->hrEmployeeProfile->forceFill(['primary_site_id' => Site::factory()->create()->id])->save();
        $outsideCurrentSites = $this->metrics($this->site->id);
        $this->assertSame(0, $outsideCurrentSites['activity']['treatments']);
        $this->assertSame('readable', $outsideCurrentSites['source_access']);
        $this->viewer->hrEmployeeProfile->forceFill(['primary_site_id' => $this->site->id])->save();
        $this->assertSame(1, $this->metrics()['activity']['treatments']);
        $this->site->forceFill(['is_active' => false])->saveQuietly();
        $inactiveSite = $this->metrics();
        $this->assertSame(['treatments' => null, 'ambulance' => null, 'hospital' => null], $inactiveSite['activity']);
        $this->assertSame('restricted', $inactiveSite['source_access']);
        $this->assertNull($inactiveSite['by_type']);
        $this->assertNull($inactiveSite['by_outcome']);
    }

    public function test_inactive_current_staff_and_explicit_source_read_denial_fail_closed_despite_cached_profile(): void
    {
        $source = $this->source();
        $this->viewer->load('hrEmployeeProfile', 'permissionOverrides');
        $this->assertSame(1, $this->metrics()['activity']['treatments']);
        $this->viewer->hrEmployeeProfile->forceFill(['is_active' => false])->save();
        $inactiveStaff = $this->metrics();
        $this->assertSame(['treatments' => null, 'ambulance' => null, 'hospital' => null], $inactiveStaff['activity']);
        $this->assertSame('restricted', $inactiveStaff['source_access']);
        $this->assertNull($inactiveStaff['by_type']);
        $this->assertNull($inactiveStaff['by_outcome']);
        $this->viewer->hrEmployeeProfile->forceFill(['is_active' => true])->save();
        $this->grant('hazards.view', false);
        $explicitDenial = $this->metrics();
        $this->assertSame(['treatments' => null, 'ambulance' => null, 'hospital' => null], $explicitDenial['activity']);
        $this->assertNull($explicitDenial['by_type']);
        $this->assertNull($explicitDenial['by_outcome']);
        $this->assertSame('restricted', $explicitDenial['source_access']);
        $this->assertSame('current_readable_sources', $explicitDenial['scope']);

        $this->grant('hazards.view', true);
        $dispatcher = Model::getEventDispatcher();
        Model::setEventDispatcher(clone $dispatcher);
        $state = (object) ['armed' => true, 'fired' => false];
        FirstAidRecord::retrieved(function (FirstAidRecord $row) use ($source, $state): void {
            if (! $state->armed || (int) $row->id !== (int) $source->id || ! array_key_exists('treatment_outcome', $row->getAttributes())) { return; }
            $state->armed = false; $state->fired = true;
            DB::table('first_aid_records')->where('id', $source->id)->update(['treatment_outcome' => 'sent_to_hospital']);
        });
        try {
            $unavailable = $this->metrics();
            $this->assertTrue($state->fired, 'Change the actual admitted metric row after SQL and before the original final proof.');
            $this->assertSame(['treatments' => null, 'ambulance' => null, 'hospital' => null], $unavailable['activity']);
            $this->assertSame('unavailable', $unavailable['source_access']);
            $this->assertNull($unavailable['by_type']);
            $this->assertNull($unavailable['by_outcome']);
            $this->assertSame('current_readable_sources', $unavailable['scope']);
        } finally {
            $state->armed = false;
            Model::setEventDispatcher($dispatcher);
        }
        $recovered = $this->metrics();
        $this->assertSame('readable', $recovered['source_access']);
        $this->assertSame(['treatments' => 1, 'ambulance' => 0, 'hospital' => 1], $recovered['activity']);
    }

    public function test_actorless_public_services_fail_closed_without_implicit_request_user_or_new_analytics_export(): void
    {
        $this->source(['ambulance_called' => true, 'treatment_outcome' => 'sent_to_hospital']);
        $this->actingAs($this->viewer);
        $this->assertSame(['treatments' => null, 'ambulance' => null, 'hospital' => null], app(HsKpiService::class)->firstAidActivity());
        $actorless = app(FirstAidMetricsService::class)->forViewer(null, now()->subDays(30), now(), $this->site->id);
        $this->assertSame(['treatments' => null, 'ambulance' => null, 'hospital' => null], $actorless['activity']);
        $this->assertSame('restricted', $actorless['source_access']);
        $this->assertSame('current_readable_sources', $actorless['scope']);
        $this->assertNull($actorless['by_type']);
        $this->assertNull($actorless['by_outcome']);
        $analytics = app(HsAnalyticsService::class);
        $this->assertSame(['by_type' => null, 'by_outcome' => null, 'source_access' => 'restricted', 'scope' => 'current_readable_sources'],
            $analytics->build($this->site->id, now()->subDays(30), now(), 'manager')['first_aid_data']);
        $this->assertSame('incidents', $analytics->exportRows('first_aid', $this->site->id, now()->subDays(30), now())['name']);
    }

    public function test_requested_site_and_exact_time_range_are_intersections_of_current_source_population(): void
    {
        $from = now()->subDays(2);
        $this->source(['treatment_date' => $from]);
        $this->source(['treatment_date' => now()]);
        $this->source(['treatment_date' => $from->copy()->subSecond()]);
        $this->source(['treatment_date' => now()->addSecond()]);
        $service = app(FirstAidMetricsService::class);
        $this->assertSame(2, $service->forViewer($this->viewer, $from, now(), $this->site->id)['activity']['treatments']);
        $emptySelection = $service->forViewer($this->viewer, $from, now(), []);
        $this->assertSame(['treatments' => 0, 'ambulance' => 0, 'hospital' => 0], $emptySelection['activity']);
        $this->assertSame([], $emptySelection['by_type']);
        $this->assertSame([], $emptySelection['by_outcome']);
        $this->assertSame('readable', $emptySelection['source_access']);
        $this->assertSame('current_readable_sources', $emptySelection['scope']);
        $foreignSelection = $service->forViewer($this->viewer, $from, now(), Site::factory()->create()->id);
        $this->assertSame(0, $foreignSelection['activity']['treatments']);
        $this->assertSame('readable', $foreignSelection['source_access']);
        $this->assertSame(2, $service->forViewer($this->viewer, $from, now(), null)['activity']['treatments']);
    }

    private function analytics(): array
    {
        return $this->actingAs($this->viewer)->get('/health-safety/analytics?period=30d')->assertOk()->inertiaProps();
    }

    private function metrics(int|array|null $sites = null): array
    {
        return app(FirstAidMetricsService::class)->forViewer($this->viewer, now()->subDays(30), now(), $sites);
    }

    private function source(array $fields = []): FirstAidRecord
    {
        return FirstAidRecord::factory()->createQuietly(['site_id' => $this->site->id, 'first_aider_id' => $this->viewer->id, 'created_by' => $this->viewer->id, 'treated_person_type' => 'staff', 'treated_person_id' => null, 'client_id' => null, 'treatment_date' => now()->subDay(), 'injury_illness_type' => 'cut', 'treatment_outcome' => 'returned_to_activity', 'ambulance_called' => false, ...$fields]);
    }

    private function grant(string $key, bool $allowed): void
    {
        $this->viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => $allowed]]);
    }
}
