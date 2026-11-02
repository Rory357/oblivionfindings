<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Automations\AutomationEngine;
use App\Domain\SecurityDevices\Automations\AutomationWords;
use App\Domain\SecurityDevices\Automations\Models\Automation;
use App\Domain\SecurityDevices\Automations\Models\AutomationRun;
use App\Domain\SecurityDevices\Automations\SafeActions;
use App\Domain\SecurityDevices\Cctv\Models\CameraPrivacyOverride;
use App\Domain\SecurityDevices\Iot\Readings\SensorReadingHistory;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Modes\Models\HouseMode;
use App\Domain\SecurityDevices\Modes\Models\HouseModeEvent;
use App\Domain\SecurityDevices\Screens\Alerts\AutomationsPresenter;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Notifications\SecurityDeviceAutomationNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * S&D Phase F (pg-sdr-13b, pop-ups 40 and 41): a house's automations. Only the safe actions (D12):
 * a disarm or an unlock is refused and never offered. "Test it" tells only the person testing,
 * marked TEST, and changes nothing. A real trigger runs the actions; another Site's automation is a
 * 404; saving needs securityDevices.automations.manage.
 */

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Totara House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->other = Site::factory()->create(['name' => 'Rimu Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);

    $this->person = function (array $keys, ?Site $site = null): User {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    };
    $this->manager = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'securityDevices.automations.manage']);
    // Told as "the staff on shift": nobody is rostered, so everyone at the Site who looks after alerts.
    $this->responder = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.alerts.acknowledge']);
    $this->device = function (string $name, Site $site, array $attributes): Device {
        $device = Device::factory()->create(['name' => $name, 'last_seen_at' => now(), ...$attributes]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $site->id,
            'assignment_type' => 'permanent', 'assigned_at' => now()->subWeek(),
        ]);

        return $device;
    };
    $sensor = ['domain' => 'iot_healthcare', 'category' => 'environmental', 'manufacturer' => 'Milesight', 'model' => 'EM300-TH'];
    $this->tap = ($this->device)('Hot water at Room 3 tap', $this->site, $sensor);
    $this->camera = ($this->device)('Hallway camera', $this->site, ['domain' => 'security', 'category' => 'cctv', 'subcategory' => 'dome_camera']);
    $this->payload = fn (array $actions, array $overrides = []): array => [
        'site_id' => $this->site->id,
        'name' => 'Hot water too hot',
        'is_on' => true,
        'trigger' => ['kind' => 'reading', 'device_id' => $this->tap->id, 'metric' => 'temperature', 'direction' => 'above', 'value' => 45],
        'conditions' => ['between' => null],
        'actions' => $actions,
        ...$overrides,
    ];
    $this->safeActions = [
        ['action' => 'notify', 'who' => 'on_shift', 'email' => false, 'push' => false, 'message' => 'Check the tap'],
        ['action' => 'open_alert'],
        ['action' => 'camera_privacy_on', 'cameras' => [$this->camera->id], 'until' => '06:00'],
    ];
});

