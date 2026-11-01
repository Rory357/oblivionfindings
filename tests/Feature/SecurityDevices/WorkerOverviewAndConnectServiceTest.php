<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerSecretStore;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Screens\Setup\ConnectServicePresenter;
use App\Models\Client;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Notifications\SecurityDeviceAlertNotification;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeIntegrationSecretBackend;
use Tests\TestCase;

/**
 * S&D Phase E (E8): the support worker's Overview (pg-sdr-1c) and pop-up 15, Connect a service.
 * Scope (another Site's alerts never show), who gets which Overview, and that the wizard's save
 * path needs the services key and never sends a saved login back.
 */
class WorkerOverviewAndConnectServiceTest extends TestCase
{
    use RefreshDatabase;

    private Site $home;

    private Site $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $backend = new FakeIntegrationSecretBackend;
        $this->app->instance(SecretManagerSecretStore::class, $backend);
        $this->app->instance(SecretManagerLeaseIssuer::class, $backend);

        $this->home = Site::factory()->create(['name' => 'Aurora House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->other = Site::factory()->create(['name' => 'Kōwhai Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    }

    /** @param  list<string>  $keys */
    private function person(array $keys, ?Site $site = null): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => ($site ?? $this->home)->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user;
    }

    private function supportWorker(): User
    {
        return $this->person(['securityDevices.viewAny', 'securityDevices.devices.view']);
    }

    private function device(Site $site, string $name, string $category = 'alarm', string $subcategory = 'panic_button'): Device
    {
        $device = Device::factory()->create(['name' => $name, 'domain' => 'security', 'category' => $category, 'subcategory' => $subcategory]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now()->subDays(3),
        ]);

