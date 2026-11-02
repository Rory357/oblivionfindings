<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Cctv\Services\CameraPrivacy;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Modes\HouseModes;
use App\Domain\SecurityDevices\Modes\HouseModeSchedule;
use App\Domain\SecurityDevices\Modes\Models\HouseMode;
use App\Domain\SecurityDevices\Modes\Models\HouseModeEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Notifications\HouseModeNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * S&D Phase F: house modes (pg-sdr-12c, pop-ups 42 and 43) and rooms (pg-sdr-12d) on the Site page.
 * A mode takes only SafeActions (D12): it never disarms or unlocks, and "Check doors are locked"
 * reads the lock and tells the staff on shift, sending no command. Another Site's mode is 404.
 */

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Synthetic House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->other = Site::factory()->create(['name' => 'Synthetic Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);

    $this->person = function (array $keys, ?Site $site = null, string $name = 'Synthetic Manager'): User {
        $user = User::factory()->create(['name' => $name, 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    };
    $this->manager = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.modes.switch', 'securityDevices.automations.manage']);
    // Told about what a check finds: nobody is rostered on, so everyone at the Site who acknowledges alerts.
    $this->responder = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.alerts.acknowledge'], null, 'Synthetic Responder');

    $this->device = function (Site $site, array $attributes): Device {
        $device = Device::factory()->security()->create(['provider' => 'manual', 'ip_address' => '10.40.0.20', ...$attributes]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $site->id,
            'assignment_type' => 'permanent', 'assigned_at' => now()->subDays(3),
        ]);

        return $device;
    };
    $this->door = fn (Site $site, string $name = 'Synthetic front door') => ($this->device)($site, [
        'name' => $name, 'category' => 'access_control', 'subcategory' => 'card_reader',
        'manufacturer' => 'Ubiquiti', 'model' => 'UniFi Access G2 reader', 'provider' => 'unifi',
    ]);
    $this->panel = fn (Site $site) => ($this->device)($site, [
        'name' => 'Synthetic alarm', 'category' => 'alarm', 'subcategory' => 'panel',
        'manufacturer' => 'Ajax Systems', 'model' => 'Hub 2 Plus', 'config' => ['areas' => ['01' => 'Whole house']],
    ]);
    $this->camera = fn (Site $site) => ($this->device)($site, ['name' => 'Synthetic hallway camera', 'category' => 'cctv', 'subcategory' => 'dome_camera']);
    $this->event = fn (Device $device, string $type, int $minutesAgo, array $payload = []) => DeviceEvent::query()->create([
        'device_id' => $device->id, 'event_type' => $type, 'severity' => 'info', 'payload' => $payload,
        'source' => 'test', 'occurred_at' => now()->subMinutes($minutesAgo),
    ]);
    $this->mode = fn (Site $site, string $name, ?array $schedule, array $actions) => HouseMode::query()->create([
        'site_id' => $site->id, 'name' => $name, 'sort_order' => 1, 'schedule' => $schedule, 'actions' => $actions,
    ]);
    // The NZ wall clock's next 9:01 pm from now, so anything a mode turns on lasts into the future.
    $this->ninePm = function (): CarbonImmutable {
        $nz = CarbonImmutable::now(HouseModeSchedule::ZONE);
        $at = $nz->setTime(21, 1);

        return ($at->lessThan($nz->subMinutes(5)) ? $at->addDay() : $at)->utc();
    };
});

it('switches on schedule and runs only safe actions, skipping anything else whatever put it there', function (): void {
    Notification::fake();
    $door = ($this->door)($this->site);
    $panel = ($this->panel)($this->site);
    ($this->event)($door, 'door_locked', 5);
    // Stored behind the form's back: a disarm and an unlock are never run.
    $night = ($this->mode)($this->site, 'Night', ['days' => [1, 2, 3, 4, 5, 6, 7], 'starts' => '21:00'], [
        ['action' => 'alarm.disarm', 'panels' => [$panel->id]],
        ['action' => 'access.door.unlock_timed', 'doors' => [$door->id]],
        ['action' => 'door_locked_check', 'doors' => [$door->id]],
    ]);
    $at = ($this->ninePm)();

    expect(app(HouseModes::class)->tick($at))->toBe(['switched' => 1, 'checks' => 0]);
    $switch = HouseModeEvent::query()->where('kind', 'switch')->sole();
    expect($switch->mode_id)->toBe($night->id)
        ->and($switch->source)->toBe('schedule')
        ->and(array_column($switch->lines, 'text'))->toBe([
            'Synthetic front door locked',
            'Skipped an action modes may not take',
            'Skipped an action modes may not take',
        ]);
    expect(DeviceCommandRequest::query()->count())->toBe(0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.house_mode.switched']);

    // Once per start: the next minute switches nothing, and a start long gone isn't run late.
    expect(app(HouseModes::class)->tick($at->addMinute()))->toBe(['switched' => 0, 'checks' => 0])
        ->and(app(HouseModes::class)->tick($at->addHours(3))['switched'])->toBe(0);
});

it('refuses a mode with a disarm or an unlock (422), and devices from another Site', function (string $action): void {
    $door = ($this->door)($this->site);
    $panel = ($this->panel)($this->site);
    $night = ($this->mode)($this->site, 'Night', null, []);

    $this->actingAs($this->manager)->putJson("/security-devices/sites/{$this->site->id}/modes/{$night->id}", [
        'schedule' => null,
        'actions' => [['action' => $action, 'panels' => [$panel->id], 'doors' => [$door->id]]],
    ])->assertUnprocessable()->assertJsonValidationErrors('actions.0.action');
    $this->actingAs($this->manager)->postJson("/security-devices/sites/{$this->site->id}/modes", [
        'name' => 'Away', 'schedule' => null, 'actions' => [['action' => $action]],
    ])->assertUnprocessable()->assertJsonValidationErrors('actions.0.action');

    expect($night->fresh()->actions)->toBe([])
        ->and(HouseMode::query()->count())->toBe(1);
})->with(['alarm.disarm', 'access.door.unlock_timed', 'access.door.keep_unlocked', 'camera.privacy.disable']);

it('refuses a door from another Site in a mode', function (): void {
    $far = ($this->door)($this->other, 'Synthetic Lodge door');
    $night = ($this->mode)($this->site, 'Night', null, []);

    $this->actingAs($this->manager)->putJson("/security-devices/sites/{$this->site->id}/modes/{$night->id}", [
        'schedule' => null, 'actions' => [['action' => 'door_locked_check', 'doors' => [$far->id]]],
    ])->assertUnprocessable()->assertJsonValidationErrors('actions.0.doors');
});

it('lets someone with modes.switch but not automations.manage switch, but not add or edit', function (): void {
    Notification::fake();
    $switcher = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.modes.switch'], null, 'Synthetic Switcher');
    $away = ($this->mode)($this->site, 'Away', null, [['action' => 'notify', 'message' => 'Everyone is out']]);

    $this->actingAs($switcher)->from("/security-devices/sites/{$this->site->id}?tab=modes")
        ->post("/security-devices/sites/{$this->site->id}/modes/{$away->id}/switch")
        ->assertRedirect("/security-devices/sites/{$this->site->id}?tab=modes")
        ->assertSessionHas('success', 'Switched to Away.');
    $switch = HouseModeEvent::query()->sole();
    expect($switch->source)->toBe('hand')->and($switch->actor_user_id)->toBe($switcher->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.house_mode.switched', 'user_id' => $switcher->id]);
    Notification::assertSentTo($this->responder, HouseModeNotification::class);

    $this->actingAs($switcher)->putJson("/security-devices/sites/{$this->site->id}/modes/{$away->id}", ['schedule' => null, 'actions' => []])->assertForbidden();
    $this->actingAs($switcher)->postJson("/security-devices/sites/{$this->site->id}/modes", ['name' => 'Visitors', 'schedule' => null, 'actions' => []])->assertForbidden();

    $this->actingAs($switcher)->get("/security-devices/sites/{$this->site->id}?tab=modes")->assertOk()
        ->assertInertia(fn ($page) => $page->component('security-devices/sites/show')
            ->where('can.switch_mode', true)
            ->where('can.edit_modes', false)
            ->where('modes.edit', null)
            ->where('house.current.name', 'Away')
            ->where('modes.items.0.on', true)
            ->where('modes.log.0.title', 'Away')
            ->where('modes.log.0.sub', 'By hand · Synthetic Switcher · Staff on shift told'));
});

it('keeps another Site’s modes 404, from either Site’s address', function (): void {
    $far = ($this->mode)($this->other, 'Night', null, []);

    $this->actingAs($this->manager)->putJson("/security-devices/sites/{$this->other->id}/modes/{$far->id}", ['schedule' => null, 'actions' => []])->assertNotFound();
    $this->actingAs($this->manager)->putJson("/security-devices/sites/{$this->site->id}/modes/{$far->id}", ['schedule' => null, 'actions' => []])->assertNotFound();
    $this->actingAs($this->manager)->postJson("/security-devices/sites/{$this->other->id}/modes/{$far->id}/switch")->assertNotFound();
    $this->actingAs($this->manager)->postJson("/security-devices/sites/{$this->site->id}/modes/{$far->id}/switch")->assertNotFound();
    $this->actingAs($this->manager)->postJson("/security-devices/sites/{$this->other->id}/modes", ['name' => 'Away', 'schedule' => null, 'actions' => []])->assertNotFound();
    expect(HouseModeEvent::query()->count())->toBe(0);
});

it('reports a door that isn’t locked to the staff on shift and sends no command', function (): void {
    Notification::fake();
    $front = ($this->door)($this->site);
    $back = ($this->door)($this->site, 'Synthetic back door');
    ($this->event)($front, 'door_locked', 30);
    ($this->event)($front, 'door_unlocked', 3);
    ($this->event)($back, 'door_locked', 10);

    $this->actingAs($this->manager)->postJson("/security-devices/sites/{$this->site->id}/modes", [
        'name' => 'Night', 'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'starts' => '21:00'],
        'actions' => [['action' => 'door_locked_check', 'doors' => [$front->id, $back->id]]],
    ])->assertRedirect();
    $night = HouseMode::query()->sole();
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.house_mode.added']);

    $this->actingAs($this->manager)->postJson("/security-devices/sites/{$this->site->id}/modes/{$night->id}/switch")->assertRedirect();

    $switch = HouseModeEvent::query()->sole();
    expect(array_column($switch->lines, 'text'))->toBe([
        'Synthetic back door locked',
        'Synthetic front door not locked · staff on shift told',
    ]);
    Notification::assertSentTo($this->responder, HouseModeNotification::class, fn (HouseModeNotification $notice): bool => $notice->title === 'Synthetic House: Synthetic front door isn’t locked'
        && str_contains($notice->line, 'Lock it by hand'));
    expect(DeviceCommandRequest::query()->count())->toBe(0)
        ->and(DeviceAlert::query()->count())->toBe(0);
});

it('opens an alert for what a check finds when the mode says to, and checks the alarm when due', function (): void {
    Notification::fake();
    $door = ($this->door)($this->site);
    $panel = ($this->panel)($this->site);
    ($this->event)($door, 'door_unlocked', 3);
    ($this->event)($panel, 'alarm_armed', 2, ['partition' => '01', 'summary' => 'Area 01 armed (away).']);
    $night = ($this->mode)($this->site, 'Night', ['days' => [1, 2, 3, 4, 5, 6, 7], 'starts' => '21:00'], [
        ['action' => 'door_locked_check', 'doors' => [$door->id]],
        ['action' => 'alarm_armed_check', 'panels' => [$panel->id], 'after_minutes' => 15],
        ['action' => 'open_alert'],
    ]);
    $at = ($this->ninePm)();

    app(HouseModes::class)->tick($at);
    $alert = DeviceAlert::query()->sole();
    expect($alert->kind)->toBe('mode_door_check')
        ->and($alert->device_id)->toBe($door->id)
        ->and($alert->title)->toBe('Synthetic front door: not locked for Night');
    expect(HouseModeEvent::query()->where('state', 'pending')->count())->toBe(1);

    expect(app(HouseModes::class)->tick($at->addMinutes(16)))->toBe(['switched' => 0, 'checks' => 1]);
    $check = HouseModeEvent::query()->where('kind', 'check')->sole();
    expect($check->state)->toBe('done')
        ->and($check->title)->toBe('Alarm armed check passed')
        ->and($check->lines[0]['text'])->toStartWith('Whole house armed at ');
    expect(DeviceCommandRequest::query()->count())->toBe(0)
        ->and($night->fresh()->actions)->toHaveCount(3);
});

it('turns camera privacy on until its time, and switching modes never turns it off early', function (): void {
    Notification::fake();
    $camera = ($this->camera)($this->site);
    $night = ($this->mode)($this->site, 'Night', ['days' => [1, 2, 3, 4, 5, 6, 7], 'starts' => '21:00'], [
        ['action' => 'camera_privacy_on', 'cameras' => [$camera->id], 'until' => '06:00'],
    ]);
    $day = ($this->mode)($this->site, 'Day', null, []);
    $privacy = app(CameraPrivacy::class);
    expect($privacy->active($camera->id))->toBeFalse();

    $this->actingAs($this->manager)->postJson("/security-devices/sites/{$this->site->id}/modes/{$night->id}/switch")->assertRedirect();
    $until = $privacy->until($camera->id);
    expect($until)->not->toBeNull()
        ->and($until->setTimezone(HouseModeSchedule::ZONE)->format('H:i'))->toBe('06:00');
    // Refused like privacy hours: the same 409 every picture path gives.
    expect(fn () => $privacy->refuse($camera->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    $this->actingAs($this->manager)->postJson("/security-devices/sites/{$this->site->id}/modes/{$day->id}/switch")->assertRedirect();
    expect($privacy->active($camera->id))->toBeTrue();
});

it('shows rooms with their devices, and the devices not in a room yet', function (): void {
    $lounge = SiteRoom::query()->create(['site_id' => $this->site->id, 'name' => 'Lounge', 'sort_order' => 1]);
    SiteRoom::query()->create(['site_id' => $this->other->id, 'name' => 'Synthetic Lodge kitchen', 'sort_order' => 1]);
    $placed = Device::factory()->security()->create(['name' => 'Synthetic lounge camera', 'provider' => 'manual']);
    DeviceAssignment::query()->create([
        'device_id' => $placed->id, 'assignable_type' => DeviceAssignment::TARGET_ROOM, 'assignable_id' => $lounge->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subDay(),
    ]);
    ($this->door)($this->site);
    ($this->door)($this->other, 'Synthetic Lodge door');

    $this->actingAs($this->manager)->get("/security-devices/sites/{$this->site->id}?tab=rooms")->assertOk()
        ->assertInertia(fn ($page) => $page->component('security-devices/sites/show')
            ->where('tab', 'rooms')
            ->where('modes', null)
            ->where('rooms.room_count', 1)
            ->has('rooms.items', 1)
            ->where('rooms.items.0.name', 'Lounge')
            ->where('rooms.items.0.sub', '1 device')
            ->where('rooms.unplaced.count', 1)
            ->where('rooms.unplaced.names', ['Synthetic front door'])
            ->where('rooms.profile_href', null));
});
