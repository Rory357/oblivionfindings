<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
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
use App\Models\Site;
use App\Models\User;
use App\Notifications\SecurityDeviceAlertNotification;
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
        parent::tearDown();
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
}
