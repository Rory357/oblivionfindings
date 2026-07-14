<?php

namespace Tests\Feature\ControlRoom;

use App\Models\Client;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\MedicationError;
use App\Models\Role;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomTenantSurfaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_tenant_report_manager_dashboard_does_not_expose_foreign_reports_or_global_shift(): void
    {
        $manager = $this->adminForOrganization(1);
        $localSite = Site::factory()->create(['tenant_id' => 1]);
        $foreignSite = Site::factory()->create(['tenant_id' => 202]);

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
            'name' => 'Installation-wide night shift',
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
                ->where('active_shift', null)
                ->has('sites', 1)
                ->where('sites.0.id', $localSite->id)
            );
    }

    public function test_dashboard_site_filter_uses_alert_site_before_client_fallback(): void
    {
        $manager = $this->adminForOrganization(1);
        $selectedSite = Site::factory()->create(['tenant_id' => 1]);
        $otherSite = Site::factory()->create(['tenant_id' => 1]);
        $selectedClient = Client::factory()->create([
            'organization_id' => 1,
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

    public function test_tenant_report_manager_stats_hide_global_shifts_and_foreign_signal_sources(): void
    {
        $manager = $this->adminForOrganization(1);
        $localSite = Site::factory()->create(['tenant_id' => 1]);
        $foreignSite = Site::factory()->create(['tenant_id' => 202]);
        $localSource = $this->signalSource('Tenant source', 'tenant-source', 19);
        $foreignSource = $this->signalSource('Foreign source', 'foreign-source', 23);

        $this->signalForSite($localSource, $localSite, 'tenant.signal');
        $this->signalForSite($foreignSource, $foreignSite, 'foreign.signal');
        Shift::query()->create([
            'name' => 'Global historical shift',
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

    public function test_tenant_report_manager_incident_feed_excludes_foreign_medication_and_safeguarding_records(): void
    {
        $manager = $this->adminForOrganization(1);
        $localSite = Site::factory()->create(['tenant_id' => 1]);
        $foreignSite = Site::factory()->create(['tenant_id' => 202]);
        $localClient = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $localSite->id,
        ]);
        $foreignClient = Client::factory()->create([
            'organization_id' => 202,
            'site_id' => $foreignSite->id,
        ]);

        $localMedicationError = $this->medicationError($localClient, $manager, 'Local medication error');
        $this->medicationError($foreignClient, $manager, 'Foreign medication error');
        $localSafeguarding = $this->safeguardingConcern(
            $localSite,
            $manager,
            'Local safeguarding concern',
        );
        $this->safeguardingConcern(
            $foreignSite,
            $manager,
            'Foreign safeguarding concern',
        );

        $expectedIds = ['me_'.$localMedicationError->id, 'sg_'.$localSafeguarding->id];

        $this->actingAs($manager)
            ->get('/control-room/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('incidents.total', 2)
                ->where('incidents.data', fn ($incidents) => collect($incidents)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === collect($expectedIds)->sort()->values()->all())
                ->has('sites', 1)
                ->where('sites.0.id', $localSite->id)
                ->has('clients', 1)
                ->where('clients.0.id', $localClient->id)
            );
    }

    public function test_only_explicit_platform_admin_can_see_installation_wide_shift_snapshots(): void
    {
        $platformAdmin = $this->adminForOrganization(null);
        Shift::query()->create([
            'name' => 'Platform active shift',
            'starts_at' => now()->subHour(),
            'status' => 'active',
            'shift_lead_user_id' => $platformAdmin->id,
        ]);
        Shift::query()->create([
            'name' => 'Platform completed shift',
            'starts_at' => now()->subHours(10),
            'ends_at' => now()->subHours(2),
            'status' => 'completed',
            'shift_lead_user_id' => $platformAdmin->id,
        ]);

        $this->actingAs($platformAdmin)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('active_shift.name', 'Platform active shift')
            );

        $this->actingAs($platformAdmin)
            ->get('/control-room/stats')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('shift_comparison', 1)
                ->where('shift_comparison.0.name', 'Platform completed shift')
            );
    }

    private function adminForOrganization(?int $organizationId): User
    {
        $admin = User::factory()->create([
            'organization_id' => $organizationId,
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        return $admin;
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
