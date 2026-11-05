<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Care\CareRules;
use App\Domain\SecurityDevices\Care\DevicePersonLinks;
use App\Domain\SecurityDevices\Care\Models\DevicePersonLink;
use App\Domain\SecurityDevices\Care\ScaldWatch;
use App\Domain\SecurityDevices\Care\SensorMonitoringConsent;
use App\Domain\SecurityDevices\Iot\Readings\SensorReadingHistory;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\ConsentType;
use App\Models\Permission;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Tests\Support\AuthoritativeConsentFixture;

/*
 * S&D Phase F: a sensor linked to a person's care only with their consent to monitoring (pop-up
 * 65), the falls rule that tells night staff when someone is out of bed too long (pg-sdr-7b), and a
 * hot-water probe that opens a scald-risk alert with an H&S hazard a person raises (pg-sdr-7c).
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
    $this->keeper = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.devices.update', 'securityDevices.people.link', 'hazards.create']);
    $this->sensor = function (string $name, Site $site): Device {
        $device = Device::factory()->create([
            'name' => $name, 'domain' => 'iot_healthcare', 'category' => 'environmental',
            'manufacturer' => 'Milesight', 'model' => 'EM300-TH', 'last_seen_at' => now(),
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $site->id,
            'assignment_type' => 'permanent', 'assigned_at' => now()->subWeek(),
        ]);

        return $device;
    };
    $this->bed = ($this->sensor)('Hemi’s bed sensor', $this->site);
    $this->hemi = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active', 'first_name' => 'Hemi', 'preferred_name' => null]);
    $this->consent = fn (Client $client) => AuthoritativeConsentFixture::manualSelf(
        $client,
        ConsentType::query()->where('name', SensorMonitoringConsent::TYPE)->firstOrFail(),
        $this->keeper,
        ['status' => 'given', 'given_at' => now()->subDay(), 'expires_at' => now()->addMonths(6)],
    );
    $this->link = fn (array $overrides = []) => $this->actingAs($this->keeper)->postJson("/security-devices/devices/{$this->bed->id}/care-link", [
        'client_id' => $this->hemi->id, 'purpose' => 'falls', 'rule' => ['minutes' => 10, 'from' => '22:00', 'to' => '06:00'], ...$overrides,
    ]);
    $this->nz = fn (string $time): CarbonImmutable => CarbonImmutable::parse($time, CareRules::ZONE)->utc();
});

it('links a sensor to a person only while their consent to monitoring is active', function (): void {
    ($this->link)()->assertUnprocessable()
        ->assertJsonPath('errors.client_id.0', 'Hemi’s consent to monitoring sensors isn’t active. Record it in Clients first.');
    $this->actingAs($this->keeper)->getJson("/security-devices/devices/{$this->bed->id}/care-link/options")
        ->assertOk()->assertJsonPath('people.0.consent.active', false);

    ($this->consent)($this->hemi);
    ($this->link)()->assertCreated();
    $link = DevicePersonLink::query()->sole();
    expect($link->purpose)->toBe('falls')
        // MySQL JSON keeps its own key order, so compare the values, not the order.
        ->and($link->rule)->toEqual(['minutes' => 10, 'from' => '22:00', 'to' => '06:00'])
        ->and($link->consent_id)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.person_link.created']);

    // One person per sensor; unlink first.
    ($this->link)()->assertUnprocessable()->assertJsonPath('errors.client_id.0', 'This sensor is already linked to someone. Unlink it first.');
});

it('never links someone at another Site, and needs the people.link permission', function (): void {
    ($this->consent)($this->hemi);
    $elsewhere = Client::factory()->create(['site_id' => $this->other->id, 'status' => 'active', 'first_name' => 'Aroha']);
    ($this->link)(['client_id' => $elsewhere->id])->assertNotFound();

    $viewer = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view']);
    $this->actingAs($viewer)->postJson("/security-devices/devices/{$this->bed->id}/care-link", ['client_id' => $this->hemi->id, 'purpose' => 'falls'])->assertForbidden();

    $farSensor = ($this->sensor)('Rimu Lodge sensor', $this->other);
    $this->actingAs($this->keeper)->getJson("/security-devices/devices/{$farSensor->id}/care-link/options")->assertNotFound();
});

it('reads in or out of bed the way bed sensors send it', function (array $data, ?bool $expected): void {
    expect(\App\Domain\SecurityDevices\Iot\Mqtt\MqttSensorIntakeService::bedOccupancy($data))->toBe($expected);
})->with([
    'in_bed true' => [['in_bed' => true], true],
    'bed_status out' => [['bed_status' => 'out_of_bed'], false],
    'occupancy occupied' => [['occupancy' => 'occupied'], true],
    'occupancy 0' => [['occupancy' => 0], false],
    'nothing about a bed' => [['temperature' => 21.5], null],
    'an unknown word' => [['bed_status' => 'maybe'], null],
]);

it('tells night staff when someone is out of bed past their plan’s minutes, and closes it when they’re back', function (): void {
    ($this->consent)($this->hemi);
    ($this->link)()->assertCreated();
    $history = app(SensorReadingHistory::class);
    $rules = app(CareRules::class);

    $history->record($this->bed->id, ['occupancy' => 1], ($this->nz)('2026-11-03 22:30'));
    $history->record($this->bed->id, ['occupancy' => 0], ($this->nz)('2026-11-03 23:00'));
    expect($rules->tick(($this->nz)('2026-11-03 23:05')))->toBe(0);
    expect($rules->tick(($this->nz)('2026-11-03 23:11')))->toBe(1);
    $alert = DeviceAlert::query()->where('kind', 'care_out_of_bed')->sole();
    expect($alert->title)->toBe('Hemi out of bed for over 10 minutes')
        ->and($alert->severity)->toBe('critical')
        ->and($rules->tick(($this->nz)('2026-11-03 23:20')))->toBe(0); // told once per time out of bed

    $history->record($this->bed->id, ['occupancy' => 1], ($this->nz)('2026-11-03 23:25'));
    expect($alert->fresh()->state)->toBe(DeviceAlert::STATE_DONE);
});

it('doesn’t alert outside the night hours, and unlinks when consent ends', function (): void {
    $consent = ($this->consent)($this->hemi);
    ($this->link)()->assertCreated();
    $history = app(SensorReadingHistory::class);

    $history->record($this->bed->id, ['occupancy' => 0], ($this->nz)('2026-11-04 07:05'));
    expect(app(CareRules::class)->tick(($this->nz)('2026-11-04 08:30')))->toBe(0)
        ->and(DeviceAlert::query()->count())->toBe(0);

    $consent->forceFill(['withdrawn_at' => now()->subMinute()])->save();
    expect(app(DevicePersonLinks::class)->sweepConsents())->toBe(1)
        ->and(DevicePersonLink::query()->sole()->unlink_reason)->toBe('consent_ended');
});

it('opens a scald alert after three hot readings, a person raises the hazard, and it closes after 24 hours under 45 °C', function (): void {
    $tap = ($this->sensor)('Hot water at Room 3 tap', $this->site);
    $this->actingAs($this->keeper)->putJson("/security-devices/devices/{$tap->id}/scald-watch", ['on' => true])->assertOk();
    $history = app(SensorReadingHistory::class);
    $start = CarbonImmutable::now('UTC')->subHours(30)->startOfMinute();

    $history->record($tap->id, ['temperature' => 41.0], $start);
    $history->record($tap->id, ['temperature' => 52.0], $start->addMinute());
    $history->record($tap->id, ['temperature' => 61.0], $start->addMinutes(2));
    expect(DeviceAlert::query()->where('kind', 'scald_risk')->count())->toBe(0); // two hot readings aren't three
    $history->record($tap->id, ['temperature' => 58.0], $start->addMinutes(3));
    $alert = DeviceAlert::query()->where('kind', 'scald_risk')->sole();
    expect($alert->severity)->toBe('critical');

    $raised = $this->actingAs($this->keeper)->postJson("/security-devices/alerts/{$alert->id}/hazard")->assertCreated();
    $hazard = SiteHazard::query()->sole();
    expect($hazard->hazard_type)->toBe('hot_water_temperature')
        ->and((int) $hazard->reported_by_user_id)->toBe($this->keeper->id)
        ->and((int) $hazard->site_id)->toBe($this->site->id)
        ->and($raised->json('url'))->toBe('/hazards/'.$hazard->id)
        ->and((int) $alert->fresh()->hazard_id)->toBe($hazard->id);
    $this->actingAs($this->keeper)->postJson("/security-devices/alerts/{$alert->id}/hazard")->assertCreated();
    expect(SiteHazard::query()->count())->toBe(1);

    // Three cool readings inside the last 24 hours before the check at hour 29 (the window starts at hour 5).
    foreach ([6, 16, 28] as $hours) {
        $history->record($tap->id, ['temperature' => 42.0], $start->addHours($hours));
    }
    expect(app(ScaldWatch::class)->tick($start->addHours(29)))->toBe(1)
        ->and($alert->fresh()->state)->toBe(DeviceAlert::STATE_DONE);
});

it('raises no hazard without the hazards.create permission', function (): void {
    $tap = ($this->sensor)('Hot water at Room 4 tap', $this->site);
    $tap->forceFill(['config' => [...(array) $tap->config, 'care' => ['scald_watch' => true]]])->save();
    $history = app(SensorReadingHistory::class);
    foreach ([0, 1, 2] as $minute) {
        $history->record($tap->id, ['temperature' => 55.0], CarbonImmutable::now('UTC')->subMinutes(10 - $minute));
    }
    $alert = DeviceAlert::query()->where('kind', 'scald_risk')->sole();
    $viewer = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.alerts.acknowledge']);

    $this->actingAs($viewer)->postJson("/security-devices/alerts/{$alert->id}/hazard")->assertForbidden();
    expect(SiteHazard::query()->count())->toBe(0);
});

it('renders the 7b Nights view, 4-check readiness ring, 7-night bars, and hides the resident’s name without client access', function (): void {
    $this->travelTo(($this->nz)('2026-11-04 09:00'));

    $this->bed->forceFill(['name' => 'Room 4 bed sensor'])->save();
    $this->hemi->forceFill(['last_name' => 'Ruru'])->save();
    ($this->consent)($this->hemi);
    $plan = \App\Models\CarePlan::query()->create([
        'client_id' => $this->hemi->id,
        'title' => 'Hemi falls prevention plan',
        'status' => 'active',
        'plan_type' => 'support',
        'starts_at' => '2026-10-01',
        'next_review_at' => '2027-01-12',
        'created_by' => $this->keeper->id,
    ]);
    ($this->link)(['care_plan_id' => $plan->id])->assertCreated();

    // Tonight's night shift on the roster (2026-11-04 22:00 -> 2026-11-05 07:00 NZ).
    \App\Models\Shift::query()->create([
        'site_id' => $this->site->id,
        'client_id' => $this->hemi->id,
        'user_id' => $this->keeper->id,
        'starts_at' => ($this->nz)('2026-11-04 22:00'),
        'ends_at' => ($this->nz)('2026-11-05 07:00'),
        'status' => 'scheduled',
    ]);

    $history = app(SensorReadingHistory::class);
    $rules = app(CareRules::class);
    // Last night (Tue 3 Nov 22:00 -> Wed 4 Nov 06:00 NZ): in bed at 22:15, out 12 min at 01:10 (opens alert), back at 01:22, up for the day at 07:05.
    $history->record($this->bed->id, ['occupancy' => 1], ($this->nz)('2026-11-03 22:15'));
    $history->record($this->bed->id, ['occupancy' => 0], ($this->nz)('2026-11-04 01:10'));
    $rules->tick(($this->nz)('2026-11-04 01:21'));
    $history->record($this->bed->id, ['occupancy' => 1], ($this->nz)('2026-11-04 01:22'));
    $history->record($this->bed->id, ['occupancy' => 0], ($this->nz)('2026-11-04 07:05'));

    // Clinical viewer who MAY view clients vs IT/device viewer who MAY NOT view clients.
    $clinical = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'clients.viewAny']);
    $deviceOnly = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view']);

    $this->actingAs($clinical)
        ->get("/security-devices/devices/{$this->bed->id}")
        ->assertOk()
        ->assertInertia(function ($page): void {
            $page->component('security-devices/devices/sensor')
                ->where('tab', 'nights')
                ->where('meters.0.label', 'Right now')
                ->where('meters.0.value', 'Up')
                ->where('meters.1.label', 'Last night')
                ->where('meters.1.value', 'Out once')
                ->where('meters.2.label', 'Alerts in 7 nights')
                ->where('meters.2.value', '1')
                ->where('nights.readiness.passed', 4)
                ->where('nights.readiness.label', 'Ready to alert night staff')
                ->where('nights.seven_nights.values.6', 12)
                ->where('nights.person_card.href', '/clients/'.$this->hemi->id);
            $props = $page->toArray()['props'];
            expect($props['nights']['care_banner']['kicker'])->toContain('Hemi');
            expect(collect($props['nights']['last_night']['items'])->pluck('title')->all())->toContain('Out of bed for 12 min');
        });

    // Viewer without client access gets no Nights tab at all (in or out of bed is about Hemi), and
    // never sees Hemi's name on the sensor page OR on Alerts.
    $nightsBody = (string) $this->actingAs($deviceOnly)
        ->get("/security-devices/devices/{$this->bed->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'readings')
            ->where('nights', null)
            ->where('care.link.person_href', null))
        ->getContent();
    expect($nightsBody)->not->toContain('Hemi');

    $alertsBody = (string) $this->actingAs($deviceOnly)
        ->get("/security-devices/devices/{$this->bed->id}?tab=alerts")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('alerts.0.title', 'A person out of bed for over 10 minutes'))
        ->getContent();
    expect($alertsBody)->not->toContain('Hemi');
});

it('shows the 7c tap temperature, hottest today and H&S hazard meters on a scald probe', function (): void {
    $tap = ($this->sensor)('Hot water at Room 3 tap', $this->site);
    $tap->forceFill([
        'battery_level' => 96,
        'config' => ['care' => ['scald_watch' => true]],
        'meta' => ['observed' => ['sensor_readings' => [
            'temperature' => ['label' => 'Temperature', 'value' => 58.0, 'unit' => 'C', 'state' => 'warning', 'observed_at' => now()->subMinute()->toIso8601String()],
        ]]],
    ])->save();

    $history = app(SensorReadingHistory::class);
    $base = CarbonImmutable::now('UTC')->subMinutes(5)->startOfMinute();
    $history->record($tap->id, ['temperature' => 52.0], $base);
    $history->record($tap->id, ['temperature' => 61.0], $base->addMinute());
    $history->record($tap->id, ['temperature' => 58.0], $base->addMinutes(2));

    $alert = DeviceAlert::query()->where('device_id', $tap->id)->where('kind', 'scald_risk')->sole();
    $viewer = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'hazards.create']);

    $this->actingAs($viewer)
        ->get("/security-devices/devices/{$tap->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('device.badge.label', 'Too hot')
            ->where('meters.0.label', 'At the tap now')
            ->where('meters.0.value', '58 °C')
            ->where('meters.1.label', 'Hottest today')
            ->where('meters.1.value', '61 °C')
            ->where('meters.2.label', 'H&S hazard')
            ->where('meters.2.value', 'Ready to raise')
            ->where('meters.3.label', 'Battery')
            ->where('meters.3.value', '96%')
            ->where('care.scald.alert.can_raise_hazard', true));

    $this->actingAs($viewer)->postJson("/security-devices/alerts/{$alert->id}/hazard")->assertCreated();

    $this->actingAs($viewer)
        ->get("/security-devices/devices/{$tap->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('meters.2.value', 'Raised')
            ->where('care.scald.alert.hazard_raised', true));
});

/*
 * Audit fixes for the 7b Nights view and the 7c meters: nothing is claimed that the stored readings,
 * alerts and roster don't show, and in/out of bed never reaches someone who can't open the record.
 */

