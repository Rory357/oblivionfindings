<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Topology\Data\TopologyEvidence;
use App\Domain\Monitoring\Topology\Services\TopologySnapshotBuilder;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlertEvent;
use App\Domain\SecurityDevices\Care\Models\DevicePersonLink;
use App\Domain\SecurityDevices\Enums\RelationshipType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Domain\SecurityDevices\Modes\Models\HouseModeEvent;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S&D round 3: the Site page's Devices, Alerts, Logbook and links-found views (pg-sdr-12g–12j) and
 * the Overview's Activity tab (18c). Scope, keys, a resident's name, and what counts as open.
 */
class SitePageTabsTest extends TestCase
{
    use RefreshDatabase;

    private Site $home;

    private Site $other;

    private User $viewer;

    private MonitoringProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        // Thu 29 Oct 2026, 12:00 pm in Auckland (NZDT), so "today" and "overnight" are fixed.
        $this->travelTo(CarbonImmutable::parse('2026-10-28 23:00:00', 'UTC'));

        $this->home = Site::factory()->create(['name' => 'Aurora House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->other = Site::factory()->create(['name' => 'Kōwhai Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->viewer = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->viewer->id,
            'primary_site_id' => $this->home->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $this->grant('securityDevices.viewAny', 'securityDevices.devices.view');
        $this->profile = MonitoringProfile::factory()->create();
    }

    private function grant(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->viewer->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }
        $this->viewer->refresh();
    }

