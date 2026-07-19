<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityDevicesNavigationRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $auditor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::forceCreate([
            'name' => 'Security Devices Admin',
            'email' => 'security-devices-admin@example.test',
            'email_verified_at' => now(),
            'approved_at' => now(),
            'organization_id' => 1,
            'password' => 'not-used-by-this-feature-test',
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());

        $this->auditor = User::forceCreate([
            'name' => 'Security Devices Auditor',
            'email' => 'security-devices-auditor@example.test',
            'email_verified_at' => now(),
            'approved_at' => now(),
            'organization_id' => 1,
            'password' => 'not-used-by-this-feature-test',
        ]);
        $this->auditor->roles()->attach(Role::where('name', 'auditor')->firstOrFail());
    }

    #[DataProvider('workspaceRouteProvider')]
    public function test_grouped_navigation_destinations_resolve_to_production_backed_pages(
        string $route,
        string $component,
    ): void {
        $this->actingAs($this->admin)
            ->get($route)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component($component));
    }

    public static function workspaceRouteProvider(): array
    {
        return [
            'network and IT' => ['/security-devices/network-it', 'security-devices/category'],
            'security' => ['/security-devices/security', 'security-devices/category'],
            'healthcare' => ['/security-devices/healthcare', 'security-devices/category'],
            'tracking' => ['/security-devices/tracking', 'security-devices/category'],
            'facilities and IoT' => ['/security-devices/facilities-iot', 'security-devices/category'],
            'monitoring' => ['/security-devices/monitoring', 'security-devices/alerts-events'],
            'maintenance' => ['/security-devices/maintenance', 'security-devices/maintenance-health'],
            'discovery' => ['/security-devices/discovery', 'security-devices/discovery'],
            'settings' => ['/security-devices/settings', 'security-devices/settings'],
        ];
    }

    #[DataProvider('canonicalWorkspaceProvider')]
    public function test_canonical_workspace_routes_use_the_approved_plain_language_title(
        string $route,
        string $slug,
        string $title,
    ): void {
        $this->actingAs($this->admin)
            ->get($route)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/category')
                ->where('pageConfig.slug', $slug)
                ->where('pageConfig.title', $title)
            );
    }

    public static function canonicalWorkspaceProvider(): array
    {
        return [
            'network and IT' => ['/security-devices/network-it', 'network-it', 'Network & IT'],
            'security' => ['/security-devices/security', 'security', 'Security'],
            'healthcare' => ['/security-devices/healthcare', 'healthcare', 'Healthcare'],
            'tracking' => ['/security-devices/tracking', 'tracking', 'Tracking'],
            'facilities and IoT' => ['/security-devices/facilities-iot', 'facilities-iot', 'Facilities & IoT'],
        ];
    }

    #[DataProvider('canonicalOperationsProvider')]
    public function test_canonical_operations_routes_use_the_approved_plain_language_title(
        string $route,
        string $component,
        string $title,
    ): void {
        $this->actingAs($this->admin)
            ->get($route)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component($component)
                ->where('pageMeta.title', $title)
            );
    }

    public static function canonicalOperationsProvider(): array
    {
        return [
            'monitoring' => ['/security-devices/monitoring', 'security-devices/alerts-events', 'Monitoring'],
            'maintenance' => ['/security-devices/maintenance', 'security-devices/maintenance-health', 'Maintenance'],
        ];
    }

    public function test_discovery_summary_reports_stale_collectors_from_real_heartbeat_times(): void
    {
        MonitoringCollector::factory()->create([
            'tenant_id' => 1,
            'name' => 'Online collector',
            'status' => 'online',
            'last_seen_at' => now()->subMinute(),
        ]);
        MonitoringCollector::factory()->create([
            'tenant_id' => 1,
            'name' => 'Stale collector',
            'status' => 'offline',
            'last_seen_at' => now()->subMinutes(10),
        ]);
        MonitoringCollector::factory()->create([
            'tenant_id' => 2,
            'name' => 'Other tenant collector',
            'status' => 'offline',
            'last_seen_at' => null,
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/discovery')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/discovery')
                ->has('collectors', 2)
                ->where('summary.collectors', 2)
                ->where('summary.online', 1)
                ->where('summary.stale', 1)
            );
    }

    public function test_settings_only_lists_areas_the_user_can_open(): void
    {
        $this->actingAs($this->auditor)
            ->get('/security-devices/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/settings')
                ->has('areas', 1)
                ->where('areas.0.title', 'Reports & exports')
                ->where('areas.0.href', '/security-devices/reports')
            );
    }

    public function test_sites_destination_is_tenant_scoped_and_does_not_require_sites_module_access(): void
    {
        Site::factory()->create(['tenant_id' => 1, 'name' => 'Koru House']);
        Site::factory()->create(['tenant_id' => 2, 'name' => 'Other tenant site']);

        $this->actingAs($this->admin)
            ->get('/security-devices/sites')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/sites/index')
                ->has('sites', 1)
                ->where('sites.0.name', 'Koru House')
            );
    }

    public function test_site_technology_detail_is_tenant_scoped(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1, 'name' => 'Koru House']);
        $otherTenantSite = Site::factory()->create(['tenant_id' => 2]);

        $this->actingAs($this->admin)
            ->get("/security-devices/sites/{$site->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/sites/show')
                ->where('site.name', 'Koru House')
                ->has('devices')
                ->has('summary')
            );

        $this->actingAs($this->admin)
            ->get("/security-devices/sites/{$otherTenantSite->id}")
            ->assertNotFound();
    }
}
