<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CategoryPageControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $noPerms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->noPerms = User::factory()->create();
    }

    // ── Authentication & Authorization ────────────────────────────

    #[DataProvider('categoryRouteProvider')]
    public function test_category_page_requires_authentication(string $route): void
    {
        $this->get($route)->assertRedirect('/login');
    }

    #[DataProvider('categoryRouteProvider')]
    public function test_category_page_requires_permission(string $route): void
    {
        $this->actingAs($this->noPerms)
            ->get($route)
            ->assertForbidden();
    }

    #[DataProvider('categoryRouteProvider')]
    public function test_legacy_category_page_redirects_with_permission(string $route): void
    {
        $this->actingAs($this->admin)
            ->get($route)
            ->assertRedirect();
    }

    public static function categoryRouteProvider(): array
    {
        return [
            'alarms' => ['/security-devices/alarms'],
            'cctv' => ['/security-devices/cctv'],
            'access-control' => ['/security-devices/access-control'],
            'tracking-devices' => ['/security-devices/tracking-devices'],
            'smart-iot-healthcare' => ['/security-devices/smart-iot-healthcare'],
            'it-infrastructure' => ['/security-devices/it-infrastructure'],
            'facilities' => ['/security-devices/facilities'],
        ];
    }

    // ── Correct Inertia component ─────────────────────────────────

    #[DataProvider('categoryRouteProvider')]
    public function test_legacy_category_page_redirects_to_a_canonical_workspace(string $route): void
    {
        $this->actingAs($this->admin)
            ->get($route)
            ->assertRedirect();
    }

    // ── Domain scoping ────────────────────────────────────────────

    public function test_alarms_page_only_shows_security_alarm_devices(): void
    {
        Device::factory()->create(['domain' => 'security', 'category' => 'alarm', 'name' => 'Panel A']);
        Device::factory()->create(['domain' => 'security', 'category' => 'perimeter', 'name' => 'Beam 1']);
        Device::factory()->create(['domain' => 'security', 'category' => 'cctv', 'name' => 'Camera 1']);
        Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'network', 'name' => 'Switch 1']);

        $response = $this->actingAs($this->admin)->get('/security-devices/security?tab=alarms');

        $response->assertInertia(fn ($page) => $page
            ->has('devices.data', 2)  // alarm + perimeter, not cctv or IT
            ->where('stats.total', 2)
            ->where('workspace.activeTab', 'alarms')
            ->where('pageConfig.domain', 'security')
        );
    }

    public function test_cctv_page_only_shows_cctv_devices(): void
    {
        Device::factory()->create(['domain' => 'security', 'category' => 'cctv', 'name' => 'Camera 1']);
        Device::factory()->create(['domain' => 'security', 'category' => 'alarm', 'name' => 'Panel 1']);

        $response = $this->actingAs($this->admin)->get('/security-devices/security?tab=cctv');

        $response->assertInertia(fn ($page) => $page
            ->has('devices.data', 1)
            ->where('stats.total', 1)
            ->where('workspace.activeTab', 'cctv')
        );
    }

    public function test_it_infrastructure_shows_all_it_categories(): void
    {
        Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'network', 'name' => 'Switch']);
        Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'server', 'name' => 'Server']);
        Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'power', 'name' => 'UPS']);
        Device::factory()->create(['domain' => 'security', 'category' => 'cctv', 'name' => 'Camera']);

        $response = $this->actingAs($this->admin)->get('/security-devices/network-it?tab=devices');

        $response->assertInertia(fn ($page) => $page
            ->has('devices.data', 3)  // 3 IT devices, not the camera
            ->where('pageConfig.domain', 'it_infrastructure')
        );
    }

    public function test_tracking_page_shows_all_tracking_devices(): void
    {
        Device::factory()->create(['domain' => 'tracking', 'category' => 'vehicle_tracker']);
        Device::factory()->create(['domain' => 'tracking', 'category' => 'personal_tracker']);
        Device::factory()->create(['domain' => 'facilities', 'category' => 'cold_chain']);

        $response = $this->actingAs($this->admin)->get('/security-devices/tracking');

        $response->assertInertia(fn ($page) => $page
            ->has('devices.data', 2)
        );
    }

    // ── Stats scoping ─────────────────────────────────────────────

    public function test_stats_are_scoped_to_category(): void
    {
        Device::factory()->create(['domain' => 'security', 'category' => 'cctv', 'status' => 'active']);
        Device::factory()->create(['domain' => 'security', 'category' => 'cctv', 'status' => 'offline']);
        Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'network', 'status' => 'active']);

        $response = $this->actingAs($this->admin)->get('/security-devices/security?tab=cctv');

        $response->assertInertia(fn ($page) => $page
            ->where('stats.total', 2)
            ->where('stats.active', 1)
            ->where('stats.offline', 1)
        );
    }

    public function test_category_inventory_stats_and_providers_cover_the_single_application_registry(): void
    {

        Device::factory()->itInfrastructure()->create([
            'name' => 'Primary switch',
            'provider' => 'primary-provider',
        ]);
        Device::factory()->itInfrastructure()->create([
            'name' => 'Unrelated switch',
            'provider' => 'unrelated-provider',
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices/network-it');

        $response->assertInertia(fn ($page) => $page
            ->has('devices.data', 2)
            ->where('stats.total', 2)
            ->where('filterOptions.providers', ['primary-provider', 'unrelated-provider'])
        );
    }

    // ── Subcategory filter ────────────────────────────────────────

    public function test_subcategory_filter_works(): void
    {
        Device::factory()->create(['domain' => 'security', 'category' => 'cctv', 'subcategory' => 'dome_camera']);
        Device::factory()->create(['domain' => 'security', 'category' => 'cctv', 'subcategory' => 'nvr']);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=cctv&subcategory=dome_camera');

        $response->assertInertia(fn ($page) => $page
            ->has('devices.data', 1)
        );
    }

    // ── Search within scope ───────────────────────────────────────

    public function test_search_works_within_category_scope(): void
    {
        Device::factory()->create(['domain' => 'security', 'category' => 'alarm', 'name' => 'Fire Panel Reception']);
        Device::factory()->create(['domain' => 'security', 'category' => 'alarm', 'name' => 'PIR Living Room']);
        Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'network', 'name' => 'Fire Rack Switch']);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=alarms&search=Fire');

        $response->assertInertia(fn ($page) => $page
            ->has('devices.data', 1)  // Only the alarm device, not the IT switch
        );
    }

    // ── Page config correctness ───────────────────────────────────

    public function test_facilities_page_has_correct_config(): void
    {
        $response = $this->actingAs($this->admin)->get('/security-devices/facilities-iot');

        $response->assertInertia(fn ($page) => $page
            ->where('pageConfig.slug', 'facilities-iot')
            ->where('pageConfig.title', 'Facilities & IoT')
            ->where('pageConfig.domain', 'facilities')
            ->has('pageConfig.emptyTitle')
            ->has('pageConfig.emptyDescription')
        );
    }

    public function test_access_control_page_has_correct_config(): void
    {
        $response = $this->actingAs($this->admin)->get('/security-devices/security?tab=access-control');

        $response->assertInertia(fn ($page) => $page
            ->where('pageConfig.slug', 'security')
            ->where('pageConfig.title', 'Security')
            ->where('pageConfig.domain', 'security')
            ->where('workspace.activeTab', 'access-control')
        );
    }
}
