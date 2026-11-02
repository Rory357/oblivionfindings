<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\SecurityDevices\Alerts\ItOnCall;
use App\Domain\SecurityDevices\Alerts\Models\OnCallHandover;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Screens\Alerts\QuietTimesPresenter;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** S&D round 3: Alerts › Quiet times (pg-sdr-13c) and › On call (13d), and telling the person on call first. */
class AlertsQuietTimesOnCallTest extends TestCase
{
    use RefreshDatabase;

    private Site $home;

    private Site $other;

    private Device $switch;

    private Device $otherSwitch;

    private User $jordan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->home = Site::factory()->create(['name' => 'Kauri House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->other = Site::factory()->create(['name' => 'Kōwhai Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->switch = $this->device($this->home, 'Kauri House switch');
        $this->otherSwitch = $this->device($this->other, 'Kōwhai Lodge switch');
        $this->jordan = $this->person('Jordan Peri');
    }

    private function device(Site $site, string $name): Device
    {
        $device = Device::factory()->itInfrastructure()->create(['name' => $name]);
        DeviceAssignment::create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $site->id, 'assigned_at' => now()->subDays(30)]);

        return $device;
    }

    /** @param  list<string>|null  $keys */
    private function person(string $name, ?array $keys = null): User
    {
        $user = User::factory()->create(['name' => $name, 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->home->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach ($keys ?? ['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'securityDevices.alerts.acknowledge', 'securityDevices.monitoring.manage'] as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    }

    /** @param  array<string, mixed>  $attributes */
    private function quiet(Device $device, Site $site, CarbonImmutable $from, CarbonImmutable $to, array $attributes = []): MonitoringMaintenanceWindow
    {
        return MonitoringMaintenanceWindow::query()->create([
            'site_id' => $site->id, 'device_id' => $device->id, 'name' => 'Quiet time · '.$device->name,
            'starts_at' => $from, 'ends_at' => $to, 'status' => 'active', 'reason' => 'Switch software update 6.6.65',
            'created_by_user_id' => $this->jordan->id, ...$attributes,
        ]);
    }

    private function onCallShift(User $user, CarbonImmutable $from, CarbonImmutable $to): Shift
    {
        return Shift::factory()->create([
            'site_id' => $this->home->id, 'user_id' => $user->id, 'starts_at' => $from, 'ends_at' => $to,
            'status' => 'scheduled', 'is_on_call' => true, 'published_at' => now(),
        ]);
    }

    public function test_quiet_times_lists_the_viewers_quiet_times_coming_up_first_then_the_ones_that_ended(): void
    {
        $now = CarbonImmutable::now('UTC');
        $this->quiet($this->switch, $this->home, $now->addHours(2), $now->addHours(3));
        $this->quiet($this->switch, $this->home, $now->addDays(3), $now->addDays(3)->addHour(), ['reason' => 'Firewall software update']);
        $this->quiet($this->switch, $this->home, $now->subDay(), $now->subDay()->addMinutes(30), ['reason' => 'Software update 6.6.61']);
        $this->quiet($this->switch, $this->home, $now->subDays(2), $now->subDays(2)->addHours(3), [
            'reason' => 'Electrician moving the comms cupboard power', 'status' => 'cancelled',
            'cancelled_at' => $now->subDays(2)->addHour(), 'cancelled_by_user_id' => $this->jordan->id, 'cancellation_reason' => 'The electrician finished early',
        ]);
        $this->quiet($this->otherSwitch, $this->other, $now->addHours(5), $now->addHours(6), ['reason' => 'Another Site’s work']);

        $response = $this->actingAs($this->jordan)->get('/security-devices/alerts?tab=quiet');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('tab', 'quiet')
            ->where('counts.quiet', 2)
            ->has('quiet_times.rows', 4)
            ->where('quiet_times.rows.0.paused.name', 'Kauri House switch')
            ->where('quiet_times.rows.0.state.tone', 'info')
            ->where('quiet_times.rows.0.can_end', true)
            ->where('quiet_times.rows.1.state.label', 'Planned')
            ->where('quiet_times.rows.2.state.label', 'Ended')
            ->where('quiet_times.rows.2.state.note', 'No alerts went out for it')
            ->where('quiet_times.rows.2.can_end', false)
            ->where('quiet_times.rows.3.state.label', 'Ended early')
            ->where('quiet_times.rows.3.state.note.text', 'Ended {time} by Jordan Peri')
            ->where('quiet_times.rows.0.set_by', 'Jordan Peri')
            ->where('quiet_times.can.add', true));
        $this->assertStringStartsWith('Starts in', (string) $response->viewData('page')['props']['quiet_times']['rows'][0]['state']['label']);
        $this->assertStringNotContainsString('Another Site', (string) $response->getContent());
    }

    public function test_held_back_reads_the_checks_that_the_quiet_time_paused(): void
    {
        $now = CarbonImmutable::now('UTC');
        $window = $this->quiet($this->switch, $this->home, $now->subHours(3), $now->subHours(2));
        $monitor = Monitor::factory()->create(['device_id' => $this->switch->id]);
        $applied = ['state' => 'applied', 'winner' => ['snapshot' => ['id' => $window->id]]];
        foreach ([[$now->subMinutes(170), MonitorState::Failed, $applied], [$now->subMinutes(165), MonitorState::Failed, $applied], [$now->subMinutes(160), MonitorState::Healthy, ['state' => 'none']]] as $n => [$at, $state, $evidence]) {
            MonitorObservation::query()->create([
                'monitor_id' => $monitor->id, 'source_key' => 'held:'.$n.':'.Str::uuid(), 'state' => $state, 'metrics' => [],
                'observed_at' => $at, 'ingested_at' => $at, 'maintenance_policy_evidence' => $evidence,
            ]);
        }
        // A failure outside quiet time's say-so isn't "held back".
        MonitorObservation::query()->create([
            'monitor_id' => $monitor->id, 'source_key' => 'not-held:'.Str::uuid(), 'state' => MonitorState::Failed, 'metrics' => [],
            'observed_at' => $now->subMinutes(150), 'ingested_at' => $now->subMinutes(150), 'maintenance_policy_evidence' => ['state' => 'none'],
        ]);

        $held = app(QuietTimesPresenter::class)->present($this->jordan, null, $now)['held'];

        $this->assertCount(1, $held);
        $this->assertSame('Kauri House switch missed 2 checks', $held[0]['title']);
        $this->assertSame('success', $held[0]['tone']);
        $this->assertSame('Held while quiet time was on · answering again {time}', $held[0]['sub']['text']);
    }

    public function test_the_it_person_on_call_is_told_first_and_others_only_without_one(): void
    {
        $now = CarbonImmutable::now('UTC');
        $sam = $this->person('Sam Tuilagi');
        $worker = $this->person('Mere Support', ['securityDevices.viewAny']);
        $onCall = app(ItOnCall::class);

        $this->onCallShift($sam, $now->addDays(3), $now->addDays(10));
        $this->onCallShift($worker, $now->subHour(), $now->addHours(8));
        $this->assertSame([$this->jordan->id, $sam->id], $onCall->first(collect([$this->jordan, $sam]))->pluck('id')->all(), 'A support worker on call doesn’t cover IT.');

        $this->onCallShift($this->jordan, $now->subDays(2), $now->addDays(5));
        $this->assertSame([$this->jordan->id], $onCall->first(collect([$this->jordan, $sam]))->pluck('id')->all());
        // Someone on call who wouldn't be told about that Site doesn't take the alert from those who would.
        $this->assertSame([$sam->id, $worker->id], $onCall->first(collect([$sam, $worker]))->pluck('id')->all());

        $this->actingAs($this->jordan)->get('/security-devices/alerts?tab=oncall')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'oncall')
                ->where('on_call.now.name', 'Jordan Peri')
                ->has('on_call.people', 2)
                ->where('on_call.people.0.when', 'now')
                ->where('on_call.people.1.name', 'Sam Tuilagi')
                ->where('on_call.people.1.when', 'next')
                ->where('on_call.delivery.0.when', 'A device or hub stops answering')
                ->where('on_call.can.handover', true));
    }

    public function test_a_handover_note_goes_to_the_next_person_on_call(): void
    {
        $now = CarbonImmutable::now('UTC');
        $sam = $this->person('Sam Tuilagi');
        $this->onCallShift($this->jordan, $now->subDays(2), $now->addDays(5));
        $this->onCallShift($sam, $now->addDays(5), $now->addDays(12));

        $this->actingAs($this->jordan)->postJson('/security-devices/alerts/on-call/handover', ['still_open' => '', 'coming_up' => ' '])
            ->assertStatus(422);
        $this->actingAs($this->jordan)->postJson('/security-devices/alerts/on-call/handover', ['still_open' => 'Kōwhai Lodge router dropped twice at night.'])
            ->assertCreated()
            ->assertJson(['message' => 'Handover saved for Sam Tuilagi.']);

        $note = OnCallHandover::query()->sole();
        $this->assertSame($this->jordan->id, (int) $note->from_user_id);
        $this->assertSame($sam->id, (int) $note->to_user_id);

        $this->actingAs($this->jordan)->get('/security-devices/alerts?tab=oncall')
            ->assertInertia(fn ($page) => $page
                ->where('on_call.handover.from', 'Jordan Peri')
                ->where('on_call.handover.to', 'Sam Tuilagi')
                ->where('on_call.handover.still_open', 'Kōwhai Lodge router dropped twice at night.'));

        $reader = $this->person('Ana Ruha', ['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view']);
        $this->actingAs($reader)->postJson('/security-devices/alerts/on-call/handover', ['still_open' => 'Not mine to write'])->assertForbidden();
        $this->actingAs($reader)->get('/security-devices/alerts?tab=oncall')
            ->assertInertia(fn ($page) => $page->where('on_call.handover', null)->where('on_call.can.handover', false));
    }
}