it('refuses an automation that would disarm or unlock (422), and never offers one', function (string $unsafe): void {
    $this->actingAs($this->manager)
        ->postJson('/security-devices/automations', ($this->payload)([['action' => 'notify', 'who' => 'on_shift'], ['action' => $unsafe]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['actions.1.action' => 'That action can’t be automated: automations and house modes never disarm or unlock.']);
    expect(Automation::query()->count())->toBe(0);

    $offered = array_column(app(AutomationsPresenter::class)->formOptions($this->manager)['actions'], 'key');
    expect($offered)->toBe(array_keys(SafeActions::ACTIONS))
        ->and(array_intersect($offered, SafeActions::NEVER))->toBe([])
        ->and(implode(' ', $offered))->not->toContain('disarm')->not->toContain('unlock');
})->with(['alarm.disarm', 'access.door.unlock_timed', 'access.door.keep_unlocked', 'unlock']);

it('saves a safe automation, audited, and shows it as when, where and then', function (): void {
    $this->actingAs($this->manager)->postJson('/security-devices/automations', ($this->payload)($this->safeActions))->assertCreated();
    $automation = Automation::query()->sole();
    expect($automation->site_id)->toBe($this->site->id)
        ->and($automation->trigger_kind)->toBe('reading')
        ->and(array_column($automation->actionList(), 'action'))->toBe(['notify', 'open_alert', 'camera_privacy_on']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.automation.added', 'auditable_id' => $automation->id]);

    $row = app(AutomationsPresenter::class)->rows($this->manager)[0];
    expect($row['when'])->toBe('Hot water at Room 3 tap: temperature above 45 °C')
        ->and($row['where'])->toBe('Totara House')
        ->and($row['then'])->toBe('Tell the staff on shift (app) · open an alert for the staff on shift · turn camera privacy on until 6:00 am for Hallway camera')
        ->and($row['last_run'])->toBeNull();
});

it('tests an automation: only the tester is told, marked TEST, and no alert, camera change or command happens', function (): void {
    Notification::fake();
    $this->actingAs($this->manager)->postJson('/security-devices/automations', ($this->payload)($this->safeActions))->assertCreated();
    $automation = Automation::query()->sole();

    $response = $this->actingAs($this->manager)->postJson("/security-devices/automations/{$automation->id}/test")->assertOk();

    Notification::assertSentTo($this->manager, SecurityDeviceAutomationNotification::class,
        fn (SecurityDeviceAutomationNotification $notice): bool => $notice->test && str_starts_with($notice->title, 'TEST · ') && str_starts_with($notice->line, 'TEST · '));
    Notification::assertNotSentTo($this->responder, SecurityDeviceAutomationNotification::class);
    Notification::assertCount(1);
    expect(DeviceAlert::query()->count())->toBe(0)
        ->and(CameraPrivacyOverride::query()->count())->toBe(0)
        ->and(DB::table('device_command_requests')->count())->toBe(0)
        ->and(DB::table('device_command_attempts')->count())->toBe(0);

    $run = AutomationRun::query()->sole();
    expect($run->test)->toBeTrue()->and((int) $run->actor_user_id)->toBe($this->manager->id);
    $texts = collect($response->json('run.lines'))->pluck('text')->implode("\n");
    expect($texts)->toContain('You got “TEST · Hot water too hot” in the app')
        ->toContain('A real run would tell the staff on shift')
        ->toContain('No alert was opened')
        ->toContain('No camera changed')
        ->and($response->json('run.result'))->toBe('All 3 actions worked');
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.automation.tested', 'user_id' => $this->manager->id]);
    expect($automation->fresh()->last_run_test)->toBeTrue();
});

it('runs the safe actions when a reading crosses its value, once per crossing', function (): void {
    Notification::fake();
    $this->actingAs($this->manager)->postJson('/security-devices/automations', ($this->payload)($this->safeActions))->assertCreated();
    $history = app(SensorReadingHistory::class);
    $start = CarbonImmutable::now('UTC')->subMinutes(30)->startOfMinute();

    $history->record($this->tap->id, ['temperature' => 41.0], $start);
    expect(AutomationRun::query()->count())->toBe(0);
    $history->record($this->tap->id, ['temperature' => 52.0], $start->addMinute());

    $run = AutomationRun::query()->sole();
    expect($run->test)->toBeFalse()->and($run->outcome)->toBe(AutomationRun::OK)->and((int) $run->device_id)->toBe($this->tap->id);
    Notification::assertSentTo($this->responder, SecurityDeviceAutomationNotification::class,
        fn (SecurityDeviceAutomationNotification $notice): bool => ! $notice->test && $notice->title === 'Hot water too hot' && str_contains($notice->line, 'Check the tap'));
    $alert = DeviceAlert::query()->where('kind', 'automation')->sole();
    expect((int) $alert->device_id)->toBe($this->tap->id);
    $privacy = CameraPrivacyOverride::query()->sole();
    expect((int) $privacy->device_id)->toBe($this->camera->id)->and($privacy->source)->toBe('automation')
        ->and(DB::table('device_command_requests')->count())->toBe(0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.automation.ran', 'auditable_id' => $run->id]);

    // Still over: no new run. Under, then over again after the pause: a second run.
    $history->record($this->tap->id, ['temperature' => 55.0], $start->addMinutes(2));
    $history->record($this->tap->id, ['temperature' => 40.0], $start->addMinutes(3));
    expect(AutomationRun::query()->count())->toBe(1);
    $history->record($this->tap->id, ['temperature' => 50.0], $start->addMinutes(10));
    expect(AutomationRun::query()->count())->toBe(2);
});

it('runs a time-of-day automation once per start, in NZ time', function (): void {
    Notification::fake();
    $this->actingAs($this->manager)->postJson('/security-devices/automations', ($this->payload)(
        [['action' => 'notify', 'who' => 'on_shift']],
        ['name' => 'Evening check', 'trigger' => ['kind' => 'time', 'at' => '21:00', 'days' => [1, 2, 3, 4, 5, 6, 7]]],
    ))->assertCreated();
    $engine = app(AutomationEngine::class);
    $nz = fn (string $time): CarbonImmutable => CarbonImmutable::parse($time, AutomationWords::ZONE)->utc();

    expect($engine->tick($nz('2026-11-03 20:59')))->toBe(0)
        ->and($engine->tick($nz('2026-11-03 21:00')))->toBe(1)
        ->and($engine->tick($nz('2026-11-03 21:01')))->toBe(0)
        ->and($engine->tick($nz('2026-11-04 21:00')))->toBe(1);
    Notification::assertSentTo($this->responder, SecurityDeviceAutomationNotification::class);
});

it('runs only while the house is in one of its modes ("House mode is")', function (): void {
    Notification::fake();
    $day = HouseMode::query()->create(['site_id' => $this->site->id, 'name' => 'Day', 'sort_order' => 1, 'schedule' => null, 'actions' => []]);
    $night = HouseMode::query()->create(['site_id' => $this->site->id, 'name' => 'Night', 'sort_order' => 2, 'schedule' => null, 'actions' => []]);
    $elsewhere = HouseMode::query()->create(['site_id' => $this->other->id, 'name' => 'Away', 'sort_order' => 1, 'schedule' => null, 'actions' => []]);
    $time = fn (array $modes): array => ($this->payload)(
        [['action' => 'notify', 'who' => 'on_shift']],
        ['name' => 'Night check', 'trigger' => ['kind' => 'time', 'at' => '21:00', 'days' => [1, 2, 3, 4, 5, 6, 7]], 'conditions' => ['between' => null, 'modes' => $modes]],
    );
    // Another Site's mode is refused.
    $this->actingAs($this->manager)->postJson('/security-devices/automations', $time([$elsewhere->id]))
        ->assertUnprocessable()->assertJsonValidationErrors(['conditions.modes' => 'Choose house modes of this Site.']);
    $this->actingAs($this->manager)->postJson('/security-devices/automations', $time([$night->id]))->assertCreated();
    expect(Automation::query()->sole()->conditions['modes'])->toBe([$night->id])
        ->and(app(AutomationsPresenter::class)->rows($this->manager)[0]['when'])->toBe('At 9:00 pm every day in Night mode');

    $switch = fn (HouseMode $mode, string $at) => HouseModeEvent::query()->create([
        'site_id' => $this->site->id, 'mode_id' => $mode->id, 'kind' => HouseModeEvent::KIND_SWITCH, 'state' => HouseModeEvent::STATE_DONE,
        'source' => 'hand', 'title' => $mode->name, 'lines' => [], 'at' => CarbonImmutable::parse($at, AutomationWords::ZONE)->utc(),
    ]);
    $engine = app(AutomationEngine::class);
    $nz = fn (string $time): CarbonImmutable => CarbonImmutable::parse($time, AutomationWords::ZONE)->utc();

    $switch($day, '2026-11-03 07:00');
    expect($engine->tick($nz('2026-11-03 21:00')))->toBe(0);
    $switch($night, '2026-11-04 20:30');
    expect($engine->tick($nz('2026-11-04 21:00')))->toBe(1);
});

it('never finds another Site’s automation (404)', function (): void {
    $elsewhere = Automation::query()->create([
        'site_id' => $this->other->id, 'name' => 'Rimu evening check', 'trigger_kind' => 'time',
        'trigger' => ['kind' => 'time', 'at' => '21:00', 'days' => [1, 2, 3, 4, 5, 6, 7]], 'conditions' => ['between' => null],
        'actions' => [['action' => 'notify', 'who' => 'on_shift', 'role' => null, 'email' => false, 'push' => false, 'message' => null]],
        'is_on' => true, 'version' => 1,
    ]);
    $as = $this->actingAs($this->manager);

    $as->putJson("/security-devices/automations/{$elsewhere->id}", ['version' => 1, ...($this->payload)([['action' => 'notify', 'who' => 'on_shift']])])->assertNotFound();
    $as->postJson("/security-devices/automations/{$elsewhere->id}/test")->assertNotFound();
    $as->postJson("/security-devices/automations/{$elsewhere->id}/off", ['version' => 1, 'reason' => 'Not needed at this house'])->assertNotFound();
    $as->deleteJson("/security-devices/automations/{$elsewhere->id}", ['version' => 1])->assertNotFound();
    $as->postJson('/security-devices/automations', ($this->payload)([['action' => 'notify', 'who' => 'on_shift']], ['site_id' => $this->other->id]))->assertNotFound();
    expect(app(AutomationsPresenter::class)->rows($this->manager))->toBe([])
        ->and($elsewhere->fresh()->is_on)->toBeTrue();
});

it('needs securityDevices.automations.manage to save, test or change one (403)', function (): void {
    $viewer = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'securityDevices.alerts.acknowledge']);
    $this->actingAs($viewer)->postJson('/security-devices/automations', ($this->payload)($this->safeActions))->assertForbidden();
    expect(Automation::query()->count())->toBe(0);

    $this->actingAs($this->manager)->postJson('/security-devices/automations', ($this->payload)($this->safeActions))->assertCreated();
    $automation = Automation::query()->sole();
    $this->actingAs($viewer)->putJson("/security-devices/automations/{$automation->id}", ['version' => 1, ...($this->payload)($this->safeActions)])->assertForbidden();
    $this->actingAs($viewer)->postJson("/security-devices/automations/{$automation->id}/test")->assertForbidden();
    $this->actingAs($viewer)->postJson("/security-devices/automations/{$automation->id}/off", ['version' => 1, 'reason' => 'Not needed any more'])->assertForbidden();
    expect($automation->fresh()->is_on)->toBeTrue();

    // A manager turns it off with a reason (kept in the history), then removes it; it stays in history.
    $this->actingAs($this->manager)->postJson("/security-devices/automations/{$automation->id}/off", ['version' => 1, 'reason' => 'The tap is being replaced'])->assertOk();
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.automation.turned_off', 'auditable_id' => $automation->id]);
    $this->actingAs($this->manager)->deleteJson("/security-devices/automations/{$automation->id}", ['version' => 2])->assertOk();
    expect($automation->fresh()->removed_at)->not->toBeNull()
        ->and(app(AutomationsPresenter::class)->rows($this->manager))->toBe([]);
});