it('counts time out of bed from the start of the night when they were already up, never “In bed all night”', function (): void {
    $this->travelTo(($this->nz)('2026-11-04 09:00'));
    ($this->consent)($this->hemi);
    ($this->link)()->assertCreated();
    $history = app(SensorReadingHistory::class);
    // Up at 9:30 pm, before the night hours (10 pm – 6 am); back in bed at 11:40 pm; up for the day at 7:05 am.
    $history->record($this->bed->id, ['occupancy' => 0], ($this->nz)('2026-11-03 21:30'));
    $history->record($this->bed->id, ['occupancy' => 1], ($this->nz)('2026-11-03 23:40'));
    $history->record($this->bed->id, ['occupancy' => 0], ($this->nz)('2026-11-04 07:05'));
    $clinical = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'clients.viewAny']);

    $this->actingAs($clinical)
        ->get("/security-devices/devices/{$this->bed->id}")
        ->assertOk()
        ->assertInertia(function ($page): void {
            $page->where('tab', 'nights')
                ->where('meters.0.value', 'Up')
                ->where('meters.1.label', 'Last night')
                ->where('meters.1.value', 'Out once')
                // No events.view: nothing about alerts, and never "under the rule" for 100 minutes.
                ->where('meters.1.caption.text', 'Longest 100 min · over the 10 min rule')
                ->where('nights.seven_nights.values.6', 100)
                ->where('nights.seven_nights.values.5', null);
            $items = collect($page->toArray()['props']['nights']['last_night']['items']);
            $out = $items->firstWhere('key', 'out-0');
            expect($items->pluck('title')->all())->not->toContain('In bed all night')
                ->and($out['time'])->toBe('10:00 pm')
                ->and($out['title'])->toBe('Out of bed for 100 min')
                ->and($out['sub'])->toBe('Already out of bed when night hours started · Back at 11:40 pm · over 10 minutes');
        });
});

