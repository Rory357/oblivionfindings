<?php

namespace Tests\Feature\SecurityDevices;

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

            'password' => 'not-used-by-this-feature-test',
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());

        $this->auditor = User::forceCreate([
            'name' => 'Security Devices Auditor',
            'email' => 'security-devices-auditor@example.test',
            'email_verified_at' => now(),
            'approved_at' => now(),

            'password' => 'not-used-by-this-feature-test',
        ]);
        $this->auditor->roles()->attach(Role::where('name', 'auditor')->firstOrFail());
    }

    /** S&D rebuild C6: the six-entry menu (Overview · Devices · Sites · Access · Alerts · Setup). */
    #[DataProvider('menuRouteProvider')]
    public function test_grouped_navigation_destinations_resolve_to_production_backed_pages(
        string $route,
        string $component,
    ): void {
        $this->actingAs($this->admin)
            ->get($route)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component($component));
    }

    public static function menuRouteProvider(): array
    {
        return [
            'overview' => ['/security-devices', 'security-devices/overview/index'],
            'devices' => ['/security-devices/devices', 'security-devices/devices/index'],
            'sites' => ['/security-devices/sites', 'security-devices/sites/index'],
            'access' => ['/security-devices/access', 'security-devices/access/index'],
            'alerts' => ['/security-devices/alerts', 'security-devices/alerts/index'],
            'setup' => ['/security-devices/setup', 'security-devices/setup/index'],
        ];
    }

    /** The old workspaces and operations pages now send people into the menu above. */
    #[DataProvider('retiredRouteProvider')]
    public function test_retired_pages_redirect_into_the_six_entry_menu(string $route, string $target): void
    {
        $this->actingAs($this->admin)->get($route)->assertRedirect($target);
    }

    public static function retiredRouteProvider(): array
    {
        return [
            'network and IT' => ['/security-devices/network-it', '/security-devices/devices?tab=network'],
            'security' => ['/security-devices/security', '/security-devices/devices?tab=alarms_doors'],
            'healthcare' => ['/security-devices/healthcare', '/security-devices/devices?tab=sensors&group=health'],
            'tracking' => ['/security-devices/tracking', '/security-devices/devices?tab=trackers'],
            'facilities and IoT' => ['/security-devices/facilities-iot', '/security-devices/devices?tab=sensors'],
            'monitoring' => ['/security-devices/monitoring', '/security-devices/alerts'],
            'maintenance' => ['/security-devices/maintenance', '/security-devices/devices?tab=upkeep'],
            'discovery' => ['/security-devices/discovery', '/security-devices/setup/hubs?tab=networks'],
            'settings' => ['/security-devices/settings', '/security-devices/setup'],
        ];
    }

    public function test_device_registration_capability_matches_the_exact_create_permission(): void
    {
        $this->actingAs($this->auditor)
            ->get('/security-devices/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.add', false));

        $this->actingAs($this->admin)
            ->get('/security-devices/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.add', true));
    }

    public function test_settings_readers_reach_only_the_setup_sections_they_can_open(): void
    {
        $this->actingAs($this->auditor)->get('/security-devices/settings')->assertRedirect('/security-devices/setup');

        $this->actingAs($this->auditor)
            ->get('/security-devices/setup')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/setup/index')
                ->where('sections', fn ($sections) => collect($sections)->pluck('key')->all() === ['overview', 'history'])
            );
    }

    public function test_sites_destination_uses_explicit_all_sites_access_and_does_not_require_sites_module_access(): void
    {
        Site::factory()->create(['name' => 'Koru House']);
        Site::factory()->create(['name' => 'Kea Lodge']);

        $this->actingAs($this->admin)
            ->get('/security-devices/sites')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/sites/index')
                ->has('cards', 2)
                ->where('cards.0.name', 'Kea Lodge')
            );
    }

    public function test_site_technology_detail_is_available_to_all_sites_users(): void
    {
        $site = Site::factory()->create(['name' => 'Koru House']);
        $otherSite = Site::factory()->create([]);

        $this->actingAs($this->admin)
            ->get("/security-devices/sites/{$site->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/sites/show')
                ->where('site.name', 'Koru House')
                ->has('meters')
                ->has('overview')
            );

        $this->actingAs($this->admin)
            ->get("/security-devices/sites/{$otherSite->id}")
            ->assertOk();
    }
}
