<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\MonitoringCheckAlertNotification;
use App\Notifications\SecurityDeviceAlertNotification;
use App\Notifications\SecurityDeviceMorningSummaryNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/** S&D Phase D: device alerts open, refresh and close from the checks, and people act on them. */
class DeviceAlertsTest extends TestCase
{
    use RefreshDatabase;

    private Site $home;

    private Site $other;

    private User $viewer;

    private MonitoringProfile $profile;

    private DeviceAlertEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-02T01:00:00Z');
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->home = Site::factory()->create(['name' => 'Aurora House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->other = Site::factory()->create(['name' => 'Kōwhai Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->viewer = $this->person(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'securityDevices.alerts.acknowledge']);
        $this->profile = MonitoringProfile::factory()->create(['failure_confirmations' => 3]);
        $this->engine = app(DeviceAlertEngine::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function freezeAt(string $iso): void
    {
        CarbonImmutable::setTestNow($iso);
        \Illuminate\Support\Carbon::setTestNow($iso);
    }

    /** @param  list<string>  $keys */
    private function person(array $keys, ?Site $site = null): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->home)->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    }

    /** @return array{0: Device, 1: Monitor} */
    private function watched(Site $site, string $name, MonitorKind $kind = MonitorKind::Icmp): array
    {
        $device = Device::factory()->itInfrastructure()->create(['name' => $name]);
        DeviceAssignment::create([
            'device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id, 'assigned_at' => now()->subDays(3),
        ]);
        $monitor = Monitor::factory()->create([
            'device_id' => $device->id, 'profile_id' => $this->profile->id, 'kind' => $kind, 'name' => 'Answers',
            'current_state' => MonitorState::Healthy, 'effective_state' => MonitorState::Healthy, 'is_enabled' => true,
        ]);

        return [$device, $monitor];
    }

    private function goes(Monitor $monitor, MonitorState $from, MonitorState $to, ?int $root = null, ?array $told = null): void
    {
        $monitor->forceFill(['current_state' => $to === MonitorState::Suppressed ? MonitorState::Failed : $to, 'effective_state' => $to, 'root_cause_monitor_id' => $root])->save();
        $this->engine->onCheck($monitor->fresh(), $from, $to, $root, CarbonImmutable::now('UTC'), $told);
    }

    public function test_a_failing_check_opens_one_alert_with_who_was_told_and_it_closes_by_itself(): void
    {
        [$router, $ping] = $this->watched($this->home, 'Aurora House router');
        $web = Monitor::factory()->create(['device_id' => $router->id, 'profile_id' => $this->profile->id, 'kind' => MonitorKind::Http, 'name' => 'Web page', 'current_state' => MonitorState::Healthy, 'effective_state' => MonitorState::Healthy, 'is_enabled' => true]);

        $this->goes($ping, MonitorState::Healthy, MonitorState::Failed, told: ['names' => ['Jordan Peri'], 'channels' => ['database', 'mail']]);
        $this->goes($web, MonitorState::Healthy, MonitorState::Failed);

        $alert = DeviceAlert::query()->sole();
        expect($alert->title)->toBe('Aurora House router is offline')
            ->and($alert->severity)->toBe('critical')
            ->and($alert->site_id)->toBe($this->home->id)
            ->and($alert->events()->pluck('text')->all())->toBe(['Stopped answering', 'Jordan Peri told'])
            ->and($alert->events()->first()->detail)->toBe('3 missed checks in a row');

        // One check passing isn't enough while another still fails.
        CarbonImmutable::setTestNow(now()->addHours(2)->addMinutes(5));
        $this->goes($ping, MonitorState::Failed, MonitorState::Healthy);
        expect($alert->fresh()->state)->toBe(DeviceAlert::STATE_OPEN);

        $this->goes($web, MonitorState::Failed, MonitorState::Healthy);
        $closed = $alert->fresh();
        expect($closed->state)->toBe(DeviceAlert::STATE_DONE)
            ->and($closed->close_reason)->toBe('recovered')
            ->and($closed->events()->get()->last()->text)->toBe('Back online after 2 h 5 min');
    }

    public function test_a_device_behind_a_failed_router_gets_no_alert_of_its_own(): void
    {
        [, $routerPing] = $this->watched($this->home, 'Aurora House router');
        [$switch, $switchPing] = $this->watched($this->home, 'Aurora House switch');

        // The switch noticed first and opened its own alert…
        $this->goes($switchPing, MonitorState::Healthy, MonitorState::Failed);
        $this->goes($routerPing, MonitorState::Healthy, MonitorState::Failed);
        // …then the engine saw the router in front of it: the switch folds into the router's alert.
        $this->goes($switchPing, MonitorState::Failed, MonitorState::Suppressed, root: $routerPing->id);

        expect(DeviceAlert::query()->active()->pluck('title')->all())->toBe(['Aurora House router is offline']);
        $switchAlert = DeviceAlert::query()->where('device_id', $switch->id)->sole();
        expect($switchAlert->close_reason)->toBe('behind')
            ->and($switchAlert->events()->get()->last()->text)->toBe('Now behind Aurora House router, which is down: covered by that alert');
    }

