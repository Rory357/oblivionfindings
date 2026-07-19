<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspaceCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create(['organization_id' => 42]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());
    }

    #[DataProvider('workspaceProvider')]
    public function test_canonical_workspace_exposes_approved_local_tabs(
        string $route,
        string $slug,
        string $title,
        array $tabs,
    ): void {
        $this->actingAs($this->admin)
            ->get($route)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/category')
                ->where('workspace.slug', $slug)
                ->where('workspace.title', $title)
                ->where('workspace.canonicalHref', $route)
                ->where('workspace.activeTab', 'overview')
                ->where('workspace.tabs', fn ($actual) => collect($actual)->pluck('key')->all() === $tabs)
                ->has('workspace.summary')
                ->has('workspace.freshness')
                ->has('devices.data')
            );
    }

    public static function workspaceProvider(): array
    {
        return [
            'network and IT' => [
                '/security-devices/network-it',
                'network-it',
                'Network & IT',
                ['overview', 'map', 'devices', 'interfaces', 'services', 'traffic-capacity', 'configuration-firmware'],
            ],
            'security' => [
                '/security-devices/security',
                'security',
                'Security',
                ['overview', 'cctv', 'alarms', 'access-control', 'events'],
            ],
            'healthcare' => [
                '/security-devices/healthcare',
                'healthcare',
                'Healthcare',
                ['overview', 'client-devices', 'shared-site-devices', 'data-flow', 'calibration-maintenance'],
            ],
            'tracking' => [
                '/security-devices/tracking',
                'tracking',
                'Tracking',
                ['overview', 'personal-safety', 'fleet', 'assets', 'geofences', 'history'],
            ],
            'facilities and IoT' => [
                '/security-devices/facilities-iot',
                'facilities-iot',
                'Facilities & IoT',
                ['overview', 'environment', 'building-systems', 'utilities', 'automations', 'history'],
            ],
        ];
    }

    public function test_workspace_tab_is_url_driven_and_unknown_tabs_fall_back_to_overview(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=access-control')
            ->assertInertia(fn ($page) => $page
                ->where('workspace.activeTab', 'access-control')
                ->where('workspace.activeTabState', 'available')
            );

        $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=not-a-real-tab')
            ->assertInertia(fn ($page) => $page
                ->where('workspace.activeTab', 'overview')
            );
    }

    public function test_network_runtime_tabs_are_available_with_honest_collection_gaps(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices/network-it?tab=traffic-capacity')
            ->assertInertia(fn ($page) => $page
                ->where('workspace.activeTab', 'traffic-capacity')
                ->where('workspace.activeTabState', 'available')
                ->where('workspace.tabs.5.state', 'available')
                ->where('workspace.tabs.5.stateLabel', 'Available')
                ->where('networkItWorkspace.activeTab.traffic', [])
                ->where('networkItWorkspace.boundary.title', 'Native monitoring, honest evidence')
            );
    }

    public function test_device_context_remains_in_the_canonical_workspace_payload(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=cctv&device_id=44&search=camera')
            ->assertInertia(fn ($page) => $page
                ->where('workspace.activeTab', 'cctv')
                ->where('filters.device_id', '44')
                ->where('filters.search', 'camera')
            );
    }

    public function test_legacy_redirect_still_requires_device_view_permission(): void
    {
        $viewer = User::factory()->create(['organization_id' => 42]);
        $overrides = Permission::query()
            ->whereIn('key', ['securityDevices.viewAny', 'securityDevices.devices.view'])
            ->get()
            ->mapWithKeys(fn (Permission $permission) => [
                $permission->id => [
                    'allowed' => $permission->key === 'securityDevices.viewAny',
                ],
            ])
            ->all();
        $viewer->permissionOverrides()->sync($overrides);

        $this->actingAs($viewer)
            ->get('/security-devices/cctv')
            ->assertForbidden();
    }

    public function test_shared_workspace_query_is_tenant_scoped_and_preserves_filters(): void
    {
        Device::factory()->itInfrastructure()->create([
            'tenant_id' => 42,
            'name' => 'Tenant edge',
            'status' => 'offline',
        ]);
        Device::factory()->itInfrastructure()->create([
            'tenant_id' => 42,
            'name' => 'Tenant healthy switch',
            'status' => 'active',
        ]);
        Device::factory()->itInfrastructure()->create([
            'tenant_id' => 77,
            'name' => 'Foreign edge',
            'status' => 'offline',
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/network-it?tab=devices&status=offline')
            ->assertInertia(fn ($page) => $page
                ->where('workspace.activeTab', 'devices')
                ->where('workspace.summary.devices', 2)
                ->has('devices.data', 1)
                ->where('devices.data.0.name', 'Tenant edge')
                ->where('filters.status', 'offline')
            );
    }

    #[DataProvider('legacyRouteProvider')]
    public function test_legacy_workspace_paths_redirect_without_losing_filters_or_device_context(
        string $legacyRoute,
        string $canonicalRoute,
        string $tab,
    ): void {
        $response = $this->actingAs($this->admin)
            ->get($legacyRoute.'?search=edge%20gateway&device_id=44&status=offline');

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertSame($canonicalRoute, parse_url($location, PHP_URL_PATH));

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('edge gateway', $query['search'] ?? null);
        $this->assertSame('44', $query['device_id'] ?? null);
        $this->assertSame('offline', $query['status'] ?? null);
        $this->assertSame($tab, $query['tab'] ?? null);
    }

    public static function legacyRouteProvider(): array
    {
        return [
            'CCTV' => ['/security-devices/cctv', '/security-devices/security', 'cctv'],
            'alarms' => ['/security-devices/alarms', '/security-devices/security', 'alarms'],
            'access control' => ['/security-devices/access-control', '/security-devices/security', 'access-control'],
            'tracking devices' => ['/security-devices/tracking-devices', '/security-devices/tracking', 'overview'],
            'healthcare devices' => ['/security-devices/smart-iot-healthcare', '/security-devices/healthcare', 'overview'],
            'IT infrastructure' => ['/security-devices/it-infrastructure', '/security-devices/network-it', 'devices'],
            'facilities' => ['/security-devices/facilities', '/security-devices/facilities-iot', 'overview'],
        ];
    }
}
