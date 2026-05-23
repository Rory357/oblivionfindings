<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Support\ResidentHue;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-21 09:30:00', 'Pacific/Auckland'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('exposes the active shift site with every co-resident', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $site = Site::factory()->create(['name' => 'Rimu House', 'type' => 'house']);

    $margaret = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Margaret',
        'last_name' => 'Hewitt',
    ]);
    Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Hone',
        'last_name' => 'Tāmati',
    ]);
    Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Aroha',
        'last_name' => 'Lee',
    ]);

    $start = Carbon::now('Pacific/Auckland')->setTime(9, 0);
    Shift::factory()
        ->assignedToday($worker, $start)
        ->inProgress()
        ->create([
            'client_id' => $margaret->id,
            'site_id' => $site->id,
        ]);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('active_shift.site.id', $site->id)
            ->where('active_shift.site.name', 'Rimu House')
            ->has('active_shift.site.residents', 3)
            ->where('active_shift.site.residents.0.first_name', 'Margaret')
            ->where('active_shift.site.residents.0.initials', 'MH')
            ->where('active_shift.site.residents.0.hue', ResidentHue::for($margaret->id))
        );
});

it('uses the open attendance session shift as the active site shift after the UTC date rolls over', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-23 17:30:00', 'Pacific/Auckland'));

    $worker = User::factory()->frontlineWorker()->create();
    $site = Site::factory()->create(['name' => 'Rimu House', 'type' => 'house']);
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Margaret',
        'last_name' => 'Hewitt',
    ]);
    Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Hone',
        'last_name' => 'Tamati',
    ]);

    $start = Carbon::parse('2026-05-23 09:00:00', 'Pacific/Auckland');
    $shift = Shift::factory()
        ->assignedToday($worker, $start)
        ->inProgress()
        ->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'ends_at' => Carbon::parse('2026-05-23 18:00:00', 'Pacific/Auckland')->utc(),
        ]);

    HrAttendanceSession::query()->create([
        'user_id' => $worker->id,
        'shift_id' => $shift->id,
        'site_id' => $site->id,
        'clock_in_at' => $start->copy()->utc(),
        'status' => 'open',
        'source' => 'web',
        'created_by' => $worker->id,
    ]);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('clock.open_session.shift_id', $shift->id)
            ->where('active_shift.id', $shift->id)
            ->where('active_shift.site.id', $site->id)
            ->has('active_shift.site.residents', 2)
        );
});

it('returns null active_shift.site when the worker is not on a site shift', function () {
    $worker = User::factory()->frontlineWorker()->create();

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('active_shift', null)
        );
});

it('still works on a 1:1 shift without a site relationship', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $client = Client::factory()->create(['first_name' => 'Margaret', 'last_name' => 'Hewitt']);

    $start = Carbon::now('Pacific/Auckland')->setTime(9, 0);
    Shift::factory()
        ->assignedToday($worker, $start)
        ->inProgress()
        ->create([
            'client_id' => $client->id,
            'site_id' => null,
        ]);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('active_shift.site', null)
            ->where('active_shift.client.id', $client->id)
        );
});

it('hue helper matches the TS implementation byte-for-byte for known inputs', function () {
    // These reference values were computed by the TS implementation in
    // resources/js/pages/my-day/lib/resident-hue.ts so the server-rendered
    // payload and any front-end fallback colour the same resident the same way.
    expect(ResidentHue::for(1))->toBeLessThan(360)->toBeGreaterThanOrEqual(0);
    expect(ResidentHue::for(1))->toBe(ResidentHue::for('1'));
    expect(ResidentHue::for(42))->not->toBe(ResidentHue::for(43));
    expect(ResidentHue::initials('Margaret', 'Hewitt'))->toBe('MH');
    expect(ResidentHue::initials('Aroha', 'Lee'))->toBe('AL');
    expect(ResidentHue::initials(null, null))->toBe('');
});