    public function test_a_warning_that_gets_worse_comes_back_from_snooze(): void
    {
        [, $snmp] = $this->watched($this->home, 'Aurora House UPS', MonitorKind::Snmp);
        $this->goes($snmp, MonitorState::Healthy, MonitorState::Degraded);
        $alert = DeviceAlert::query()->sole();
        expect($alert->severity)->toBe('warning')->and($alert->title)->toBe('Aurora House UPS: Answers needs attention');

        $this->engine->snooze($alert, $this->viewer, CarbonImmutable::now('UTC')->addHours(3), 'Waiting for the electrician');
        $this->goes($snmp, MonitorState::Degraded, MonitorState::Failed);

        $worse = $alert->fresh();
        expect($worse->state)->toBe(DeviceAlert::STATE_OPEN)
            ->and($worse->severity)->toBe('critical')
            ->and($worse->events()->pluck('kind')->all())->toBe(['opened', 'snoozed', 'worse', 'back']);
    }

    public function test_people_acknowledge_assign_and_snooze_only_their_own_sites_alerts(): void
    {
        [, $ping] = $this->watched($this->home, 'Aurora House router');
        [, $otherPing] = $this->watched($this->other, 'Kōwhai Lodge router');
        $this->goes($ping, MonitorState::Healthy, MonitorState::Failed);
        $this->goes($otherPing, MonitorState::Healthy, MonitorState::Failed);
        $mine = DeviceAlert::query()->whereHas('device', fn ($q) => $q->where('name', 'Aurora House router'))->sole();
        $theirs = DeviceAlert::query()->whereHas('device', fn ($q) => $q->where('name', 'Kōwhai Lodge router'))->sole();

        $this->actingAs($this->viewer)->get('/security-devices/alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/alerts/index')
                ->has('queue', 1)
                ->where('queue.0.title', 'Aurora House router is offline')
                ->where('meters.needs_now.count', 1)
                ->where('selected.can.acknowledge', true));

        $this->actingAs($this->viewer)->postJson("/security-devices/alerts/{$theirs->id}/acknowledge", ['remind' => false])->assertNotFound();
        $this->actingAs($this->viewer)->postJson("/security-devices/alerts/{$mine->id}/acknowledge", ['note' => 'Power cut on the street', 'remind' => true])->assertOk();
        expect($mine->fresh()->acknowledged_by_user_id)->toBe($this->viewer->id)
            ->and($mine->fresh()->remind_at?->toIso8601String())->toBe(now()->addHour()->toIso8601String());

        Notification::fake();
        $colleague = $this->person(['securityDevices.viewAny', 'securityDevices.alerts.acknowledge']);
        $stranger = $this->person(['securityDevices.viewAny', 'securityDevices.alerts.acknowledge'], $this->other);
        $this->actingAs($this->viewer)->getJson("/security-devices/alerts/{$mine->id}/assignees")
            ->assertOk()
            ->assertJsonFragment(['id' => $colleague->id])
            ->assertJsonMissing(['id' => $stranger->id]);
        $this->actingAs($this->viewer)->postJson("/security-devices/alerts/{$mine->id}/assign", ['user_id' => $stranger->id])->assertUnprocessable();
        $this->actingAs($this->viewer)->postJson("/security-devices/alerts/{$mine->id}/assign", ['user_id' => $colleague->id])->assertOk();
        Notification::assertSentTo($colleague, SecurityDeviceAlertNotification::class);

        $this->actingAs($this->viewer)->postJson("/security-devices/alerts/{$mine->id}/snooze", ['until' => now()->addMinute()->toIso8601String()])->assertUnprocessable();
        $this->actingAs($this->viewer)->postJson("/security-devices/alerts/{$mine->id}/snooze", ['until' => now()->addHours(2)->toIso8601String(), 'why' => 'Waiting for Vector'])->assertOk();
        expect($mine->fresh()->state)->toBe(DeviceAlert::STATE_SNOOZED);

