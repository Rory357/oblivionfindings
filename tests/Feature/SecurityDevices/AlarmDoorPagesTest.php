<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Client;
use App\Models\Integration\IntegrationEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S&D rebuild Phase E: the alarm panel's page and the door's page. Another Site's panel or door is
 * 404; arm, disarm and unlock are refused without their keys; a report-only (Ajax) panel offers no
 * arm or disarm; and a resident is never named on a door's events (D19).
 */
class AlarmDoorPagesTest extends TestCase
{
    use RefreshDatabase;

    private Site $home;

    private Site $other;

    /** Everything a manager who may arm, disarm and unlock would hold. */
    private const array ALL_KEYS = [
        'securityDevices.viewAny',
        'securityDevices.devices.view',
        'securityDevices.events.view',
        'securityDevices.commands.operate',
        'securityDevices.commands.control',
        'securityDevices.alarms.arm',
        'securityDevices.alarms.disarm',
        'securityDevices.doors.unlock',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->home = Site::factory()->create(['name' => 'Synthetic House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->other = Site::factory()->create(['name' => 'Synthetic Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    }

    /** @param list<string> $keys */
    private function person(array $keys, string $name = 'Synthetic Manager'): User
    {
        $user = User::factory()->create(['name' => $name, 'approved_at' => now()]);
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
    private function device(Site $site, array $attributes): Device
    {
        $device = Device::factory()->security()->create([
            'provider' => 'manual',
            'ip_address' => '10.40.0.20',
            ...$attributes,
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now()->subDays(3),
        ]);

        return $device;
    }

    private function ajaxPanel(Site $site, string $name = 'Synthetic House alarm'): Device
    {
        return $this->device($site, [
            'name' => $name,
            'category' => 'alarm',
            'subcategory' => 'panel',
            'manufacturer' => 'Ajax Systems',
            'model' => 'Hub 2 Plus',
            // Even a record that lists arm and disarm can't make a report-only panel controllable.
            'config' => [
                'areas' => ['01' => 'Whole house'],
                'zones' => ['001' => 'Front door', '002' => 'Laundry window'],
                'management' => ['capabilities' => ['alarm.arm', 'alarm.disarm']],
            ],
        ]);
    }

    private function door(Site $site, string $name = 'Synthetic front door lock'): Device
    {
        return $this->device($site, [
            'name' => $name,
            'category' => 'access_control',
            'subcategory' => 'card_reader',
            'manufacturer' => 'Ubiquiti',
            'model' => 'UniFi Access G2 reader',
            'provider' => 'unifi',
            'config' => ['management' => ['unifi_access' => ['unlock_duration_seconds' => 5]]],
        ]);
    }

    public function test_another_sites_panel_and_door_are_404_even_for_their_commands(): void
    {
        $viewer = $this->person(self::ALL_KEYS);
        $panel = $this->ajaxPanel($this->other, 'Synthetic Lodge alarm');
        $door = $this->door($this->other, 'Synthetic Lodge door lock');

        $this->actingAs($viewer)->get('/security-devices/devices/'.$panel->id)->assertNotFound();
        $this->actingAs($viewer)->get('/security-devices/devices/'.$panel->id.'?tab=zones')->assertNotFound();
        $this->actingAs($viewer)->get('/security-devices/devices/'.$door->id)->assertNotFound();
        $this->actingAs($viewer)->get('/security-devices/devices/'.$door->id.'?tab=events')->assertNotFound();

        $this->actingAs($viewer)->post('/security-devices/devices/'.$panel->id.'/safety/arm', ['areas' => ['01'], 'idempotency_key' => 'test-arm-1'])->assertNotFound();
        $this->actingAs($viewer)->post('/security-devices/devices/'.$panel->id.'/safety/disarm', ['areas' => ['01'], 'reason' => 'Synthetic reason for a disarm', 'idempotency_key' => 'test-disarm-1'])->assertNotFound();
        $this->actingAs($viewer)->post('/security-devices/devices/'.$door->id.'/safety/unlock', ['reason' => 'Synthetic reason for an unlock', 'idempotency_key' => 'test-unlock-1'])->assertNotFound();
        $this->assertSame(0, DeviceCommandRequest::query()->count());
    }

    public function test_arm_disarm_and_unlock_are_refused_without_their_keys(): void
    {
        $viewer = $this->person(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'securityDevices.commands.operate', 'securityDevices.commands.control']);
        $panel = $this->ajaxPanel($this->home);
        $door = $this->door($this->home);

        $this->actingAs($viewer)->post('/security-devices/devices/'.$panel->id.'/safety/arm', ['areas' => ['01'], 'idempotency_key' => 'test-arm-2'])->assertForbidden();
        $this->actingAs($viewer)->post('/security-devices/devices/'.$panel->id.'/safety/disarm', ['areas' => ['01'], 'reason' => 'Synthetic reason for a disarm', 'idempotency_key' => 'test-disarm-2'])->assertForbidden();
        $this->actingAs($viewer)->post('/security-devices/devices/'.$door->id.'/safety/unlock', ['reason' => 'Synthetic reason for an unlock', 'idempotency_key' => 'test-unlock-2'])->assertForbidden();
        $this->assertSame(0, DeviceCommandRequest::query()->count());

        // The page offers none of them either.
        $this->actingAs($viewer)->get('/security-devices/devices/'.$door->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('security-devices/devices/door')
                ->where('can.unlock', false)
                ->where('control.unlock', null));
    }

    public function test_an_ajax_panel_is_view_only_with_no_arm_or_disarm(): void
    {
        $viewer = $this->person(self::ALL_KEYS);
        $panel = $this->ajaxPanel($this->home);
        DeviceEvent::query()->create([
            'device_id' => $panel->id,
            'event_type' => 'alarm_armed',
            'severity' => 'info',
            'payload' => ['partition' => '01', 'summary' => 'Area 01 armed (away).'],
            'source' => 'sia_dc09',
            'occurred_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($viewer)->get('/security-devices/devices/'.$panel->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('security-devices/devices/alarm')
                ->where('device.kind', 'alarm_panel')
                ->where('control.view_only', true)
                ->where('control.arm', null)
                ->where('control.disarm', null)
                ->where('can.arm', false)
                ->where('can.disarm', false)
                ->where('panel.state', 'armed')
                ->where('panel.label', 'Armed (away)'));

        // Asking anyway is refused by the governed framework: nothing is created.
        $response = $this->actingAs($viewer)->post('/security-devices/devices/'.$panel->id.'/safety/disarm', ['areas' => ['01'], 'reason' => 'Synthetic reason for a disarm', 'idempotency_key' => 'test-disarm-3']);
        $this->assertTrue($response->isForbidden() || session()->has('errors'), 'A report-only panel must refuse a disarm.');
        $this->assertSame(0, DeviceCommandRequest::query()->count());
    }

    public function test_a_resident_is_never_named_on_a_doors_events(): void
    {
        $viewer = $this->person(self::ALL_KEYS);
        $this->person([], 'Synthetic Staffer');
        Client::factory()->create(['site_id' => $this->home->id, 'status' => 'active', 'first_name' => 'Mereana', 'last_name' => 'Synthetica', 'preferred_name' => null]);
        $door = $this->door($this->home);

        $entry = fn (string $actor, int $minutes) => IntegrationEvent::factory()->create([
            'site_id' => $this->home->id,
            'canonical_device_id' => $door->id,
            'hardware_id' => null,
            'provider' => 'unifi',
            'source_app' => 'access',
            'source_event_id' => 'access-log-'.Str::uuid(),
            'occurred_at' => now()->subMinutes($minutes),
            'received_at' => now()->subMinutes($minutes),
            'severity' => 'info',
            'event_type' => 'door_opened',
            'normalized_payload' => ['summary' => 'NFC: '.$actor, 'actor_name' => $actor, 'direction' => 'NFC', 'provider_event_type' => 'access.door.unlock'],
            'raw_payload' => [],
        ]);
        $entry('Mereana Synthetica', 5);
        $entry('Synthetic Staffer', 10);
        $entry('Unplaced Visitor', 15);

        foreach (['', '?tab=events'] as $query) {
            $response = $this->actingAs($viewer)->get('/security-devices/devices/'.$door->id.$query)->assertOk();
            $props = json_encode($response->viewData('page')['props'], JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString('Mereana', $props, 'A resident’s name must never reach the door page.');
            $this->assertStringNotContainsString('Synthetica', $props);
            $this->assertStringNotContainsString('Unplaced Visitor', $props, 'Someone Oblivion can’t place could be a resident: no name.');
            $this->assertStringContainsString('Resident fob', $props);
            $this->assertStringContainsString('Synthetic Staffer', $props);
        }
    }
}
