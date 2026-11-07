<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlertEvent;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\Client;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\MedicationOnCallRule;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Notifications\SecurityDeviceAlertNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Database\Seeders\SecurityDevicesSignalSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Safety fix round (7 Nov 2026), bug 3 and F3. Stephan: "Default not snoozable but there should be
 * a setting to enable snooze and be able to set time". Safety and care alerts can't be snoozed
 * unless Setup allows it (a loosening that needs a reason), then only up to the longest snooze;
 * every snooze that ends reminds again; and one nobody says they're on goes to the on-call manager
 * and, when only Security & devices knows about it or nobody else is on call, to Control Room.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->at = function (string $iso): void {
        CarbonImmutable::setTestNow($iso);
        Carbon::setTestNow($iso);
    };
    ($this->at)('2026-11-07T01:00:00Z');
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->seed(SecurityDevicesSignalSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Synthetic House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->person = function (array $keys, string $name): User {
        $user = User::factory()->create(['approved_at' => now(), 'name' => $name]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    };
    $this->manager = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'securityDevices.alerts.acknowledge', 'securityDevices.automations.manage'], 'Synthetic Manager');
    $this->worker = User::factory()->create(['approved_at' => now(), 'name' => 'Synthetic Worker']);
    $this->client = Client::factory()->create(['site_id' => $this->site->id]);
    Shift::factory()->create([
        'client_id' => $this->client->id, 'site_id' => $this->site->id, 'user_id' => $this->worker->id,
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(6), 'status' => 'scheduled',
    ]);
    $this->button = Device::factory()->create(['name' => 'Synthetic lounge button', 'domain' => 'security', 'category' => 'alarm']);
    DeviceAssignment::query()->create([
        'device_id' => $this->button->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subWeek(),
    ]);
    $this->engine = app(DeviceAlertEngine::class);
    $this->settings = app(SecurityDevicesSettings::class);
    $this->panic = function (): DeviceAlert {
        $this->engine->onSafetyEvent($this->button, 'panic', CarbonImmutable::now('UTC'));

        return DeviceAlert::query()->where('kind', 'panic')->latest('id')->firstOrFail();
    };
    $this->onCall = fn (User $user) => MedicationOnCallRule::query()->create(['site_id' => $this->site->id, 'mode' => MedicationOnCallRule::FIXED, 'team_lead' => false, 'backup_user_id' => $user->id]);
    $this->sentTo = fn (User $user, DeviceAlert $alert): int => Notification::sent($user, SecurityDeviceAlertNotification::class)
        ->filter(fn (SecurityDeviceAlertNotification $sent): bool => $sent->alertId === (int) $alert->id)->count();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

