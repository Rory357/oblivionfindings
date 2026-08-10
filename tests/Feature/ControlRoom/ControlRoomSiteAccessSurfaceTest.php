<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\MedicationError;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomSiteAccessSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_site_scoped_manager_dashboard_excludes_other_site_reports_but_includes_the_application_wide_shift(): void
    {
        $localSite = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $manager = $this->siteScopedManager($localSite);

        ControlRoomAlert::factory()->open()->create([
            'site_id' => $localSite->id,
            'source' => 'fleet',
            'triggered_at' => now()->subMinute(),
        ]);
        ControlRoomAlert::factory()->open()->create([
            'site_id' => $foreignSite->id,
            'source' => 'compliance',
            'triggered_at' => now()->subMinute(),
        ]);
        Shift::query()->create([
            'name' => 'Application-wide night shift',
            'starts_at' => now()->subHour(),
            'status' => 'active',
            'shift_lead_user_id' => $manager->id,
        ]);

        $this->actingAs($manager)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total', 1)
                ->where('by_source.fleet', 1)
                ->missing('by_source.compliance')
                ->where('active_shift.name', 'Application-wide night shift')
                ->has('sites', 1)
                ->where('sites.0.id', $localSite->id)
            );
    }

    public function test_dashboard_site_filter_uses_alert_site_before_client_fallback(): void
    {
        $selectedSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $manager = $this->siteScopedManager($selectedSite);
        $selectedClient = Client::factory()->create([
            'site_id' => $selectedSite->id,
        ]);

        $directMatch = ControlRoomAlert::factory()->open()->create([
            'site_id' => $selectedSite->id,
            'client_id' => null,
        ]);
        $clientFallback = ControlRoomAlert::factory()->open()->create([
            'site_id' => null,
            'client_id' => $selectedClient->id,
        ]);
        ControlRoomAlert::factory()->open()->create([
            'site_id' => $otherSite->id,
            'client_id' => $selectedClient->id,
        ]);

        $this->actingAs($manager)
            ->get('/control-room?site_id='.$selectedSite->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total', 2)
                ->where('alerts.meta.total', 2)
                ->where('alerts.data', fn ($alerts) => collect($alerts)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === collect([$directMatch->id, $clientFallback->id])
                    ->sort()
                    ->values()
                    ->all())
            );
    }

    public function test_site_scoped_manager_stats_hide_application_wide_shifts_and_other_site_signal_sources(): void
    {
        $localSite = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $manager = $this->siteScopedManager($localSite);
        $localSource = $this->signalSource('Local Site source', 'local-site-source', 19);
        $foreignSource = $this->signalSource('Other Site source', 'other-site-source', 23);

        $this->signalForSite($localSource, $localSite, 'local.signal');
        $this->signalForSite($foreignSource, $foreignSite, 'other.signal');
        Shift::query()->create([
            'name' => 'Application-wide historical shift',
            'starts_at' => now()->subHours(9),
            'ends_at' => now()->subHour(),
            'status' => 'completed',
            'shift_lead_user_id' => $manager->id,
        ]);

        $this->actingAs($manager)
            ->get('/control-room/stats')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('shift_comparison', 0)
                ->has('signal_sources', 1)
                ->where('signal_sources.0.name', $localSource->name)
                ->where('signal_sources.0.signal_count_24h', 1)
            );
    }

    public function test_site_scoped_manager_incident_tracker_uses_only_the_accessible_canonical_journey(): void
    {
        $localSite = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $manager = $this->siteScopedManager($localSite);
        $localClient = Client::factory()->create([
            'site_id' => $localSite->id,
        ]);
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
        ]);

        $this->medicationError($localClient, $manager, 'Local medication error');
        $this->medicationError($foreignClient, $manager, 'Foreign medication error');
        $this->safeguardingConcern(
            $localSite,
            $manager,
            'Local safeguarding concern',
        );
        $this->safeguardingConcern(
            $foreignSite,
            $manager,
            'Foreign safeguarding concern',
        );

        $localAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $localSite->id,
            'client_id' => $localClient->id,
        ]);
        ControlRoomAlert::factory()->open()->create([
            'site_id' => $foreignSite->id,
            'client_id' => $foreignClient->id,
        ]);

        $this->actingAs($manager)
            ->get('/control-room/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('journeys.meta.total', 1)
                ->where('journeys.data.0.alert.id', $localAlert->id)
                ->where('journeys.data.0.person.id', $localClient->id)
                ->where('journeys.data.0.site.id', $localSite->id)
                ->has('sites', 1)
                ->where('sites.0.id', $localSite->id)
                ->missing('incidents')
                ->missing('clients')
            );
    }

    public function test_explicit_reports_permission_can_see_application_wide_shift_snapshots(): void
    {
        $homeSite = Site::factory()->create();
        $applicationViewer = $this->siteScopedManager($homeSite);
        $reportsPermission = Permission::query()->where('key', 'reports.viewAny')->firstOrFail();
        $applicationViewer->permissionOverrides()->attach($reportsPermission->id, ['allowed' => true]);

        Shift::query()->create([
            'name' => 'Application active shift',
            'starts_at' => now()->subHour(),
            'status' => 'active',
            'shift_lead_user_id' => $applicationViewer->id,
        ]);
        Shift::query()->create([
            'name' => 'Application completed shift',
            'starts_at' => now()->subHours(10),
            'ends_at' => now()->subHours(2),
            'status' => 'completed',
            'shift_lead_user_id' => $applicationViewer->id,
        ]);

        $this->actingAs($applicationViewer)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('active_shift.name', 'Application active shift')
            );

        $this->actingAs($applicationViewer)
            ->get('/control-room/stats')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('shift_comparison', 1)
                ->where('shift_comparison.0.name', 'Application completed shift')
            );
    }

    private function siteScopedManager(Site $site): User
    {
        $manager = User::factory()->create([
            'role' => 'coordinator',
            'approved_at' => now(),
        ]);
        $manager->roles()->attach(Role::query()->where('name', 'coordinator')->firstOrFail());
        $this->scopeUserToSite($manager, $site);

        return $manager;
    }

    private function scopeUserToSite(User $user, Site $site): void
    {
        $profile = HrEmployeeProfile::factory()->make(['user_id' => $user->id]);
        $profile->fill([
            'employee_number' => 'EMP-CR-SURFACE-'.$user->id,
            'work_email' => $user->email,
            'position_title' => 'Control Room Coordinator',
            'position_role' => 'coordinator',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ])->save();
    }

    private function signalSource(string $name, string $slug, int $globalCount): SignalSource
    {
        return SignalSource::query()->create([
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
            'last_heartbeat_at' => now(),
            'signal_count_24h' => $globalCount,
        ]);
    }

    private function signalForSite(SignalSource $source, Site $site, string $type): Signal
    {
        return Signal::query()->create([
            'signal_source_id' => $source->id,
            'signal_type_code' => $type,
            'site_id' => $site->id,
            'severity_hint' => 'medium',
            'occurred_at' => now()->subMinute(),
            'status' => 'processed',
        ]);
    }

    private function medicationError(Client $client, User $reporter, string $description): MedicationError
    {
        return MedicationError::query()->create([
            'client_id' => $client->id,
            'error_type' => 'omission',
            'severity' => 'minor',
            'description' => $description,
            'reported_by' => $reporter->id,
            'reported_at' => now()->subMinute(),
            'status' => 'reported',
        ]);
    }

    private function safeguardingConcern(
        Site $site,
        User $reporter,
        string $description,
    ): SafeguardingConcern {
        return SafeguardingConcern::withoutEvents(fn () => SafeguardingConcern::factory()->create([
            'reference_number' => 'SG-ISO-'.$site->id,
            'site_id' => $site->id,
            'reported_by_user_id' => $reporter->id,
            'description' => $description,
            'is_sensitive' => false,
            'occurred_at' => now()->subMinute(),
        ]));
    }
}