        // Without the permission: no acting.
        $reader = $this->person(['securityDevices.viewAny', 'securityDevices.events.view']);
        $this->actingAs($reader)->postJson("/security-devices/alerts/{$mine->id}/acknowledge", ['remind' => false])->assertForbidden();
    }

    public function test_auditors_see_alerts_but_cannot_acknowledge_them_and_coordinators_can(): void
    {
        [, $ping] = $this->watched($this->home, 'Aurora House router');
        $this->goes($ping, MonitorState::Healthy, MonitorState::Failed);
        $alert = DeviceAlert::query()->sole();
        $byRole = function (string $role): User {
            $user = User::factory()->create(['approved_at' => now()]);
            HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->home->id, 'secondary_site_ids' => [], 'is_active' => true]);
            $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

            return $user->fresh();
        };
        $auditor = $byRole('auditor');
        $coordinator = $byRole('coordinator');

        $this->actingAs($auditor)->get('/security-devices/alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('queue.0.title', 'Aurora House router is offline')
                ->where('selected.can.acknowledge', false));
        $this->actingAs($auditor)->postJson("/security-devices/alerts/{$alert->id}/acknowledge", ['remind' => false])->assertForbidden();
        expect($alert->fresh()->acknowledged_by_user_id)->toBeNull();

        $this->actingAs($coordinator)->postJson("/security-devices/alerts/{$alert->id}/acknowledge", ['remind' => false])->assertOk();
        expect($alert->fresh()->acknowledged_by_user_id)->toBe($coordinator->id);
    }

    public function test_snoozes_end_on_time_and_nobody_acknowledging_for_an_hour_sends_one_reminder(): void
    {
        Notification::fake();
        [, $ping] = $this->watched($this->home, 'Aurora House router');
        $this->goes($ping, MonitorState::Healthy, MonitorState::Failed);
        $alert = DeviceAlert::query()->sole();
        $this->engine->acknowledge($alert, $this->viewer, null, true);

        CarbonImmutable::setTestNow(now()->addMinutes(61));
        expect($this->engine->tick())->toBe(['back' => 0, 'reminded' => 1]);
        Notification::assertSentTo($this->viewer, SecurityDeviceAlertNotification::class);
        expect($this->engine->tick()['reminded'])->toBe(0);

        $this->engine->snooze($alert->fresh(), $this->viewer, CarbonImmutable::now('UTC')->addMinutes(30), null);
        CarbonImmutable::setTestNow(now()->addMinutes(31));
        expect($this->engine->tick()['back'])->toBe(1)
            ->and($alert->fresh()->state)->toBe(DeviceAlert::STATE_OPEN);
    }

    public function test_a_warning_nobody_acknowledges_reaches_the_rest_of_it_once_after_an_hour(): void
    {
        // Stephan, 3 Nov: warnings go to the person on call first, then the rest of IT at the hour.
        Notification::fake();
        $it = ['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'securityDevices.alerts.acknowledge', 'securityDevices.monitoring.manage'];
        $jordan = $this->person($it);
        $sam = $this->person($it);
        Shift::factory()->create([
            'site_id' => $this->home->id, 'user_id' => $jordan->id, 'starts_at' => now()->subHours(2), 'ends_at' => now()->addDays(2),
            'status' => 'scheduled', 'is_on_call' => true, 'published_at' => now(),
        ]);
        [, $printer] = $this->watched($this->home, 'Office printer', MonitorKind::Http);
        [, $wifi] = $this->watched($this->home, 'Lounge Wi-Fi', MonitorKind::Http);
        $this->goes($printer, MonitorState::Healthy, MonitorState::Degraded);
        $this->goes($wifi, MonitorState::Healthy, MonitorState::Degraded);
        [$waiting, $handled] = DeviceAlert::query()->orderBy('id')->get()->all();
        expect($waiting->severity)->toBe('warning')->and($waiting->kind)->toBe('failing');
        $this->engine->acknowledge($handled, $jordan, null, false);

        CarbonImmutable::setTestNow(now()->addMinutes(59));
        expect($this->engine->tick()['reminded'])->toBe(0);

        CarbonImmutable::setTestNow(now()->addMinutes(2));
        expect($this->engine->tick()['reminded'])->toBe(1);
        Notification::assertSentTo($sam, SecurityDeviceAlertNotification::class, fn (SecurityDeviceAlertNotification $sent): bool => $sent->alertId === (int) $waiting->id);
        // Acknowledged: nobody else is told about it.
        Notification::assertNotSentTo($sam, SecurityDeviceAlertNotification::class, fn (SecurityDeviceAlertNotification $sent): bool => $sent->alertId === (int) $handled->id);
        // One reminder, as for critical alerts.
        expect($this->engine->tick()['reminded'])->toBe(0);
    }

    public function test_added_devices_are_watched_unless_setup_says_no_and_phones_never_are(): void
    {
        $manager = $this->person(['securityDevices.viewAny', 'securityDevices.monitoring.manage']);
        $phone = Device::factory()->create(['name' => 'Someone’s phone', 'category' => 'endpoint', 'subcategory' => 'mobile_device', 'ip_address' => '10.20.0.77']);
        $noAddress = Device::factory()->create(['name' => 'Printer with no address', 'ip_address' => null]);
        $watcher = app(\App\Domain\SecurityDevices\Alerts\WatchNewDevices::class);

        expect(\App\Domain\SecurityDevices\Alerts\WatchNewDevices::isPhoneOrTablet($phone))->toBeTrue()
            ->and($watcher->watch($manager, [$phone, $noAddress]))->toBe(0)
            ->and($watcher->watch($this->viewer, [$noAddress]))->toBe(0);

        app(\App\Domain\SecurityDevices\Setup\SecurityDevicesSettings::class)->update($manager, ['devices.watch_on_add' => false], 'Adding a batch of test kit first');
        expect(app(\App\Domain\SecurityDevices\Alerts\WatchNewDevices::class)->watch($manager, [$phone]))->toBe(0)
            ->and(Monitor::query()->whereIn('device_id', [$phone->id, $noAddress->id])->count())->toBe(0);
    }

    public function test_a_panic_press_opens_a_safety_alert_for_the_staff_on_shift_until_someone_marks_it_done(): void
    {
        Notification::fake();
        [$button] = $this->watched($this->home, 'Room 3 panic button');
        $onShift = User::factory()->create(['approved_at' => now(), 'name' => 'Mere Tane']);
        $client = \App\Models\Client::factory()->create(['site_id' => $this->home->id]);
        \App\Models\Shift::factory()->create([
            'client_id' => $client->id, 'site_id' => $this->home->id, 'user_id' => $onShift->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3), 'status' => 'scheduled',
        ]);

        // As a device event arrives (the observer beside Control Room's own).
        app(\App\Domain\SecurityDevices\Alerts\SafetyEventObserver::class)->created(new \App\Domain\SecurityDevices\Models\DeviceEvent([
            'device_id' => $button->id, 'event_type' => 'panic', 'severity' => 'critical', 'occurred_at' => now(),
        ]));
        $alert = DeviceAlert::query()->sole();
        expect($alert->kind)->toBe('panic')
            ->and($alert->title)->toBe('Room 3 panic button: panic button pressed')
            ->and($alert->events()->pluck('text')->all())->toBe(['Panic button pressed', 'Mere Tane told']);
        Notification::assertSentTo($onShift, SecurityDeviceAlertNotification::class);

        // A second press is a line on the same alert; a reply to the check doesn't close it.
        $this->engine->onSafetyEvent($button, 'panic', CarbonImmutable::now('UTC'));
        [, $ping] = [null, Monitor::query()->where('device_id', $button->id)->sole()];
        $this->goes($ping, MonitorState::Failed, MonitorState::Healthy);
        expect(DeviceAlert::query()->count())->toBe(1)
            ->and($alert->fresh()->state)->toBe(DeviceAlert::STATE_OPEN)
            ->and($alert->fresh()->events()->pluck('text')->last())->toBe('Panic button pressed again');

        $this->engine->done($alert->fresh(), $this->viewer, 'False alarm, Hemi leaned on it');
        expect($alert->fresh()->state)->toBe(DeviceAlert::STATE_DONE)->and($alert->fresh()->close_reason)->toBe('done');

        // Old or replayed reports are history, not a new alert.
        app(\App\Domain\SecurityDevices\Alerts\SafetyEventObserver::class)->created(new \App\Domain\SecurityDevices\Models\DeviceEvent([
            'device_id' => $button->id, 'event_type' => 'panic', 'severity' => 'critical', 'occurred_at' => now()->subDays(2),
        ]));
        expect(DeviceAlert::query()->active()->count())->toBe(0);
    }

    public function test_a_hub_that_stops_answering_opens_an_alert_that_closes_when_it_is_back(): void
    {
        [$hubDevice] = $this->watched($this->home, 'Aurora House hub PC');
        $hub = MonitoringCollector::factory()->create([
            'site_id' => $this->home->id, 'name' => 'Aurora House', 'collector_uuid' => (string) Str::uuid(),
            'status' => 'online', 'approved_at' => now()->subDay(), 'revoked_at' => null, 'collector_device_id' => $hubDevice->id,
        ]);

        $this->engine->onHub($hub, true, CarbonImmutable::now('UTC'));
        $alert = DeviceAlert::query()->sole();
        expect($alert->kind)->toBe('hub_offline')->and($alert->title)->toBe('Aurora House hub has stopped answering');

        CarbonImmutable::setTestNow(now()->addMinutes(40));
        $this->engine->onHub($hub, false, CarbonImmutable::now('UTC'));
        expect($alert->fresh()->state)->toBe(DeviceAlert::STATE_DONE)
            ->and($alert->fresh()->events()->get()->last()->text)->toBe('Answering again after 40 min');
    }

    public function test_quiet_hours_hold_device_alerts_without_delaying_safety_alerts_and_send_one_morning_summary_per_recipients_sites(): void
    {
        Notification::fake();
        $itKeys = ['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'securityDevices.alerts.acknowledge', 'securityDevices.monitoring.manage'];
        $jordan = $this->person($itKeys, $this->home);
        $jordan->forceFill(['name' => 'Jordan Peri', 'email' => 'jordan@example.test'])->save();
        $ana = $this->person($itKeys, $this->home);
        $ana->forceFill(['name' => 'Ana Ruha', 'email' => 'ana@example.test'])->save();
        $sam = $this->person($itKeys, $this->other);
        $sam->forceFill(['name' => 'Sam Tuilagi', 'email' => 'sam@example.test'])->save();
        // Sam turned email off for the morning summary: in-app only.
        UserNotificationPreference::query()->create([
            'user_id' => $sam->id,
            'key' => SecurityDeviceMorningSummaryNotification::PREFERENCE_KEY,
            'enabled' => true,
            'channel_inapp' => true,
            'channel_email' => false,
            'channel_push' => false,
        ]);

        // Jordan is on call at Aurora House; Sam is the only IT recipient at Kōwhai Lodge.
        Shift::factory()->create([
            'site_id' => $this->home->id, 'user_id' => $jordan->id,
            'starts_at' => CarbonImmutable::parse('2026-11-02T08:00:00Z'),
            'ends_at' => CarbonImmutable::parse('2026-11-04T08:00:00Z'),
            'status' => 'scheduled', 'is_on_call' => true, 'published_at' => now(),
        ]);

        // 11:30 pm NZDT (quiet hours 10:00 pm – 6:00 am): a Wi-Fi point stops answering, sensor battery drops, and Kōwhai switch flaps.
        $this->freezeAt('2026-11-02T10:30:00Z');
        $singleConfirm = MonitoringProfile::factory()->create(['failure_confirmations' => 1, 'recovery_confirmations' => 1]);
        [$hall, $hallPing] = $this->watched($this->home, 'Aurora House hall Wi-Fi point');
        $hallPing->forceFill(['profile_id' => $singleConfirm->id])->save();
        [$otherSwitch, $otherPing] = $this->watched($this->other, 'Kōwhai Lodge switch');
        $otherPing->forceFill(['profile_id' => $singleConfirm->id])->save();

        $ingestor = app(\App\Domain\Monitoring\Services\MonitoringObservationIngestor::class);
        $ingestor->ingest(
            $hallPing,
            new ObservationInput(
                sourceKey: 'qh-hall-1',
                state: MonitorState::Failed,
                observedAt: CarbonImmutable::parse('2026-11-02T10:30:00Z'),
            ),
            $this->home->id,
            $hall->id,
            null,
        );
        $ingestor->ingest(
            $otherPing,
            new ObservationInput(
                sourceKey: 'qh-other-1',
                state: MonitorState::Failed,
                observedAt: CarbonImmutable::parse('2026-11-02T10:35:00Z'),
            ),
            $this->other->id,
            $otherSwitch->id,
            null,
        );

        [$sensor] = $this->watched($this->home, 'Room 3 sensor');
        $this->engine->onBattery($sensor, 14, CarbonImmutable::now('UTC'));

        [$hubDevice] = $this->watched($this->home, 'Aurora House hub PC');
        $hub = MonitoringCollector::factory()->create([
            'site_id' => $this->home->id, 'name' => 'Aurora House', 'collector_uuid' => (string) Str::uuid(),
            'status' => 'online', 'enrolled_at' => now()->subDays(5), 'approved_at' => now()->subDays(4),
            'revoked_at' => null, 'collector_device_id' => $hubDevice->id,
        ]);

        // Held during quiet hours: open on Alerts right away, zero notifications sent overnight.
        Notification::assertNothingSent();
        $hallAlert = DeviceAlert::query()->where('device_id', $hall->id)->sole();
        expect($hallAlert->state)->toBe(DeviceAlert::STATE_OPEN)
            ->and($hallAlert->events()->pluck('kind')->all())->toBe(['opened', 'held']);

        $this->actingAs($jordan)->get('/security-devices/alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('queue.0.title', 'Aurora House hall Wi-Fi point is offline')
                ->where('queue.0.sub', 'Aurora House · Waiting for the 6:00 am summary'));

        // Kōwhai Lodge switch recovers at 2:19 am NZDT, and Aurora House hub rolls back an overnight update at 2:20 am NZDT.
        $this->freezeAt('2026-11-02T13:19:00Z');
        $ingestor->ingest(
            $otherPing,
            new ObservationInput(
                sourceKey: 'qh-other-2',
                state: MonitorState::Healthy,
                observedAt: CarbonImmutable::parse('2026-11-02T13:19:00Z'),
            ),
            $this->other->id,
            $otherSwitch->id,
            null,
        );
        $this->freezeAt('2026-11-02T13:20:00Z');
        app(\App\Domain\SecurityDevices\Hubs\Services\HubUpdatePolicy::class)->recordReport($hub, [
            'outcome' => 'rolled_back',
            'from_version' => '1.3.1',
            'to_version' => '1.3.2',
            'detail' => 'Health check timed out',
        ]);

        // Still in quiet hours (more than 1 hour after the Wi-Fi point stopped answering): no 1-hour reminder wakes anyone up.
        expect($this->engine->tick())->toBe(['back' => 0, 'reminded' => 0]);
        Notification::assertNothingSent();

        // 1:45 am NZDT: a panic button press NEVER waits for quiet hours.
        $this->freezeAt('2026-11-02T12:45:00Z');
        [$panicButton] = $this->watched($this->home, 'Lounge panic button');
        $onShift = User::factory()->create(['approved_at' => now(), 'name' => 'Mere Tane', 'email' => 'mere@example.test']);
        $client = \App\Models\Client::factory()->create(['site_id' => $this->home->id]);
        Shift::factory()->create([
            'client_id' => $client->id, 'site_id' => $this->home->id, 'user_id' => $onShift->id,
            'starts_at' => now()->subHours(2), 'ends_at' => now()->addHours(4), 'status' => 'scheduled',
        ]);
        app(\App\Domain\SecurityDevices\Alerts\SafetyEventObserver::class)->created(new \App\Domain\SecurityDevices\Models\DeviceEvent([
            'device_id' => $panicButton->id, 'event_type' => 'panic', 'severity' => 'critical', 'occurred_at' => now(),
        ]));
        Notification::assertSentTo($onShift, SecurityDeviceAlertNotification::class);
        $this->engine->done(DeviceAlert::query()->where('device_id', $panicButton->id)->sole(), $jordan, 'Checked on shift');

        // 6:00 am NZDT: quiet hours end and the morning summary goes out once per recipient, scoped to their Sites.
        $this->freezeAt('2026-11-02T17:00:00Z');
        expect($this->engine->tick())->toBe(['back' => 0, 'reminded' => 0]);

        $jordanSent = Notification::sent($jordan, SecurityDeviceMorningSummaryNotification::class);
        expect($jordanSent)->toHaveCount(1);
        /** @var SecurityDeviceMorningSummaryNotification $jordanNote */
        $jordanNote = $jordanSent->first();
        expect($jordanNote->via($jordan))->toBe(['database', 'mail'])
            ->and($jordanNote->lines)->toHaveCount(3)
            ->and(implode(' | ', $jordanNote->lines))
            ->toContain('Aurora House hall Wi-Fi point is offline')
            ->toContain('Room 3 sensor: battery 14%')
            ->toContain('Aurora House hub couldn’t update to 1.3.2 and went back to 1.3.1')
            ->not->toContain('Kōwhai Lodge');

        // Ana is at Aurora House, but Jordan is on call: Ana does not get the initial summary.
        Notification::assertNotSentTo($ana, SecurityDeviceMorningSummaryNotification::class);

        // Sam gets Kōwhai Lodge's overnight summary in-app ONLY (email preference is off), and never sees Aurora House.
        $samSent = Notification::sent($sam, SecurityDeviceMorningSummaryNotification::class);
        expect($samSent)->toHaveCount(1);
        /** @var SecurityDeviceMorningSummaryNotification $samNote */
        $samNote = $samSent->first();
        expect($samNote->via($sam))->toBe(['database'])
            ->and($samNote->lines)->toHaveCount(1)
            ->and($samNote->lines[0])
            ->toContain('Kōwhai Lodge switch is offline')
            ->toContain('back after')
            ->not->toContain('Aurora House');

        // Alerts › On call shows Daily summary in delivery and Site-scoped Morning summary in the log.
        $this->actingAs($jordan)->get('/security-devices/alerts?tab=oncall')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('on_call.delivery.1.when', 'Daily summary')
                ->where('on_call.summary.text', fn (string $text): bool => str_contains($text, 'Aurora House hall Wi-Fi point is offline') && ! str_contains($text, 'Kōwhai Lodge')));

        // A second tick at 6:05 am sends no duplicate summary, and no 1-hour reminder yet (only 5 min since summary).
        $this->freezeAt('2026-11-02T17:05:00Z');
        expect($this->engine->tick())->toBe(['back' => 0, 'reminded' => 0]);
        Notification::assertSentToTimes($jordan, SecurityDeviceMorningSummaryNotification::class, 1);
        Notification::assertSentToTimes($sam, SecurityDeviceMorningSummaryNotification::class, 1);

        // Quiet hours are over (6:15 am NZDT): a new check failure notifies immediately.
        $this->freezeAt('2026-11-02T17:15:00Z');
        [$wifi, $wifiPing] = $this->watched($this->home, 'Aurora House Wi-Fi');
        $wifiPing->forceFill(['profile_id' => $singleConfirm->id])->save();
        $ingestor->ingest(
            $wifiPing,
            new ObservationInput(
                sourceKey: 'daytime-wifi-1',
                state: MonitorState::Failed,
                observedAt: CarbonImmutable::parse('2026-11-02T17:15:00Z'),
            ),
            $this->home->id,
            $wifi->id,
            null,
        );
        Notification::assertSentTo($jordan, MonitoringCheckAlertNotification::class);

        // 7:01 am NZDT (1 hour after the 6:00 am summary): the unacknowledged overnight Wi-Fi point alert reminds the rest of IT (Ana).
        $this->engine->acknowledge(DeviceAlert::query()->where('monitor_id', $wifiPing->id)->sole(), $jordan, null, false);
        CarbonImmutable::setTestNow('2026-11-02T18:01:00Z');
        expect($this->engine->tick())->toBe(['back' => 0, 'reminded' => 1]);
        Notification::assertSentTo($ana, SecurityDeviceAlertNotification::class, fn (SecurityDeviceAlertNotification $n): bool => $n->alertId === (int) $hallAlert->id);
    }

    public function test_in_quiet_hours_a_router_or_hub_that_stops_answering_tells_it_on_call_now_and_other_it_alerts_wait(): void
    {
        // Stephan, 6 Nov ("Let them through"): a router or hub going quiet takes the house's other
        // devices with it, so the IT person on call hears straight away, as by day.
        Notification::fake();
        $itKeys = ['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'securityDevices.alerts.acknowledge', 'securityDevices.monitoring.manage'];
        $jordan = $this->person($itKeys, $this->home);
        $jordan->forceFill(['name' => 'Jordan Peri'])->save();
        $ana = $this->person($itKeys, $this->home);
        $ana->forceFill(['name' => 'Ana Ruha'])->save();
        Shift::factory()->create([
            'site_id' => $this->home->id, 'user_id' => $jordan->id,
            'starts_at' => CarbonImmutable::parse('2026-11-02T08:00:00Z'),
            'ends_at' => CarbonImmutable::parse('2026-11-04T08:00:00Z'),
            'status' => 'scheduled', 'is_on_call' => true, 'published_at' => now(),
        ]);
        $singleConfirm = MonitoringProfile::factory()->create(['failure_confirmations' => 1, 'recovery_confirmations' => 1]);
        $ingestor = app(\App\Domain\Monitoring\Services\MonitoringObservationIngestor::class);

        // 2:00 am NZDT, inside the default quiet hours (10:00 pm – 6:00 am): the router stops answering.
        $this->freezeAt('2026-11-02T13:00:00Z');
        [$router, $routerPing] = $this->watched($this->home, 'Aurora House router');
        $router->forceFill(['subcategory' => 'router'])->save();
        $routerPing->forceFill(['profile_id' => $singleConfirm->id])->save();
        $ingestor->ingest($routerPing, new ObservationInput(sourceKey: 'qh-router-2am', state: MonitorState::Failed, observedAt: CarbonImmutable::now('UTC')), $this->home->id, $router->id, null);

        // 2:05 am: a switch's web page check needs attention. Not urgent: it waits for 6:00 am.
        $this->freezeAt('2026-11-02T13:05:00Z');
        [$switch, $switchPage] = $this->watched($this->home, 'Aurora House switch', MonitorKind::Http);
        $switch->forceFill(['subcategory' => 'switch'])->save();
        $switchPage->forceFill(['profile_id' => $singleConfirm->id, 'name' => 'Web page'])->save();
        $ingestor->ingest($switchPage, new ObservationInput(sourceKey: 'qh-switch-2am', state: MonitorState::Degraded, observedAt: CarbonImmutable::now('UTC')), $this->home->id, $switch->id, null);

        // 2:10 am: the house's hub stops answering.
        $this->freezeAt('2026-11-02T13:10:00Z');
        [$hubDevice] = $this->watched($this->home, 'Aurora House hub PC');
        $hub = MonitoringCollector::factory()->create([
            'site_id' => $this->home->id, 'name' => 'Aurora House', 'collector_uuid' => (string) Str::uuid(),
            'status' => 'online', 'approved_at' => now()->subDay(), 'revoked_at' => null, 'collector_device_id' => $hubDevice->id,
        ]);
        $this->engine->onHub($hub, true, CarbonImmutable::now('UTC'));

        $routerAlert = DeviceAlert::query()->where('device_id', $router->id)->sole();
        $switchAlert = DeviceAlert::query()->where('device_id', $switch->id)->sole();
        $hubAlert = DeviceAlert::query()->where('device_id', $hubDevice->id)->sole();
        expect($routerAlert->kind)->toBe('offline')
            ->and($routerAlert->events()->pluck('kind')->all())->toBe(['opened', 'told'])
            ->and($hubAlert->kind)->toBe('hub_offline')
            ->and($hubAlert->events()->pluck('kind')->all())->toBe(['opened', 'told'])
            ->and($switchAlert->kind)->toBe('failing')
            ->and($switchAlert->events()->pluck('kind')->all())->toBe(['opened', 'held']);
        // Jordan (on call) is told about the router and the hub now; nobody hears about the switch overnight.
        Notification::assertSentToTimes($jordan, MonitoringCheckAlertNotification::class, 1);
        Notification::assertSentTo($jordan, MonitoringCheckAlertNotification::class, fn (MonitoringCheckAlertNotification $n): bool => str_contains($n->title, 'Aurora House router'));
        Notification::assertSentTo($jordan, SecurityDeviceAlertNotification::class, fn (SecurityDeviceAlertNotification $n): bool => $n->alertId === (int) $hubAlert->id);
        Notification::assertNothingSentTo($ana);

        // 3:11 am: nobody has acknowledged them, so the rest of IT hears at the hour, as by day. The switch still waits.
        $this->freezeAt('2026-11-02T14:11:00Z');
        expect($this->engine->tick())->toBe(['back' => 0, 'reminded' => 2]);
        Notification::assertSentTo($ana, SecurityDeviceAlertNotification::class, fn (SecurityDeviceAlertNotification $n): bool => $n->alertId === (int) $routerAlert->id);
        Notification::assertSentTo($ana, SecurityDeviceAlertNotification::class, fn (SecurityDeviceAlertNotification $n): bool => $n->alertId === (int) $hubAlert->id);
        Notification::assertNotSentTo($ana, SecurityDeviceAlertNotification::class, fn (SecurityDeviceAlertNotification $n): bool => $n->alertId === (int) $switchAlert->id);

        // 6:00 am: the morning summary lists the switch only; the router and the hub were told already.
        $this->freezeAt('2026-11-02T17:00:00Z');
        expect($this->engine->tick())->toBe(['back' => 0, 'reminded' => 0]);
        $sent = Notification::sent($jordan, SecurityDeviceMorningSummaryNotification::class);
        expect($sent)->toHaveCount(1);
        /** @var SecurityDeviceMorningSummaryNotification $summary */
        $summary = $sent->first();
        expect($summary->lines)->toHaveCount(1)
            ->and($summary->lines[0])->toContain('Aurora House switch: Web page needs attention')
            ->and(implode(' | ', $summary->lines))->not->toContain('router')->not->toContain('stopped answering');
    }

    public function test_a_flat_battery_on_a_safety_device_tells_staff_on_shift_at_once_even_in_quiet_hours(): void
    {
        // Audit fix (5 Nov): a panic button with a flat battery stops protecting someone, so its notice never waits.
        Notification::fake();
        $this->freezeAt('2026-11-02T11:00:00Z'); // midnight NZDT, inside the default quiet hours
        $it = $this->person(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'securityDevices.monitoring.manage']);
        $onShift = User::factory()->create(['approved_at' => now(), 'name' => 'Mere Tane']);
        Shift::factory()->create([
            'client_id' => \App\Models\Client::factory()->create(['site_id' => $this->home->id])->id, 'site_id' => $this->home->id,
            'user_id' => $onShift->id, 'starts_at' => now()->subHours(2), 'ends_at' => now()->addHours(8), 'status' => 'scheduled',
        ]);
        $panic = Device::factory()->security()->create(['name' => 'Lounge panic button']);
        DeviceAssignment::create(['device_id' => $panic->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->home->id, 'assigned_at' => now()->subDays(3)]);

        $this->engine->onBattery($panic, 12, CarbonImmutable::now('UTC'));

        Notification::assertSentTo($onShift, SecurityDeviceAlertNotification::class);
        $alert = DeviceAlert::query()->where('device_id', $panic->id)->sole();
        expect($alert->events()->pluck('kind')->all())->toBe(['opened', 'told']);

        // IT still sees it in the 6:00 am summary.
        $this->freezeAt('2026-11-02T17:00:00Z');
        $this->engine->tick();
        $sent = Notification::sent($it, SecurityDeviceMorningSummaryNotification::class);
        expect($sent)->toHaveCount(1)
            ->and(implode(' | ', $sent->first()->lines))->toContain('Lounge panic button: battery 12%');
    }

    public function test_setup_saves_quiet_hours_and_summary_channels_and_logs_plain_english_history(): void
    {
        $manager = $this->person(['securityDevices.viewAny', 'securityDevices.integrations.view', 'securityDevices.automations.manage']);

        $this->actingAs($manager)->patchJson('/security-devices/setup/settings', [
            'changes' => [
                'alerts.quiet_hours.start' => '21:00',
                'alerts.quiet_hours.end' => '21:00',
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('alerts.quiet_hours.end');

        $this->actingAs($manager)->patchJson('/security-devices/setup/settings', [
            'changes' => [
                'alerts.quiet_hours.start' => '21:30',
                'alerts.summary.push' => true,
            ],
        ])->assertOk()->assertJsonPath('changed', ['alerts.quiet_hours.start', 'alerts.summary.push']);

        $this->actingAs($manager)->get('/security-devices/setup/history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('history.total', 2)
                ->where('history.rows', fn ($rows): bool => collect($rows)->pluck('what')->contains('Changed quiet hours to start at 9:30 pm')
                    && collect($rows)->pluck('what')->contains('Turned on push notices for the 6:00 am summary')));
    }
}