        return $device;
    }

    private function alert(Device $device, Site $site, string $kind, string $title): DeviceAlert
    {
        return DeviceAlert::query()->create([
            'device_id' => $device->id,
            'site_id' => $site->id,
            'kind' => $kind,
            'severity' => in_array($kind, ['panic', 'alarm'], true) ? 'critical' : 'warning',
            'title' => $title,
            'state' => DeviceAlert::STATE_OPEN,
            'opened_at' => now()->subMinutes(10),
        ]);
    }

    private function onShift(User $user, Site $site): void
    {
        Shift::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'client_id' => Client::factory()->create(['site_id' => $site->id])->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'status' => 'scheduled',
        ]);
    }

    private function told(User $user, DeviceAlert $alert): void
    {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => SecurityDeviceAlertNotification::class,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => json_encode(['type' => 'security_device_alert', 'severity' => $alert->severity, 'title' => $alert->title, 'body' => 'Panic button pressed', 'context' => ['alert_id' => $alert->id]]),
            'read_at' => null,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);
    }

    public function test_a_support_worker_sees_their_own_house_not_the_it_overview(): void
    {
        $worker = $this->supportWorker();
        $this->device($this->home, 'Aurora House staff button');

        $this->actingAs($worker)
            ->get('/security-devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/overview/index')
                ->where('view', 'worker')
                ->where('site.name', 'Aurora House')
                ->where('kit.items.0.name', 'Aurora House staff button')
                ->where('kit.items.0.kind', 'panic')
                ->where('can.open_alerts', false)
                ->missing('first_time')
                ->missing('overview')
                ->missing('trends')
                ->missing('deviceForm'));

        // Someone with a key of the people who look after devices keeps the IT Overview.
        $manager = $this->person(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.reports.view']);
        $this->actingAs($manager)
            ->get('/security-devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('view')->has('meters.online'));
    }

    public function test_another_sites_alerts_never_show_and_its_page_is_refused(): void
    {
        $worker = $this->supportWorker();
        $this->onShift($worker, $this->home);
        $here = $this->alert($this->device($this->home, 'Aurora House staff button'), $this->home, 'panic', 'Aurora House staff button: panic button pressed');
        $this->alert($this->device($this->home, 'Aurora House router', 'network', 'router'), $this->home, 'offline', 'Aurora House router is offline');
        $there = $this->alert($this->device($this->other, 'Kōwhai Lodge staff button'), $this->other, 'panic', 'Kōwhai Lodge staff button: panic button pressed');
        $this->told($worker, $here);
        $this->told($worker, $there);

        $response = $this->actingAs($worker)->get('/security-devices');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('on_shift', true)
            ->has('safety', 1)
            ->where('safety.0.title', 'Aurora House staff button: panic button pressed')
            ->has('told', 1)
            ->where('told.0.title', 'Aurora House staff button: panic button pressed'));
        $this->assertStringNotContainsString('Kōwhai Lodge', $response->getContent());
        // An IT alert (offline) isn't a house alert.
        $this->assertStringNotContainsString('router is offline', $response->getContent());

        $this->actingAs($worker)->get('/security-devices?site='.$this->other->id)->assertNotFound();
    }

    public function test_off_shift_a_support_worker_sees_only_the_alerts_they_were_told_about(): void
    {
        $worker = $this->supportWorker();
        $told = $this->alert($this->device($this->home, 'Aurora House staff button'), $this->home, 'panic', 'Aurora House staff button: panic button pressed');
        $this->alert($this->device($this->home, 'Aurora House back door'), $this->home, 'tamper', 'Aurora House back door: tampered with');
        $this->told($worker, $told);

        $this->actingAs($worker)
            ->get('/security-devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('on_shift', false)
                ->has('safety', 1)
                ->where('safety.0.title', 'Aurora House staff button: panic button pressed'));
    }

    public function test_connect_a_service_refuses_without_the_services_key(): void
    {
        // Can see Setup › Services, but not manage them.
        $viewer = $this->person(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.integrations.view']);

        $this->actingAs($viewer)->getJson('/security-devices/setup/services/connect')->assertForbidden();
        $this->actingAs($viewer)
            ->postJson('/security-devices/setup/services/connect/unifi/key', ['api_key' => 'TEST-ONLY-unifi-key-Zq9X'])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->postJson('/security-devices/setup/services/connect/intune/key', ['directory_id' => (string) Str::uuid(), 'client_id' => (string) Str::uuid(), 'client_secret' => 'TEST-ONLY-secret'])
            ->assertForbidden();

        $this->assertDatabaseMissing((new IntegrationProviderConnection)->getTable(), ['provider' => 'unifi']);
        $this->assertDatabaseMissing((new IntegrationProviderConnection)->getTable(), ['provider' => 'intune']);
    }

    public function test_a_saved_login_is_stored_through_the_services_own_save_and_never_sent_back(): void
    {
        $manager = $this->person(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.integrations.manage']);

        $saved = $this->actingAs($manager)
            ->postJson('/security-devices/setup/services/connect/unifi/key', ['api_key' => 'TEST-ONLY-unifi-key-Zq9X'])
            ->assertOk()
            ->assertJsonPath('outcome', 'done')
            ->assertJsonPath('state.unifi.has_key', true);
        $this->assertStringContainsString('no-store', (string) $saved->headers->get('Cache-Control'));
        // The wizard says what happened; the settings page's message doesn't toast on the next page.
        $this->assertNull(session('success'));
        $read = $this->actingAs($manager)->getJson('/security-devices/setup/services/connect')->assertOk();

        foreach ([$saved->getContent(), $read->getContent()] as $body) {
            $this->assertStringNotContainsString('TEST-ONLY', $body);
            $this->assertStringNotContainsString('Zq9X', $body);
            $this->assertStringNotContainsString('secret_last4', $body);
        }
        // The UniFi page's own save wrote it, encrypted (its last characters stay on the server).
        $connection = IntegrationProviderConnection::query()->forProvider('unifi')->firstOrFail();
        $this->assertSame('Zq9X', $connection->secret_last4);
    }

    public function test_matching_a_unifi_site_uses_the_settings_pages_own_match(): void
    {
        $manager = $this->person(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.integrations.manage']);
        IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('TEST-ONLY-legacy'),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'config' => ['discovered_sites' => [['external_id' => 'synthetic-site-a', 'name' => 'Aurora', 'meta' => ['device_count' => 3]]]],
        ]);

        $state = $this->actingAs($manager)->getJson('/security-devices/setup/services/connect')->assertOk();
        $token = $state->json('unifi.remote_sites.0.token');
        $this->assertSame(ConnectServicePresenter::unifiToken('synthetic-site-a'), $token);
        $this->assertStringNotContainsString('synthetic-site-a', $state->getContent());
        $this->assertStringNotContainsString('Kōwhai Lodge', $state->getContent());

        $this->actingAs($manager)
            ->postJson('/security-devices/setup/services/connect/unifi/match', ['site_id' => $this->home->id, 'mapping_token' => $token])
            ->assertOk()
            ->assertJsonPath('state.unifi.remote_sites.0.match.site_name', 'Aurora House');
        $this->assertDatabaseHas((new IntegrationSiteConfig)->getTable(), ['site_id' => $this->home->id, 'provider' => 'unifi', 'mapped_external_site_id' => 'synthetic-site-a']);

        // Another Site is refused by the same rule as the settings page.
        $this->actingAs($manager)
            ->postJson('/security-devices/setup/services/connect/unifi/match', ['site_id' => $this->other->id, 'mapping_token' => $token])
            ->assertNotFound();
    }
}