it('says “Not available” for a night with no bed readings, and never draws it as 0', function (): void {
    $this->travelTo(($this->nz)('2026-11-04 09:00'));
    ($this->consent)($this->hemi);
    ($this->link)()->assertCreated();
    $clinical = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'clients.viewAny']);
    $page = fn () => $this->actingAs($clinical)->get("/security-devices/devices/{$this->bed->id}")->assertOk();

    $page()->assertInertia(fn ($page) => $page
        ->where('tab', 'nights')
        ->where('meters.0.value', null)
        ->where('meters.1.value', null)
        ->where('meters.1.caption.text', 'No bed readings recorded')
        ->where('nights.last_night.items', [])
        ->where('nights.last_night.empty', 'No bed readings were recorded last night.')
        ->where('nights.seven_nights.values', [null, null, null, null, null, null, null]));

    // One "in bed" reading at 9 pm on Sat 31 Oct: that night is a real 0, but a reading more than a
    // day old doesn't say anything about later nights.
    app(SensorReadingHistory::class)->record($this->bed->id, ['occupancy' => 1], ($this->nz)('2026-10-31 21:00'));
    $page()->assertInertia(fn ($page) => $page
        ->where('meters.1.value', null)
        ->where('nights.seven_nights.labels', ['Wed', 'Thu', 'Fri', 'Sat', 'Sun', 'Mon', 'Tue'])
        ->where('nights.seven_nights.values', [null, null, null, 0, null, null, null]));
});

