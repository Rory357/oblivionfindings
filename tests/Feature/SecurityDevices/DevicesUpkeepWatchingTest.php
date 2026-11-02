<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S&D v13: Devices › Upkeep › Not watched (pg-sdr-2e) and Retired (pg-sdr-2f). Another Site's
 * device never shows, a reason written at another Site is never shown, and a removed device keeps
 * only the history from while it was at the viewer's Sites.
 */
class DevicesUpkeepWatchingTest extends TestCase
{
    use RefreshDatabase;

    private Site $home;

    private Site $other;

    private User $viewer;

    private User $admin;

    private MonitoringProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->home = Site::factory()->create(['name' => 'Synthetic House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->other = Site::factory()->create(['name' => 'Synthetic Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->viewer = $this->viewerWith(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view']);
        $this->admin = User::factory()->create(['approved_at' => now(), 'name' => 'Synthetic Admin']);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->profile = MonitoringProfile::factory()->create(['interval_seconds' => 60]);
    }

    /** @param list<string> $keys */
    private function viewerWith(array $keys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->home->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function device(Site $site, array $attributes = [], int $daysAgo = 3): Device
    {
        $device = Device::factory()->itInfrastructure()->create([
            'manufacturer' => 'Synthetic',
            'model' => 'Model 1',
            'provider' => 'manual',
            ...$attributes,
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now()->subDays($daysAgo),
        ]);

        return $device;
    }

    private function stoppedPing(Device $device, Site $site, string $reason, User $by): Monitor
    {
        $monitor = Monitor::factory()->create([
            'device_id' => $device->id,
            'profile_id' => $this->profile->id,
            'kind' => MonitorKind::Icmp,
            'affects_availability' => true,
            'is_enabled' => false,
            'current_state' => MonitorState::Unknown,
        ]);
        $audit = AuditLog::query()->create([
            'user_id' => $by->id,
            'action' => 'monitoring.monitor.deactivated',
            'auditable_type' => $monitor->getMorphClass(),
            'auditable_id' => $monitor->id,
            'meta' => ['actor_id' => $by->id, 'site_id' => $site->id, 'device_id' => $device->id, 'kind' => 'icmp', 'reason_code' => $reason],
        ]);
        $audit->forceFill(['created_at' => now()->subDays(2)])->save();

        return $monitor;
    }

    public function test_not_watched_lists_only_the_viewers_devices_with_the_stored_reason_in_plain_words(): void
    {
        $screen = $this->device($this->home, ['name' => 'Synthetic meeting screen', 'notes' => 'Switched off at the wall after hours', 'location_description' => 'Meeting room']);
        $this->stoppedPing($screen, $this->home, 'obsolete', $this->admin);
        $this->device($this->home, ['name' => 'Synthetic phone base', 'created_by_user_id' => $this->admin->id]);
        $watched = $this->device($this->home, ['name' => 'Synthetic watched switch']);
        Monitor::factory()->create(['device_id' => $watched->id, 'profile_id' => $this->profile->id, 'is_enabled' => true, 'current_state' => MonitorState::Healthy]);
        $this->device($this->other, ['name' => 'Synthetic Lodge screen']);

        $response = $this->actingAs($this->viewer)->get('/security-devices/devices?tab=upkeep&upkeep=not_watched');
        $response->assertOk()->assertInertia(function ($page): void {
            $page->component('security-devices/devices/index')
                ->where('upkeep', 'not_watched')
                ->where('batteries', null)
                ->where('retired', null)
                ->where('not_watched.meta.total', 2)
                ->where('can.watch', false);
            $rows = collect($page->toArray()['props']['not_watched']['rows'])->keyBy('name');
            $this->assertSame(['Synthetic meeting screen', 'Synthetic phone base'], $rows->keys()->sort()->values()->all());
            $screen = $rows['Synthetic meeting screen'];
            $this->assertSame('Stopped by Synthetic Admin · no longer needed', $screen['why']);
            $this->assertSame('Switched off at the wall after hours', $screen['note']);
            $this->assertSame('Synthetic House · Meeting room', $screen['where']);
            $this->assertNotNull($screen['since']);
            $this->assertSame('Added by Synthetic Admin with checks off', $rows['Synthetic phone base']['why']);
            // The recent log names the stop and its reason.
            $recent = collect($page->toArray()['props']['not_watched']['recent']);
            $this->assertTrue($recent->contains(fn (array $line): bool => $line['title'] === 'Synthetic meeting screen · watching stopped' && str_contains((string) $line['sub'], 'no longer needed')));
        });
        $this->assertStringNotContainsString('Synthetic Lodge screen', $response->getContent());
        $this->assertStringNotContainsString('Synthetic watched switch', $response->getContent());

        // People who manage checks get Watch this device.
        $manager = $this->viewerWith(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.monitoring.manage']);
        $this->actingAs($manager)->get('/security-devices/devices?tab=upkeep&upkeep=not_watched')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.watch', true));
    }

    public function test_a_reason_recorded_at_another_site_is_never_shown(): void
    {
        $moved = $this->device($this->home, ['name' => 'Synthetic moved screen']);
        $this->stoppedPing($moved, $this->other, 'replaced', $this->admin);

        $this->actingAs($this->viewer)->get('/security-devices/devices?tab=upkeep&upkeep=not_watched')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $row = $page->toArray()['props']['not_watched']['rows'][0];
                $this->assertSame('Synthetic moved screen', $row['name']);
                $this->assertStringNotContainsString('replaced', $row['why']);
                $this->assertStringNotContainsString('Stopped by', $row['why']);
            });
    }

    public function test_retired_lists_removed_devices_from_the_viewers_sites_with_who_when_and_whats_kept(): void
    {
        $router = $this->device($this->home, ['name' => 'Synthetic old router', 'subcategory' => 'router', 'location_description' => 'Comms cupboard']);
        DeviceEvent::query()->create(['device_id' => $router->id, 'event_type' => 'offline', 'severity' => 'critical', 'source' => 'native', 'occurred_at' => now()->subDay()]);
        $theirs = $this->device($this->other, ['name' => 'Synthetic Lodge old camera']);
        $live = $this->device($this->home, ['name' => 'Synthetic live switch']);

        $this->actingAs($this->admin)->delete("/security-devices/devices/{$router->id}")->assertRedirect();
        $this->actingAs($this->admin)->delete("/security-devices/devices/{$theirs->id}")->assertRedirect();
        $this->assertSoftDeleted('devices', ['id' => $router->id]);
        // The admin's "Removed …" flash would ride on the next request in this test; spend it first.
        $this->actingAs($this->admin)->get('/security-devices/devices')->assertOk();

        $response = $this->actingAs($this->viewer)->get('/security-devices/devices?tab=upkeep&upkeep=retired');
        $response->assertOk()->assertInertia(function ($page) use ($router): void {
            $page->where('upkeep', 'retired')
                ->where('retired.meta.total', 1)
                ->where('retired.rows.0.id', $router->id)
                ->where('retired.rows.0.name', 'Synthetic old router')
                ->where('retired.rows.0.was_at', 'Synthetic House · Comms cupboard')
                ->where('retired.rows.0.by', 'Synthetic Admin')
                ->where('retired.rows.0.kept', 'Logbook')
                // The first row is open, with its last lines: Removed is always the newest.
                ->where('retired.selected.id', $router->id)
                ->where('retired.selected.lines.0.title', 'Removed')
                ->where('retired.selected.lines.0.who', 'Synthetic Admin');
            $titles = collect($page->toArray()['props']['retired']['selected']['lines'])->pluck('title');
            $this->assertContains('Went offline', $titles->all());
            $this->assertContains('Added at Synthetic House', $titles->all());
        });
        $this->assertStringNotContainsString('Synthetic Lodge old camera', $response->getContent());
        $this->assertStringNotContainsString('Synthetic live switch', $response->getContent());

        // Asking for another Site's removed device by hand opens nothing.
        $this->actingAs($this->viewer)->get('/security-devices/devices?tab=upkeep&upkeep=retired&retired='.$theirs->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('retired.selected', null));
        // Nor does asking for another Site.
        $this->actingAs($this->viewer)->get('/security-devices/devices?tab=upkeep&upkeep=retired&site='.$this->other->id)->assertNotFound();
        // The removed device's own page stays closed: it's read here, never reopened.
        $this->actingAs($this->viewer)->get('/security-devices/devices/'.$router->id)->assertNotFound();
    }

    public function test_a_removed_device_keeps_only_the_history_from_while_it_was_at_the_viewers_sites(): void
    {
        $device = Device::factory()->itInfrastructure()->create(['name' => 'Synthetic travelling switch', 'subcategory' => 'switch', 'provider' => 'manual']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $this->other->id,
            'assigned_at' => now()->subDays(10),
            'released_at' => now()->subDays(5),
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $this->home->id,
            'assigned_at' => now()->subDays(5),
        ]);
        DeviceEvent::query()->create(['device_id' => $device->id, 'event_type' => 'lodge_only_event', 'severity' => 'warning', 'source' => 'native', 'occurred_at' => now()->subDays(7)]);
        DeviceEvent::query()->create(['device_id' => $device->id, 'event_type' => 'house_event', 'severity' => 'warning', 'source' => 'native', 'occurred_at' => now()->subDays(2)]);

        $this->actingAs($this->admin)->delete("/security-devices/devices/{$device->id}")->assertRedirect();

        $this->actingAs($this->viewer)->get('/security-devices/devices?tab=upkeep&upkeep=retired&retired='.$device->id)
            ->assertOk()
            ->assertInertia(function ($page): void {
                $titles = collect($page->toArray()['props']['retired']['selected']['lines'])->pluck('title')->all();
                $this->assertContains('House event', $titles);
                $this->assertContains('Moved to Synthetic House', $titles);
                $this->assertNotContains('Lodge only event', $titles);
                $this->assertNotContains('Added at Synthetic Lodge', $titles);
            });

        // Without events.view, alert lines are left out; the moves and the removal stay.
        $limited = $this->viewerWith(['securityDevices.viewAny', 'securityDevices.devices.view']);
        $this->actingAs($limited)->get('/security-devices/devices?tab=upkeep&upkeep=retired&retired='.$device->id)
            ->assertOk()
            ->assertInertia(function ($page): void {
                $titles = collect($page->toArray()['props']['retired']['selected']['lines'])->pluck('title')->all();
                $this->assertNotContains('House event', $titles);
                $this->assertContains('Removed', $titles);
            });
    }
}
