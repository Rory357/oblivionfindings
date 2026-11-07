<?php

use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Alerts\SafetyEventObserver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Client;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Notifications\SecurityDeviceAlertNotification;
use App\Observers\DeviceEventObserver;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Database\Seeders\SecurityDevicesSignalSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * Safety fix round (7 Nov 2026), bugs 1, 2 and 4: device safety events reach Control Room as its own
 * people-safety and home-facility types at their real tier (not the generic "info" catch-all), and
 * a duress code or a possible fall tells the staff on shift at the house, as a panic press does.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->seed(SecurityDevicesSignalSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Synthetic House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->device = function (string $name, string $category = 'alarm'): Device {
        $device = Device::factory()->create(['name' => $name, 'domain' => 'security', 'category' => $category]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
            'assignment_type' => 'permanent', 'assigned_at' => now()->subWeek(),
        ]);

        return $device;
    };
    $this->event = fn (Device $device, string $type, string $severity, array $payload = []): DeviceEvent => DeviceEvent::create([
        'device_id' => $device->id, 'event_type' => $type, 'severity' => $severity,
        'source' => 'safety_fix_test', 'occurred_at' => now(), 'payload' => $payload,
    ]);
});

it('sends each device safety event to Control Room’s own type, never the generic catch-all', function (string $eventType, array $payload, string $code): void {
    $event = new DeviceEvent(['event_type' => $eventType, 'payload' => $payload]);

    expect(DeviceEventObserver::signalTypeFor($event))->toBe($code);
})->with([
    'panic' => ['panic', [], 'panic_sos_person'],
    'Milesight panic_alarm' => ['panic_alarm', [], 'panic_sos_person'],
    'SOS' => ['sos_triggered', [], 'panic_sos_person'],
    'duress code' => ['duress', [], 'panic_sos_person'],
    'intruder alarm' => ['alarm_triggered', ['cid_code' => 130], 'intrusion_alarm'],
    'fire alarm (Contact ID 110)' => ['alarm_triggered', ['cid_code' => 110], 'fire_alarm'],
    'fire alarm (Contact ID 118)' => ['alarm_triggered', ['cid_code' => '118'], 'fire_alarm'],
    'fire alarm (SIA FA)' => ['alarm_triggered', ['sia_code' => 'fa'], 'fire_alarm'],
    'forced door' => ['door_forced', [], 'door_forced_open'],
    'water leak' => ['water_leak_detected', [], 'water_leak'],
    'Milesight water leak' => ['water_leak', [], 'water_leak'],
    'fall' => ['fall_detected', [], 'fall_detected'],
    // Unchanged: the legacy alarm_trigger keeps its own S&D rule, and unknown events the catch-all.
    'legacy alarm_trigger' => ['alarm_trigger', [], 'device_alarm_trigger'],
    'unknown' => ['something_else', [], 'device_signal_generic'],
]);

it('raises a panic press, a duress code and a fire alarm as critical Control Room alerts, even when the device under-reports', function (string $type, string $reported, array $payload, string $code, string $alertType): void {
    $device = ($this->device)('Synthetic '.$type.' device');

    ($this->event)($device, $type, $reported, $payload);

    $signal = Signal::query()->latest('id')->firstOrFail();
    $alert = ControlRoomAlert::query()->latest('id')->firstOrFail();
    expect($signal->signal_type_code)->toBe($code)
        ->and($signal->severity_hint)->toBe('critical')
        ->and($alert->severity)->toBe('critical')
        ->and($alert->alert_type)->toBe($alertType)
        ->and($alert->status)->toBe('open');
})->with([
    'panic reported as info' => ['panic', 'info', [], 'panic_sos_person', 'Panic/SOS (Person)'],
    'duress reported as warning' => ['duress', 'warning', [], 'panic_sos_person', 'Panic/SOS (Person)'],
    'fire alarm reported as high' => ['alarm_triggered', 'high', ['cid_code' => 111], 'fire_alarm', 'Fire Alarm'],
    // An earlier migration made this type, named "Fall detected"; the safety round leaves existing types as they are.
    'fall reported as info' => ['fall_detected', 'info', [], 'fall_detected', 'Fall detected'],
]);

it('keeps a forced door, a leak and an intruder alarm at least high, and critical when the device says so', function (): void {
    $door = ($this->device)('Synthetic back door', 'access_control');
    ($this->event)($door, 'door_forced', 'info');
    expect(ControlRoomAlert::query()->latest('id')->firstOrFail()->severity)->toBe('high');

    $leak = ($this->device)('Synthetic laundry leak sensor', 'environmental');
    ($this->event)($leak, 'water_leak_detected', 'low');
    expect(ControlRoomAlert::query()->latest('id')->firstOrFail()->severity)->toBe('high');

    $panel = ($this->device)('Synthetic alarm panel');
    ($this->event)($panel, 'alarm_triggered', 'critical', ['cid_code' => 130]);
    $intruder = ControlRoomAlert::query()->latest('id')->firstOrFail();
    expect($intruder->severity)->toBe('critical')->and($intruder->alert_type)->toBe('Intrusion Alarm');
});

it('tells the staff on shift about a duress code and a possible fall, as it does a panic press', function (string $type, string $kind, string $title): void {
    Notification::fake();
    $device = ($this->device)('Synthetic lounge sensor', 'environmental');
    $onShift = User::factory()->create(['approved_at' => now(), 'name' => 'Synthetic Worker']);
    $client = Client::factory()->create(['site_id' => $this->site->id]);
    Shift::factory()->create([
        'client_id' => $client->id, 'site_id' => $this->site->id, 'user_id' => $onShift->id,
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3), 'status' => 'scheduled',
    ]);

    app(SafetyEventObserver::class)->created(new DeviceEvent([
        'device_id' => $device->id, 'event_type' => $type, 'severity' => 'info', 'occurred_at' => now(),
    ]));

    $alert = DeviceAlert::query()->sole();
    expect($alert->kind)->toBe($kind)
        ->and($alert->severity)->toBe('critical')
        ->and($alert->title)->toBe($title)
        ->and($alert->events()->pluck('text')->all())->toContain('Synthetic Worker told');
    Notification::assertSentTo($onShift, SecurityDeviceAlertNotification::class, fn (SecurityDeviceAlertNotification $sent): bool => $sent->alertId === (int) $alert->id);
})->with([
    'duress' => ['duress', 'duress', 'Synthetic lounge sensor: duress code entered at the alarm'],
    'fall' => ['fall_detected', 'fall', 'Synthetic lounge sensor: possible fall'],
]);