it('never shows in or out of bed to someone who can’t open the resident’s record', function (): void {
    $this->travelTo(($this->nz)('2026-11-04 09:00'));
    $this->bed->forceFill(['name' => 'Room 4 bed sensor'])->save();
    ($this->consent)($this->hemi);
    ($this->link)()->assertCreated();
    $history = app(SensorReadingHistory::class);
    $history->record($this->bed->id, ['occupancy' => 1], ($this->nz)('2026-11-03 22:15'));
    $history->record($this->bed->id, ['occupancy' => 0], ($this->nz)('2026-11-04 01:10'));
    $history->record($this->bed->id, ['occupancy' => 1], ($this->nz)('2026-11-04 01:30'));
    $history->record($this->bed->id, ['occupancy' => 0], ($this->nz)('2026-11-04 07:05'));
    // May see devices and their alerts, but not Hemi's record.
    $deviceOnly = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view']);

    foreach (['', '?tab=nights'] as $query) {
        $body = (string) $this->actingAs($deviceOnly)
            ->get("/security-devices/devices/{$this->bed->id}{$query}")
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];
                $json = json_encode($props, JSON_UNESCAPED_UNICODE);
                expect($props['tab'])->toBe('readings')
                    ->and(collect($props['tabs'])->pluck('key')->all())->toBe(['readings', 'alerts'])
                    ->and($props['nights'])->toBeNull()
                    ->and(collect($props['meters'])->pluck('key')->intersect(['right_now', 'last_night', 'alerts_7d'])->values()->all())->toBe([])
                    ->and($json)->not->toContain('In bed')
                    ->and($json)->not->toContain('Out of bed')
                    ->and($json)->not->toContain('Out once')
                    ->and($json)->not->toContain('Falls risk');
            })
            ->getContent();
        expect($body)->not->toContain('Hemi');
    }
});

