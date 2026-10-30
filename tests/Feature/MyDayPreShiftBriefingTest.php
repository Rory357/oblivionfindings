<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-04-28 08:30:00', 'Pacific/Auckland')->utc());
});

afterEach(function () {
    Carbon::setTestNow();
});

it('shows the next shift briefing inside the twelve hour window', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $start = Carbon::parse('2026-04-28 09:00:00', 'Pacific/Auckland');
    $shift = Shift::factory()
        ->assignedToday($worker, $start)
        ->create(['notes' => 'Check the fluids chart before breakfast.']);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('next_shift_briefing.id', $shift->id)
            ->where('next_shift_briefing.what_to_know', 'Check the fluids chart before breakfast.')
            ->where('previous_shift', null)
        );
});

it('hides the pre shift briefing once the worker is clocked in', function () {
    $worker = User::factory()->frontlineWorker()->create();
    // The open clock session only shows for a shift at a Site where the
    // worker is currently employed.
    $site = Site::factory()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subMonth(),
        'end_date' => null,
        'is_active' => true,
    ]);
    $start = Carbon::parse('2026-04-28 08:00:00', 'Pacific/Auckland');
    $shift = Shift::factory()
        ->assignedToday($worker, $start)
        ->inProgress()
        ->create([
            'client_id' => Client::factory()->create(['site_id' => $site->id])->id,
            'site_id' => $site->id,
            'actual_starts_at' => $start->copy()->utc(),
            'started_by' => $worker->id,
        ]);

    HrAttendanceSession::query()->create([
        'tenant_id' => null,
        'user_id' => $worker->id,
        'shift_id' => $shift->id,
        'clock_in_at' => $start->copy()->utc(),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $worker->id,
    ]);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('next_shift_briefing', null)
            ->where('clock.open_session.shift_id', $shift->id)
        );
});