it('refuses to snooze a safety or care alert by default, and still snoozes an ordinary device alert', function (): void {
    $alert = ($this->panic)();

    expect(DeviceAlertEngine::snoozeRule('panic'))->toBe(['allowed' => false, 'max_minutes' => null])
        ->and(DeviceAlertEngine::snoozeRule('care_out_of_bed')['allowed'])->toBeFalse()
        ->and(DeviceAlertEngine::snoozeRule('tamper'))->toBe(['allowed' => true, 'max_minutes' => null]);
    expect(fn () => $this->engine->snooze($alert, $this->manager, CarbonImmutable::now('UTC')->addMinutes(10), null))
        ->toThrow(HttpException::class);
    $this->actingAs($this->manager)->postJson("/security-devices/alerts/{$alert->id}/snooze", ['until' => now()->addMinutes(10)->toIso8601String(), 'why' => 'Busy'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.until.0', 'Safety and care alerts can’t be snoozed: someone has to attend to them or resolve them.');
    expect($alert->fresh()->state)->toBe(DeviceAlert::STATE_OPEN);

    // The Alerts page offers no Snooze for it.
    $this->actingAs($this->manager)->get('/security-devices/alerts?alert='.$alert->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('selected.id', $alert->id)->where('selected.can.snooze', false));

    $this->engine->onSafetyEvent($this->button, 'tamper', CarbonImmutable::now('UTC'));
    $tamper = DeviceAlert::query()->where('kind', 'tamper')->sole();
    $this->actingAs($this->manager)->postJson("/security-devices/alerts/{$tamper->id}/snooze", ['until' => now()->addHours(3)->toIso8601String(), 'why' => 'Installer coming'])->assertOk();
    expect($tamper->fresh()->state)->toBe(DeviceAlert::STATE_SNOOZED);
});

it('lets Setup allow snoozing only with a reason, audited, and caps each snooze at the longest snooze', function (): void {
    expect(fn () => $this->settings->update($this->manager, [SecurityDevicesSettings::SAFETY_SNOOZE => true]))
        ->toThrow(ValidationException::class, 'Say why you’re loosening this safety check.');
    expect(fn () => $this->settings->update($this->manager, [SecurityDevicesSettings::SAFETY_SNOOZE_MAX => 7], 'Odd time'))
        ->toThrow(ValidationException::class, 'Choose one of the times offered.');
    expect(fn () => $this->settings->update($this->manager, [SecurityDevicesSettings::ESCALATE_URGENT => 4], 'Odd time'))
        ->toThrow(ValidationException::class, 'Choose one of the times offered.');

    $worker = ($this->person)(['securityDevices.viewAny', 'securityDevices.alerts.acknowledge'], 'Synthetic Reader');
    $this->actingAs($worker)->patchJson('/security-devices/setup/settings', ['changes' => [SecurityDevicesSettings::SAFETY_SNOOZE => true], 'reason' => 'Because'])->assertForbidden();

    expect($this->settings->update($this->manager, [SecurityDevicesSettings::SAFETY_SNOOZE => true], 'Night staff need a moment to settle the person first'))
        ->toBe([SecurityDevicesSettings::SAFETY_SNOOZE]);
    $audit = \App\Models\AuditLog::query()->where('action', 'security_devices.settings.changed')->latest('id')->firstOrFail();
    expect(data_get($audit->meta, 'loosens_safety'))->toBeTrue()
        ->and(data_get($audit->meta, 'reason'))->toBe('Night staff need a moment to settle the person first');
    expect(DeviceAlertEngine::snoozeRule('panic'))->toBe(['allowed' => true, 'max_minutes' => 15]);

    // A longer longest snooze loosens too; a shorter one doesn't need a reason.
    expect(fn () => $this->settings->update($this->manager, [SecurityDevicesSettings::SAFETY_SNOOZE_MAX => 30]))
        ->toThrow(ValidationException::class, 'Say why you’re loosening this safety check.');
    expect($this->settings->update($this->manager, [SecurityDevicesSettings::SAFETY_SNOOZE_MAX => 10]))->toBe([SecurityDevicesSettings::SAFETY_SNOOZE_MAX]);
    // A longer wait before escalating loosens; a shorter one doesn't.
    expect(fn () => $this->settings->update($this->manager, [SecurityDevicesSettings::ESCALATE_CARE => 15]))
        ->toThrow(ValidationException::class, 'Say why you’re loosening this safety check.');
    expect($this->settings->update($this->manager, [SecurityDevicesSettings::ESCALATE_CARE => 3]))->toBe([SecurityDevicesSettings::ESCALATE_CARE]);

    $alert = ($this->panic)();
    $this->actingAs($this->manager)->postJson("/security-devices/alerts/{$alert->id}/snooze", ['until' => now()->addMinutes(20)->toIso8601String(), 'why' => 'Settling him'])
        ->assertUnprocessable()->assertJsonPath('errors.until.0', 'Snooze a safety or care alert for 10 minutes at most.');
    $this->actingAs($this->manager)->postJson("/security-devices/alerts/{$alert->id}/snooze", ['until' => now()->addMinutes(10)->toIso8601String(), 'why' => 'Settling him'])
        ->assertOk();
    expect($alert->fresh()->state)->toBe(DeviceAlert::STATE_SNOOZED);
});

it('reminds again when a snooze ends, whatever the kind of alert', function (): void {
    $this->settings->update($this->manager, [SecurityDevicesSettings::SAFETY_SNOOZE => true], 'Night staff need a moment');
    $alert = ($this->panic)();
    $this->engine->acknowledge($alert, $this->manager, null, false);
    $this->engine->snooze($alert->fresh(), $this->manager, CarbonImmutable::now('UTC')->addMinutes(10), null);
    $before = ($this->sentTo)($this->manager, $alert);

    ($this->at)('2026-11-07T01:09:00Z');
    expect($this->engine->minute())->toBe(['back' => 0, 'escalated' => 0]);

    ($this->at)('2026-11-07T01:10:30Z');
    expect($this->engine->minute())->toBe(['back' => 1, 'escalated' => 0]);
    $back = $alert->fresh();
    expect($back->state)->toBe(DeviceAlert::STATE_OPEN)
        ->and(($this->sentTo)($this->manager, $alert))->toBe($before + 1)
        ->and($back->events()->where('kind', 'reminded')->value('text'))->toBe('Snooze ended: still open');
});

it('tells the on-call manager when nobody says they’re on a panic press within the minutes set, once', function (): void {
    $onCall = User::factory()->create(['approved_at' => now(), 'name' => 'Synthetic On Call']);
    ($this->onCall)($onCall);
    $alert = ($this->panic)();
    expect(($this->sentTo)($this->worker, $alert))->toBe(1)
        ->and(($this->sentTo)($onCall, $alert))->toBe(0);

    ($this->at)('2026-11-07T01:01:30Z');
    expect($this->engine->minute()['escalated'])->toBe(0);

    ($this->at)('2026-11-07T01:02:00Z');
    expect($this->engine->minute()['escalated'])->toBe(1)
        ->and(($this->sentTo)($onCall, $alert))->toBe(1);
    $events = $alert->events()->pluck('text')->all();
    expect($events)->toContain('Nobody had said they’re on it')
        ->and($events)->toContain('Synthetic On Call told (on call)');
    // Control Room already has a panic press from the device itself: no second Control Room alert.
    expect(Signal::query()->where('signal_type_code', 'care_alert_unanswered')->count())->toBe(0);

    ($this->at)('2026-11-07T01:05:00Z');
    expect($this->engine->minute()['escalated'])->toBe(0)
        ->and(($this->sentTo)($onCall, $alert))->toBe(1);
});

it('never escalates an alert someone said they’re on, and safety alerts no longer wait an hour to remind IT', function (): void {
    $onCall = User::factory()->create(['approved_at' => now(), 'name' => 'Synthetic On Call']);
    ($this->onCall)($onCall);
    $alert = ($this->panic)();
    $this->engine->acknowledge($alert, $this->worker, 'With him now', false);

    ($this->at)('2026-11-07T02:05:00Z');
    expect($this->engine->tick()['reminded'])->toBe(0)
        ->and($this->engine->minute()['escalated'])->toBe(0)
        ->and(($this->sentTo)($onCall, $alert))->toBe(0)
        ->and($alert->fresh()->reminded_at)->toBeNull();
});

it('sends an unanswered care alert to the on-call manager and to Control Room as critical', function (): void {
    $onCall = User::factory()->create(['approved_at' => now(), 'name' => 'Synthetic On Call']);
    ($this->onCall)($onCall);
    $bed = Device::factory()->create(['name' => 'Synthetic bed sensor', 'domain' => 'iot_healthcare', 'category' => 'environmental']);
    DeviceAssignment::query()->create(['device_id' => $bed->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subWeek()]);
    $alert = $this->engine->openCare($bed, 'care_out_of_bed', 'Out of bed for 10 minutes', CarbonImmutable::now('UTC'), 'Out of bed for 10 minutes', null);
    expect($alert)->not->toBeNull();

    ($this->at)('2026-11-07T01:04:00Z');
    expect($this->engine->minute()['escalated'])->toBe(0);

    ($this->at)('2026-11-07T01:05:00Z');
    expect($this->engine->minute()['escalated'])->toBe(1)
        ->and(($this->sentTo)($onCall, $alert))->toBe(1);
    $signal = Signal::query()->where('signal_type_code', 'care_alert_unanswered')->sole();
    $controlRoom = ControlRoomAlert::query()->latest('id')->firstOrFail();
    expect((int) $signal->site_id)->toBe($this->site->id)
        ->and($controlRoom->severity)->toBe('critical')
        ->and($controlRoom->alert_type)->toBe('Care alert not answered')
        ->and($alert->events()->pluck('text')->all())->toContain('Control Room told (critical)');
});

it('tells Control Room instead when the only person on call was already told and hasn’t answered', function (): void {
    // The manager is on an on-call shift at the house, so they hear about the press with the staff on shift.
    $onCall = ($this->person)(['securityDevices.viewAny'], 'Synthetic Lead');
    Shift::factory()->create([
        'client_id' => $this->client->id, 'site_id' => $this->site->id, 'user_id' => $onCall->id, 'is_on_call' => true,
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(6), 'status' => 'scheduled', 'published_at' => now(),
    ]);
    MedicationOnCallRule::query()->create(['site_id' => $this->site->id, 'mode' => MedicationOnCallRule::ROSTER, 'team_lead' => false, 'backup_user_id' => null]);
    $alert = ($this->panic)();
    expect(($this->sentTo)($onCall, $alert))->toBe(1);

    ($this->at)('2026-11-07T01:02:00Z');
    expect($this->engine->minute()['escalated'])->toBe(1)
        // Not told twice.
        ->and(($this->sentTo)($onCall, $alert))->toBe(1)
        ->and(Signal::query()->where('signal_type_code', 'care_alert_unanswered')->count())->toBe(1)
        ->and(DeviceAlertEvent::query()->where('alert_id', $alert->id)->where('kind', 'told')->pluck('text')->all())->toContain('Control Room told (critical)');
});