it('only counts the night shift as filled when the roster covers the whole night', function (): void {
    $this->travelTo(($this->nz)('2026-11-04 09:00'));
    ($this->consent)($this->hemi);
    ($this->link)()->assertCreated();
    $clinical = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'clients.viewAny']);
    $shift = fn (string $from, string $to, string $status = 'scheduled') => \App\Models\Shift::query()->create([
        'site_id' => $this->site->id,
        'client_id' => $this->hemi->id,
        'user_id' => $this->keeper->id,
        'starts_at' => ($this->nz)($from),
        'ends_at' => ($this->nz)($to),
        'status' => $status,
    ]);

    // Tonight's night hours: Wed 4 Nov 10 pm – Thu 5 Nov 6 am. Only 10 pm – 2 am is rostered; the
    // shift for the rest was cancelled.
    $shift('2026-11-04 22:00', '2026-11-05 02:00');
    $shift('2026-11-05 02:00', '2026-11-05 07:00', 'cancelled');
    $this->actingAs($clinical)
        ->get("/security-devices/devices/{$this->bed->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('nights.readiness.checks.3.state', 'warning')
            ->where('nights.readiness.checks.3.label', 'Night shift on the roster doesn’t cover the whole night')
            ->where('nights.readiness.checks.3.note', 'Not covered from 2:00 am to 6:00 am'));

    // A scheduled shift for the rest of the night: now it's filled.
    $shift('2026-11-05 02:00', '2026-11-05 07:00');
    $this->actingAs($clinical)
        ->get("/security-devices/devices/{$this->bed->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('nights.readiness.checks.3.state', 'success')
            ->where('nights.readiness.checks.3.label', 'Night shift filled on the roster tonight')
            ->where('nights.readiness.checks.3.note', '10:00 pm – 7:00 am'));
});

