<?php

use App\Models\AppSetting;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    // Freeze the same NZ instant in UTC so Eloquent date casts continue to
    // interpret database timestamps as UTC during the request.
    $now = Carbon::parse('2026-04-28 08:45:00', 'Pacific/Auckland')->utc();

    Carbon::setTestNow($now);
    Date::setTestNow($now);
});

afterEach(function () {
    Carbon::setTestNow();
    Date::setTestNow();
});

it('requires authentication for roster data', function () {
    $this->getJson('/my-roster/data')->assertUnauthorized();
});

it('returns today upcoming and recent shifts for the authenticated worker', function () {
    $worker = User::factory()->create();
    $otherWorker = User::factory()->create();

    $todayStart = Carbon::parse('2026-04-28 09:00:00', 'Pacific/Auckland');
    $upcomingStart = Carbon::parse('2026-05-02 10:00:00', 'Pacific/Auckland');
    $recentStart = Carbon::parse('2026-04-26 08:00:00', 'Pacific/Auckland');
    $outsideStart = Carbon::parse('2026-05-20 08:00:00', 'Pacific/Auckland');

    Shift::factory()->create([
        'user_id' => $worker->id,
        'starts_at' => $todayStart->copy()->utc(),
        'ends_at' => $todayStart->copy()->addHours(4)->utc(),
        'status' => 'scheduled',
    ]);

    Shift::factory()->create([
        'user_id' => $worker->id,
        'starts_at' => $upcomingStart->copy()->utc(),
        'ends_at' => $upcomingStart->copy()->addHours(4)->utc(),
        'status' => 'scheduled',
    ]);

    Shift::factory()->create([
        'user_id' => $worker->id,
        'starts_at' => $recentStart->copy()->utc(),
        'ends_at' => $recentStart->copy()->addHours(4)->utc(),
        'actual_starts_at' => $recentStart->copy()->utc(),
        'actual_ends_at' => $recentStart->copy()->addHours(4)->utc(),
        'status' => 'completed',
    ]);

    Shift::factory()->create([
        'user_id' => $worker->id,
        'starts_at' => $outsideStart->copy()->utc(),
        'ends_at' => $outsideStart->copy()->addHours(4)->utc(),
        'status' => 'scheduled',
    ]);

    Shift::factory()->create([
        'user_id' => $otherWorker->id,
        'starts_at' => $todayStart->copy()->utc(),
        'ends_at' => $todayStart->copy()->addHours(4)->utc(),
        'status' => 'scheduled',
    ]);

    $this->actingAs($worker)
        ->getJson('/my-roster/data')
        ->assertOk()
        ->assertJsonCount(1, 'today_shifts')
        ->assertJsonCount(1, 'upcoming_shifts')
        ->assertJsonCount(1, 'recent_shifts')
        ->assertJsonPath('today_shifts.0.status_state', 'starting-soon')
        ->assertJsonPath('recent_shifts.0.status_state', 'completed')
        ->assertJsonPath('window.timezone', 'Pacific/Auckland');
});

it('hides unpublished shifts from my roster when publish workflow is enabled', function () {
    config(['features.rostering.publish' => true]);

    $worker = User::factory()->create();
    $todayStart = Carbon::parse('2026-04-28 09:00:00', 'Pacific/Auckland');

    $published = Shift::factory()->create([
        'user_id' => $worker->id,
        'starts_at' => $todayStart->copy()->utc(),
        'ends_at' => $todayStart->copy()->addHours(4)->utc(),
        'status' => 'scheduled',
        'published_at' => $todayStart->copy()->subDay()->utc(),
    ]);

    Shift::factory()->create([
        'user_id' => $worker->id,
        'starts_at' => $todayStart->copy()->addHour()->utc(),
        'ends_at' => $todayStart->copy()->addHours(5)->utc(),
        'status' => 'scheduled',
        'published_at' => null,
    ]);

    $this->actingAs($worker)
        ->getJson('/my-roster/data')
        ->assertOk()
        ->assertJsonCount(1, 'today_shifts')
        ->assertJsonPath('today_shifts.0.id', $published->id);
});

it('uses the global application setting for the frontline publish filter', function () {
    config(['features.rostering.publish' => false]);

    AppSetting::create([
        'key' => 'features.rostering.publish',
        'value' => true,
    ]);

    $worker = User::factory()->create();
    $todayStart = Carbon::parse('2026-04-28 09:00:00', 'Pacific/Auckland');

    Shift::factory()->create([
        'user_id' => $worker->id,
        'starts_at' => $todayStart->copy()->utc(),
        'ends_at' => $todayStart->copy()->addHours(4)->utc(),
        'status' => 'scheduled',
        'published_at' => null,
    ]);

    $this->actingAs($worker)
        ->getJson('/my-roster/data')
        ->assertOk()
        ->assertJsonCount(0, 'today_shifts');
});

it('hides unpublished shifts from my calendar events when publish workflow is enabled', function () {
    config(['features.rostering.publish' => true]);

    $worker = User::factory()->create(['organization_id' => 1]);
    $todayStart = Carbon::parse('2026-04-28 09:00:00', 'Pacific/Auckland');

    $published = Shift::factory()->create([
        'organization_id' => 1,
        'user_id' => $worker->id,
        'starts_at' => $todayStart->copy()->utc(),
        'ends_at' => $todayStart->copy()->addHours(4)->utc(),
        'status' => 'scheduled',
        'published_at' => $todayStart->copy()->subDay()->utc(),
    ]);

    Shift::factory()->create([
        'organization_id' => 1,
        'user_id' => $worker->id,
        'starts_at' => $todayStart->copy()->addHour()->utc(),
        'ends_at' => $todayStart->copy()->addHours(5)->utc(),
        'status' => 'scheduled',
        'published_at' => null,
    ]);

    $this->actingAs($worker)
        ->getJson('/my-calendar/events?start=2026-04-28&end=2026-04-29')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', 'shift-'.$published->id);
});