    private function device(Site $site, array $attributes = [], ?MonitorState $check = MonitorState::Healthy): Device
    {
        $device = Device::factory()->itInfrastructure()->create($attributes);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now()->subDays(3),
        ]);
        if ($check !== null) {
            Monitor::factory()->create([
                'device_id' => $device->id,
                'profile_id' => $this->profile->id,
                'current_state' => $check,
                'last_observation_at' => now()->subMinute(),
                'last_state_changed_at' => now()->subHours(2),
            ]);
        }

        return $device;
    }

    private function alert(Device $device, Site $site, array $attributes = []): DeviceAlert
    {
        $alert = DeviceAlert::query()->create([
            'device_id' => $device->id,
            'site_id' => $site->id,
            'kind' => 'failing',
            'severity' => 'warning',
            'title' => $device->name.': a check needs attention',
            'state' => DeviceAlert::STATE_OPEN,
            'opened_at' => now()->subMinutes(30),
            'last_failing_at' => now()->subMinutes(30),
            ...$attributes,
        ]);
        DeviceAlertEvent::query()->create(['alert_id' => $alert->id, 'at' => $alert->opened_at, 'kind' => 'opened', 'text' => 'Started failing']);

        return $alert;
    }

    public function test_the_devices_tab_lists_this_sites_devices_problems_first_and_not_watched_last(): void
    {
        $this->device($this->home, ['name' => 'Aurora House switch', 'subcategory' => 'switch']);
        $this->device($this->home, ['name' => 'Back yard camera'], MonitorState::Failed);
        $this->device($this->home, ['name' => 'Staff phone base', 'provider' => null], null);
        $this->device($this->other, ['name' => 'Kōwhai Lodge router']);

        $response = $this->actingAs($this->viewer)->get('/security-devices/sites/'.$this->home->id.'?tab=devices');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('tab', 'devices')
            ->where('tabs.devices', 3)
            ->has('devices.rows', 3)
            ->where('devices.rows.0.name', 'Back yard camera')
            ->where('devices.rows.0.badge.tone', 'critical')
            ->where('devices.rows.1.name', 'Aurora House switch')
            ->where('devices.rows.2.name', 'Staff phone base')
            ->where('devices.rows.2.state', 'not_watched')
            ->where('devices.not_watched_count', 1)
            ->where('devices.can.edit', false));
        $this->assertStringNotContainsString('Kōwhai Lodge router', $response->getContent());
    }

    public function test_the_alerts_and_logbook_tabs_need_their_keys_and_keep_to_this_site(): void
    {
        $switch = $this->device($this->home, ['name' => 'Aurora House switch', 'subcategory' => 'switch']);
        $router = $this->device($this->other, ['name' => 'Kōwhai Lodge router']);
        $this->alert($switch, $this->home);
        $this->alert($router, $this->other);

        foreach (['alerts', 'logbook'] as $tab) {
            $this->actingAs($this->viewer)->get('/security-devices/sites/'.$this->home->id.'?tab='.$tab)
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('tab', 'overview')->where('tabs.alerts', false)->where('tabs.logbook', false)->where('alerts', null)->where('logbook', null));
        }

        $this->grant('securityDevices.events.view');
        $response = $this->actingAs($this->viewer)->get('/security-devices/sites/'.$this->home->id.'?tab=alerts');
        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('tab', 'alerts')
            ->has('alerts.rows', 1)
            ->where('alerts.rows.0.title', 'Aurora House switch: a check needs attention')
            ->where('alerts.rows.0.told', 'Nobody was told')
            ->where('alerts.rows.0.ended.badge', 'Open'));
        $this->assertStringNotContainsString('Kōwhai Lodge router', $response->getContent());

        HouseModeEvent::query()->create([
            'site_id' => $this->home->id, 'kind' => HouseModeEvent::KIND_SWITCH, 'state' => HouseModeEvent::STATE_DONE,
            'source' => 'schedule', 'title' => 'Night → Day', 'lines' => [], 'at' => now()->subHours(3),
        ]);
        HouseModeEvent::query()->create([
            'site_id' => $this->other->id, 'kind' => HouseModeEvent::KIND_SWITCH, 'state' => HouseModeEvent::STATE_DONE,
            'source' => 'schedule', 'title' => 'Day → Away', 'lines' => [], 'at' => now()->subHours(2),
        ]);
        $logbook = $this->actingAs($this->viewer)->get('/security-devices/sites/'.$this->home->id.'?tab=logbook');
        $logbook->assertOk()->assertInertia(fn ($page) => $page
            ->where('tab', 'logbook')
            ->where('logbook.what', 'everything')
            ->where('logbook.sections.0.items.0.title', 'Aurora House switch: a check needs attention')
            ->where('logbook.sections.0.items.1.title', 'House mode Night → Day'));
        $this->assertStringNotContainsString('Day → Away', $logbook->getContent());
        $this->assertStringNotContainsString('Kōwhai Lodge router', $logbook->getContent());
    }

    public function test_snoozed_alerts_are_left_out_of_alerts_open(): void
    {
        $this->grant('securityDevices.events.view', 'securityDevices.reports.view');
        $switch = $this->device($this->home, ['name' => 'Aurora House switch', 'subcategory' => 'switch']);
        $camera = $this->device($this->home, ['name' => 'Back yard camera']);
        $this->alert($switch, $this->home);
        $snoozed = $this->alert($camera, $this->home, [
            'title' => 'Back yard camera has no picture', 'state' => DeviceAlert::STATE_SNOOZED, 'snoozed_until' => now()->addHours(2),
        ]);
        DeviceAlertEvent::query()->create(['alert_id' => $snoozed->id, 'at' => now()->subMinutes(10), 'kind' => 'snoozed', 'actor_user_id' => $this->viewer->id, 'text' => 'Snoozed it', 'detail' => 'Checking port 4']);

        $this->actingAs($this->viewer)->get('/security-devices/sites/'.$this->home->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('meters.alerts.count', 1)->where('meters.alerts.caption', 'Aurora House switch: a check needs attention'));
        $this->actingAs($this->viewer)->get('/security-devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('meters.alerts.count', 1));
        $sites = $this->actingAs($this->viewer)->get('/security-devices/sites');
        $sites->assertOk();
        $this->assertStringNotContainsString('Back yard camera has no picture', $sites->getContent());

        // The Site's Alerts tab still lists it, as snoozed, after the open one.
        $this->actingAs($this->viewer)->get('/security-devices/sites/'.$this->home->id.'?tab=alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('alerts.rows', 2)
                ->where('alerts.rows.0.ended.badge', 'Open')
                ->where('alerts.rows.1.ended.badge', 'Snoozed')
                ->where('alerts.rows.1.ended.note', fn (string $note): bool => str_starts_with($note, 'Until ') && str_ends_with($note, ': Checking port 4')));
    }

    public function test_a_resident_is_named_only_to_someone_who_may_open_their_record(): void
    {
        $this->grant('securityDevices.events.view', 'securityDevices.reports.view');
        $client = Client::factory()->create(['site_id' => $this->home->id, 'first_name' => 'Hemi', 'preferred_name' => null]);
        $sensor = $this->device($this->home, ['name' => 'Room 3 bed sensor']);
        DevicePersonLink::query()->create([
            'device_id' => $sensor->id, 'client_id' => $client->id, 'site_id' => $this->home->id, 'purpose' => 'falls',
            'rule' => ['minutes' => 10, 'from' => '22:00', 'to' => '06:00'], 'linked_at' => now()->subDays(5),
        ]);
        $alert = $this->alert($sensor, $this->home, [
            'kind' => 'care_out_of_bed', 'severity' => 'critical', 'title' => 'Hemi out of bed for over 10 minutes',
        ]);
        DeviceAlertEvent::query()->where('alert_id', $alert->id)->update(['text' => 'Hemi out of bed since 11:20 am', 'detail' => 'From Hemi’s falls plan: check on them now.']);

        $tab = $this->actingAs($this->viewer)->get('/security-devices/sites/'.$this->home->id.'?tab=alerts');
        $tab->assertOk()->assertInertia(fn ($page) => $page->where('alerts.rows.0.title', 'A person out of bed for over 10 minutes'));
        $activity = $this->actingAs($this->viewer)->get('/security-devices?tab=activity');
        $activity->assertOk()->assertInertia(fn ($page) => $page->where('tab', 'activity')->where('activity.rows.0.title', 'A person out of bed for over 10 minutes'));
        $header = $this->actingAs($this->viewer)->get('/security-devices/sites/'.$this->home->id);
        // The Alerts page follows the same rule: the queue, the chosen alert and its timeline.
        $alerts = $this->actingAs($this->viewer)->get('/security-devices/alerts?alert='.$alert->id);
        $alerts->assertOk()->assertInertia(fn ($page) => $page
            ->where('queue.0.title', 'A person out of bed for over 10 minutes')
            ->where('selected.title', 'A person out of bed for over 10 minutes'));
        foreach ([$tab, $activity, $header, $alerts] as $response) {
            $this->assertStringNotContainsString('Hemi', $response->getContent());
        }

        // Someone who may open Hemi's record reads his name.
        $this->grant('clients.viewAny');
        $this->actingAs($this->viewer)->get('/security-devices/sites/'.$this->home->id.'?tab=alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('alerts.rows.0.title', 'Hemi out of bed for over 10 minutes'));
    }

    public function test_activity_needs_events_view_and_shows_only_the_viewers_sites(): void
    {
        // reports.view: someone who looks after devices gets this Overview, not the support worker's.
        $this->grant('securityDevices.reports.view');
        $switch = $this->device($this->home, ['name' => 'Aurora House switch', 'subcategory' => 'switch']);
        $router = $this->device($this->other, ['name' => 'Kōwhai Lodge router']);
        $this->alert($switch, $this->home);
        $this->alert($router, $this->other);

        $this->actingAs($this->viewer)->get('/security-devices?tab=activity')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('tab', 'overview')->where('can.activity', false)->where('activity', null));

        $this->grant('securityDevices.events.view');
        $response = $this->actingAs($this->viewer)->get('/security-devices?tab=activity');
        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('tab', 'activity')
            ->where('activity.today', true)
            ->has('activity.rows', 1)
            ->where('activity.rows.0.title', 'Aurora House switch: a check needs attention')
            ->where('activity.rows.0.site', 'Aurora House')
            ->where('activity.rows.0.next', 'Nobody was told · still open, not acknowledged')
            ->where('activity.by_kind.2.key', 'alerts')
            ->where('activity.by_kind.2.count', 1));
        $this->assertStringNotContainsString('Kōwhai Lodge router', $response->getContent());

        // Another day picks that day; kinds narrow the table.
        $this->actingAs($this->viewer)->get('/security-devices?tab=activity&kind=doors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('activity.kind', 'doors')->has('activity.rows', 0));
        $this->actingAs($this->viewer)->get('/security-devices?tab=activity&day=2026-10-27')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('activity.day', '2026-10-27')->where('activity.today', false)->has('activity.rows', 0));
    }

    public function test_links_found_lists_only_links_between_devices_the_viewer_can_see(): void
    {
        $gateway = $this->device($this->home, ['name' => 'Aurora House gateway', 'subcategory' => 'router']);
        $switch = $this->device($this->home, ['name' => 'Aurora House switch', 'subcategory' => 'switch']);
        $phone = $this->device($this->home, ['name' => 'Staff phone base']);
        // A resident's own device at the house: hidden from someone who may not see clients.
        $hidden = Device::factory()->itInfrastructure()->create(['name' => 'Resident tablet']);
        DeviceAssignment::create([
            'device_id' => $hidden->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => Client::factory()->create(['site_id' => $this->home->id])->id,
            'assigned_at' => now()->subDays(3),
        ]);
        app(TopologySnapshotBuilder::class)->build($this->home, [
            new TopologyEvidence('lldp', $gateway->id, $switch->id, 'ethernet', 'LAN 1', 'Port 1', 0.98, ['chassis_hash' => hash('sha256', 'gw')]),
            new TopologyEvidence('forwarding_table', $switch->id, $phone->id, 'ethernet', 'Port 14', null, 0.7, ['bridge_port' => 14]),
            new TopologyEvidence('lldp', $switch->id, $hidden->id, 'ethernet', 'Port 20', null, 0.95, ['chassis_hash' => hash('sha256', 'laptop')]),
        ], source: 'native:snmp', sourceCheckpoint: 'snmp-run-aurora-1');

        $response = $this->actingAs($this->viewer)->get('/security-devices/sites/'.$this->home->id.'?tab=network');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('tab', 'network')
            ->where('network.links.nothing', false)
            ->where('network.links.threshold', 85)
            ->has('network.links.rows', 2)
            ->where('network.links.rows.0.name', 'Aurora House gateway → Aurora House switch')
            ->where('network.links.rows.0.map.state', 'new')
            ->where('network.links.rows.0.sure', 98)
            ->where('network.links.rows.1.name', 'Aurora House switch → Staff phone base')
            ->where('network.links.rows.1.map.state', 'unsure')
            ->where('network.links.sources.0.key', 'hub')
            ->where('network.links.can.change', false));
        $this->assertStringNotContainsString('Resident tablet', $response->getContent());

        // A link a person put on the map says so.
        DeviceRelationship::query()->create([
            'parent_device_id' => $gateway->id, 'child_device_id' => $switch->id,
            'relationship_type' => RelationshipType::ConnectedTo, 'created_by_user_id' => $this->viewer->id,
        ]);
        $this->actingAs($this->viewer)->get('/security-devices/sites/'.$this->home->id.'?tab=network')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('network.links.rows.1.name', 'Aurora House gateway → Aurora House switch')
                ->where('network.links.rows.1.map.state', 'on'));
    }

    public function test_add_to_the_map_needs_permission_to_edit_devices(): void
    {
        $gateway = $this->device($this->home, ['name' => 'Aurora House gateway', 'subcategory' => 'router']);
        $switch = $this->device($this->home, ['name' => 'Aurora House switch', 'subcategory' => 'switch']);
        $add = fn () => $this->actingAs($this->viewer)->post('/security-devices/devices/'.$gateway->id.'/relationships', [
            'other_device_id' => $switch->id, 'relationship_type' => 'connected_to', 'direction' => 'downstream', 'port' => 'LAN 1',
        ]);

        $add()->assertForbidden();
        $this->assertSame(0, DeviceRelationship::query()->count());

        $this->grant('securityDevices.devices.update');
        $add()->assertRedirect();
        $this->assertTrue(DeviceRelationship::query()->active()->where('parent_device_id', $gateway->id)->where('child_device_id', $switch->id)->exists());
        $this->actingAs($this->viewer)->get('/security-devices/sites/'.$this->home->id.'?tab=network')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('network.links.can.change', true)->where('network.tree.mapped_count', 2));
    }
}
