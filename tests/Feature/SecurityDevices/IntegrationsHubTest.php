<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Integration\IntegrationTenantSecret;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the Security & Devices Integrations Hub landing page.
 *
 * Target: App\Domain\SecurityDevices\Http\Controllers\IntegrationsHubController
 * Route:  GET /security-devices/integrations
 */
class IntegrationsHubTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        // support_worker lacks securityDevices.integrations.view.
        $this->viewer = User::factory()->create();
        $this->viewer->roles()->attach(Role::where('name', 'support_worker')->first());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/security-devices/integrations')->assertRedirect('/login');
    }

    public function test_user_without_integrations_view_is_forbidden(): void
    {
        $this->actingAs($this->viewer)
            ->get('/security-devices/integrations')
            ->assertForbidden();
    }

    public function test_admin_sees_inertia_hub_page_with_stats_and_can_flags(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices/integrations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/integrations')
                ->has('providers', 3)
                ->has('stats.providers_total')
                ->has('stats.providers_live')
                ->has('stats.providers_connected')
                ->has('stats.providers_errored')
                ->has('stats.imported_devices')
                ->has('stats.events_24h')
                ->where('stats.providers_total', 3)
                ->has('can.manage')
            );
    }

    public function test_provider_catalog_shape_and_unifi_live_docs(): void
    {
        $response = $this->actingAs($this->admin)->get('/security-devices/integrations');

        $response->assertInertia(function ($page) {
            $providers = collect($page->toArray()['props']['providers']);

            $slugs = $providers->pluck('slug')->all();
            $this->assertEqualsCanonicalizing(['unifi', 'queclink', 'milesight'], $slugs);

            $unifi = $providers->firstWhere('slug', 'unifi');
            $this->assertSame('live', $unifi['implementation_status']);
            $this->assertSame('/security-devices/integrations/unifi', $unifi['docs_href']);

            // Non-configured providers should report "not_configured" by default.
            $this->assertSame('not_configured', $unifi['connection_status']);
            $this->assertFalse($unifi['connected']);
        });
    }

    public function test_stats_reflect_connected_secrets_per_provider(): void
    {
        // Seed one connected + one errored secret for this tenant.
        IntegrationTenantSecret::create([
            'tenant_id' => $this->admin->tenant_id ?? 1,
            'provider' => 'unifi',
            'secret_encrypted' => 'dummy',
            'secret_last4' => '1234',
            'status' => IntegrationTenantSecret::STATUS_CONNECTED,
        ]);

        IntegrationTenantSecret::create([
            'tenant_id' => $this->admin->tenant_id ?? 1,
            'provider' => 'queclink',
            'secret_encrypted' => 'dummy',
            'secret_last4' => '5678',
            'status' => IntegrationTenantSecret::STATUS_ERROR,
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices/integrations');

        $response->assertInertia(fn ($page) => $page
            ->where('stats.providers_connected', 1)
            ->where('stats.providers_errored', 1)
        );
    }

    public function test_connection_state_device_and_event_rollups_are_scoped_to_the_users_tenant(): void
    {
        $this->admin->forceFill(['organization_id' => 42])->save();

        IntegrationTenantSecret::create([
            'tenant_id' => 42,
            'provider' => 'unifi',
            'secret_encrypted' => 'tenant-secret',
            'secret_last4' => '0042',
            'status' => IntegrationTenantSecret::STATUS_CONNECTED,
        ]);
        IntegrationTenantSecret::create([
            'tenant_id' => 77,
            'provider' => 'milesight',
            'secret_encrypted' => 'foreign-secret',
            'secret_last4' => '0077',
            'status' => IntegrationTenantSecret::STATUS_ERROR,
        ]);

        $tenantDevice = Device::factory()->create(['tenant_id' => 42, 'provider' => 'unifi']);
        $foreignDevice = Device::factory()->create(['tenant_id' => 77, 'provider' => 'milesight']);

        foreach ([['device' => $tenantDevice, 'source' => 'unifi'], ['device' => $foreignDevice, 'source' => 'milesight']] as $event) {
            DeviceEvent::create([
                'device_id' => $event['device']->id,
                'event_type' => 'heartbeat',
                'severity' => 'info',
                'source' => $event['source'],
                'occurred_at' => now(),
            ]);
        }

        $response = $this->actingAs($this->admin)->get('/security-devices/integrations');

        $response->assertInertia(function ($page) {
            $props = $page->toArray()['props'];
            $providers = collect($props['providers']);

            $this->assertSame(1, $props['stats']['providers_connected']);
            $this->assertSame(0, $props['stats']['providers_errored']);
            $this->assertSame(1, $props['stats']['imported_devices']);
            $this->assertSame(1, $props['stats']['events_24h']);
            $this->assertSame('connected', $providers->firstWhere('slug', 'unifi')['connection_status']);
            $this->assertSame('not_configured', $providers->firstWhere('slug', 'milesight')['connection_status']);
        });
    }
}