it('keeps normal hot water out of warning on a scald probe, and only counts today’s readings as hottest today', function (): void {
    $this->travelTo(($this->nz)('2026-11-04 12:00'));
    $probe = function (string $name, CarbonImmutable $at): Device {
        $tap = ($this->sensor)($name, $this->site);
        $tap->forceFill([
            'config' => ['care' => ['scald_watch' => true]],
            'meta' => ['observed' => ['sensor_readings' => [
                'temperature' => ['label' => 'Temperature', 'value' => 41.0, 'unit' => 'C', 'state' => 'healthy', 'observed_at' => $at->toIso8601String()],
            ]]],
        ])->save();
        app(SensorReadingHistory::class)->record($tap->id, ['temperature' => 41.0], $at);

        return $tap;
    };
    $viewer = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view']);

    // 41 °C is normal hot water: over the room-temperature limit (32 °C), but under the 45 °C scald rule.
    $today = $probe('Hot water at Room 5 tap', CarbonImmutable::now('UTC')->subMinute());
    $this->actingAs($viewer)
        ->get("/security-devices/devices/{$today->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('device.badge.label', fn (string $label): bool => $label !== 'Too hot')
            ->where('meters.0.label', 'At the tap now')
            ->where('meters.0.value', '41 °C')
            ->where('meters.0.tone', 'success')
            ->where('meters.0.caption.text', 'At or under 45 °C · safe')
            ->where('meters.1.label', 'Hottest today')
            ->where('meters.1.value', '41 °C')
            ->where('meters.2.value', 'None')
            ->where('meters.2.tone', 'success'));

    // Only a reading from yesterday: Hottest today is "Not available", never yesterday's reading.
    $yesterday = $probe('Hot water at Room 6 tap', CarbonImmutable::now('UTC')->subDay());
    $this->actingAs($viewer)
        ->get("/security-devices/devices/{$yesterday->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('meters.1.label', 'Hottest today')
            ->where('meters.1.value', null)
            ->where('meters.1.caption.text', 'No reading today'));
});
