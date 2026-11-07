<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The estate and one Site. S&D rebuild C3/C4: the old dashboard's estate panel became the Overview
 * and the Site technology page became Sites (pg-sdr-11) and the Site page (pg-sdr-12). The old pages'
 * coverage, WAN, topology, group, IT work and contact props went with them; their Site scoping, their
 * honesty about empty or unwatched Sites and their Control Room and event-history privacy are checked
 * here against the new pages.
 */
class EstateSiteOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create([
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    }

    public function test_sites_never_present_empty_or_unwatched_sites_as_healthy(): void
    {
        $hubDown = Site::factory()->create(['name' => 'Action Site']);
        $empty = Site::factory()->create(['name' => 'Empty Site']);
        $unwatched = Site::factory()->create(['name' => 'Rimu House']);

        $device = Device::factory()->offline()->create([
            'health_status' => HealthStatus::Critical,
            'updated_at' => now()->subMinute(),
        ]);
        $this->assignToSite($device, $hubDown);
        $this->hub($hubDown, 'offline');

        // A device whose stored health says "healthy" but that nothing checks.
        $this->assignToSite(Device::factory()->create(['health_status' => HealthStatus::Healthy]), $unwatched);
        $this->hub($unwatched, 'online');

        $this->actingAs($this->admin)
            ->get('/security-devices/sites')
            ->assertOk()
            ->assertInertia(function ($page) use ($hubDown, $empty, $unwatched): void {
                $props = $page->toArray()['props'];
                $cards = collect($props['cards'])->keyBy('id');

                $this->assertSame(
                    collect([$hubDown->id, $empty->id, $unwatched->id])->sort()->values()->all(),
                    $cards->keys()->sort()->values()->all(),
                );
                $this->assertSame(3, $props['tabs']['all']);
                $this->assertSame(1, $props['tabs']['attention']);

                $this->assertSame('critical', $cards[$hubDown->id]['flag']['tone']);
                $this->assertSame('Hub not answering', $cards[$hubDown->id]['flag']['text']);
                $this->assertTrue($cards[$hubDown->id]['needs_attention']);

                foreach ([$empty->id, $unwatched->id] as $id) {
                    $this->assertSame('neutral', $cards[$id]['flag']['tone']);
                    $this->assertNotSame('All online', $cards[$id]['flag']['text']);
                    $this->assertFalse($cards[$id]['needs_attention']);
                }
                $this->assertSame('No hub yet', $cards[$empty->id]['flag']['text']);
                $this->assertFalse($cards[$empty->id]['set_up']);
                $this->assertSame('Nothing watched yet', $cards[$unwatched->id]['flag']['text']);
            });
    }

    public function test_site_access_and_cross_module_projections_require_their_own_permissions(): void
    {
        $allowedSite = Site::factory()->create(['name' => 'Allowed Site']);
        $hiddenSite = Site::factory()->create(['name' => 'Hidden Site']);
        $viewer = User::factory()->create([
            'approved_at' => now(),
        ]);
        $viewer->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        // Support workers hold only the care-alert key in S&D now (Stephan, 7 Nov 2026). This viewer gets
        // the module's two view keys directly, as the role gave them before.
        $viewer->permissionOverrides()->syncWithoutDetaching(Permission::query()
            ->whereIn('key', ['securityDevices.viewAny', 'securityDevices.devices.view'])
            ->pluck('id')->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all());
        $deniedCrossModulePermissions = Permission::query()
            ->whereIn('key', [
                'controlRoom.viewAny',
                'controlRoom.alerts.view',
                'it.view',
                'sites.viewAny',
            ])
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])
            ->all();
        $viewer->permissionOverrides()->syncWithoutDetaching($deniedCrossModulePermissions);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
        ]);

        $devices = [];
        foreach ([$allowedSite, $hiddenSite] as $site) {
            $devices[$site->id] = Device::factory()->create(['name' => $site->name.' device']);
            $this->assignToSite($devices[$site->id], $site);
        }
        $this->controlRoomAlertFor($devices[$allowedSite->id], $allowedSite, 'Allowed-site control room detail');
        ItTicket::factory()->create([
            'site_id' => $allowedSite->id,
            'requester_user_id' => $viewer->id,
            'title' => 'Allowed-site IT work detail',
            'status' => 'open',
        ]);

        $index = $this->actingAs($viewer)->get('/security-devices/sites');
        $index->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('cards', 1)
                ->where('cards.0.id', $allowedSite->id));
        foreach (['Hidden Site', 'Allowed-site control room detail', 'Allowed-site IT work detail'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $index->getContent());
        }

        $page = $this->actingAs($viewer)->get("/security-devices/sites/{$allowedSite->id}");
        $page->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('site.id', $allowedSite->id)
                ->where('meters.alerts.count', 0));
        foreach (['Allowed-site control room detail', 'Allowed-site IT work detail'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $page->getContent());
        }

        $this->actingAs($viewer)
            ->get("/security-devices/sites/{$hiddenSite->id}")
            ->assertNotFound();
    }

    public function test_all_sites_user_can_open_any_site(): void
    {
        $unrelatedSite = Site::factory()->create([]);

        $this->actingAs($this->admin)
            ->get("/security-devices/sites/{$unrelatedSite->id}")
            ->assertOk();
    }

    public function test_all_sites_security_scope_never_shows_another_sites_control_room_detail(): void
    {
        $assignedSite = Site::factory()->create();
        $unrelatedSite = Site::factory()->create();
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        // Support workers hold only the care-alert key in S&D now (Stephan, 7 Nov 2026). This viewer gets
        // the module's two view keys directly, as the role gave them before.
        $viewer->permissionOverrides()->syncWithoutDetaching(Permission::query()
            ->whereIn('key', ['securityDevices.viewAny', 'securityDevices.devices.view'])
            ->pluck('id')->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all());
        $permissionIds = Permission::query()
            ->whereIn('key', [
                'securityDevices.devices.viewAllSites',
                'controlRoom.viewAny',
                'controlRoom.alerts.view',
            ])
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();
        $viewer->permissionOverrides()->syncWithoutDetaching($permissionIds);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $assignedSite->id,
            'secondary_site_ids' => [],
        ]);
        $device = Device::factory()->create(['name' => 'Unrelated Site gateway']);
        $this->assignToSite($device, $unrelatedSite);
        $this->controlRoomAlertFor($device, $unrelatedSite, 'Private site alert detail');

        // All Sites in Security & devices doesn't widen Control Room: the device shows, its alert doesn't.
        $page = $this->actingAs($viewer)->get("/security-devices/sites/{$unrelatedSite->id}");
        $page->assertOk()->assertInertia(fn ($page) => $page->where('site.id', $unrelatedSite->id)->where('meters.alerts.count', 0));
        $this->assertStringNotContainsString('Private site alert detail', $page->getContent());

        $index = $this->actingAs($viewer)->get('/security-devices/sites');
        $index->assertOk();
        $this->assertStringNotContainsString('Private site alert detail', $index->getContent());

        // Someone Control Room lets read it sees it on the same page.
        $this->actingAs($this->admin)
            ->get("/security-devices/sites/{$unrelatedSite->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('meters.alerts.count', 1)->where('meters.alerts.caption', 'Private site alert detail'));
    }

    public function test_site_profile_destination_requires_exact_canonical_site_visibility(): void
    {
        $assignedSite = Site::factory()->create();
        $unrelatedSite = Site::factory()->create();
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        // Support workers hold only the care-alert key in S&D now (Stephan, 7 Nov 2026). This viewer gets
        // the module's two view keys directly, as the role gave them before.
        $viewer->permissionOverrides()->syncWithoutDetaching(Permission::query()
            ->whereIn('key', ['securityDevices.viewAny', 'securityDevices.devices.view'])
            ->pluck('id')->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all());
        $permissionIds = Permission::query()
            ->whereIn('key', [
                'securityDevices.devices.viewAllSites',
                'sites.viewAny',
            ])
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();
        $viewer->permissionOverrides()->syncWithoutDetaching($permissionIds);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $assignedSite->id,
            'secondary_site_ids' => [],
        ]);

        // The Rooms tab links to Sites & locations only for a Site that module lets the viewer open.
        $this->actingAs($viewer)
            ->get("/security-devices/sites/{$unrelatedSite->id}?tab=rooms")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'rooms')
                ->where('rooms.profile_href', null));
        $this->actingAs($viewer)
            ->get("/security-devices/sites/{$assignedSite->id}?tab=rooms")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('rooms.profile_href', '/sites/'.$assignedSite->id));
    }

    public function test_estate_and_site_findings_do_not_inherit_foreign_event_history_when_a_device_moves(): void
    {
        $this->travelTo(now()->startOfSecond());
        $allowed = Site::factory()->create();
        $hidden = Site::factory()->create();
        $viewer = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $viewer->id, 'primary_site_id' => $allowed->id, 'secondary_site_ids' => [], 'is_active' => true]);
        // maintenance.view: someone who looks after devices, so the IT Overview (with its activity) opens.
        foreach (['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'securityDevices.maintenance.view'] as $key) {
            $permission = Permission::query()->where('key', $key)->firstOrFail();
            $viewer->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        }
        $device = Device::factory()->itInfrastructure()->create(['name' => 'Currently permitted router', 'subcategory' => 'router']);
        DeviceAssignment::create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $hidden->id, 'assigned_at' => now()->subDays(2), 'released_at' => now()->subHour()]);
        DeviceAssignment::create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $allowed->id, 'assigned_at' => now()->subHour()]);
        DeviceEvent::create(['device_id' => $device->id, 'event_type' => 'hidden_past_offline', 'severity' => 'critical', 'source' => 'Private foreign history', 'occurred_at' => now()->subHours(2)]);
        DeviceEvent::create(['device_id' => $device->id, 'event_type' => 'visible_online', 'severity' => 'warning', 'source' => 'test', 'occurred_at' => now()->subMinutes(30)]);

        $overview = $this->actingAs($viewer)->get('/security-devices');
        $overview->assertOk()->assertInertia(fn ($page) => $page
            ->component('security-devices/overview/index')
            ->has('overview.activity', 1)
            ->where('overview.activity.0.title', 'Visible online'));

        $internet = $this->actingAs($viewer)->get('/security-devices/sites/'.$allowed->id.'?tab=internet');
        $internet->assertOk()->assertInertia(fn ($page) => $page
            ->where('tab', 'internet')
            ->has('internet.outages', 1)
            ->where('internet.outages.0.title', 'Visible online'));

        foreach ([$overview, $internet] as $response) {
            foreach (['Private foreign history', 'hidden_past_offline', 'Hidden past offline'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $response->getContent());
            }
        }

        // Without the device-events key there is no activity and no drop-out history at all.
        $permission = Permission::query()->where('key', 'securityDevices.events.view')->firstOrFail();
        $viewer->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $viewer = $viewer->fresh();
        $this->actingAs($viewer)->get('/security-devices')->assertOk()->assertInertia(fn ($page) => $page->where('overview.activity', []));
        $this->actingAs($viewer)->get('/security-devices/sites/'.$allowed->id.'?tab=internet')->assertOk()->assertInertia(fn ($page) => $page->where('internet.outages', []));
    }

    private function assignToSite(Device $device, Site $site): void
    {
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
    }

    private function hub(Site $site, string $status): MonitoringCollector
    {
        return MonitoringCollector::factory()->create([
            'site_id' => $site->id,
            'name' => $site->name.' hub',
            'collector_uuid' => (string) Str::uuid(),
            'status' => $status,
            'last_seen_at' => now()->subMinutes(10),
            'approved_at' => now()->subDay(),
            'revoked_at' => null,
        ]);
    }

    private function controlRoomAlertFor(Device $device, Site $site, string $type): ControlRoomAlert
    {
        $controlRoomDevice = ControlRoomDevice::query()->create([
            'name' => $device->name,
            'type' => ControlRoomDevice::TYPE_NETWORK,
            'site_id' => $site->id,
            'status' => 'online',
            'canonical_device_id' => $device->id,
        ]);

        return ControlRoomAlert::factory()->critical()->create([
            'site_id' => $site->id,
            'device_id' => $controlRoomDevice->id,
            'status' => ControlRoomAlert::STATUS_OPEN,
            'alert_type' => $type,
        ]);
    }
}
