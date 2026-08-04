<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ShiftHandoverService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-04-28 14:00:00', 'Pacific/Auckland'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function previousShiftContext(): array
{
    $site = Site::factory()->create();
    $worker = User::factory()->frontlineWorker()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);

    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subMonth(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return [$site, $worker, $client];
}

it('shows the previous shift summary after a recent completed shift', function () {
    [$site, $worker, $client] = previousShiftContext();
    $start = Carbon::parse('2026-04-28 08:00:00', 'Pacific/Auckland');
    $end = Carbon::parse('2026-04-28 12:00:00', 'Pacific/Auckland');
    $shift = Shift::factory()
        ->assignedToday($worker, $start)
        ->completed()
        ->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $worker->id,
            'actual_starts_at' => $start->copy()->utc(),
            'actual_ends_at' => $end->copy()->utc(),
            'completed_by' => $worker->id,
        ]);

    Timesheet::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $worker->id,
        'client_id' => $client->id,
        'shift_site_id' => $site->id,
        'work_date' => $start->toDateString(),
        'starts_at' => $start->copy()->utc(),
        'ends_at' => $end->copy()->utc(),
        'status' => 'draft',
    ]);

    ShiftHandover::factory()->create([
        'outgoing_shift_id' => $shift->id,
        'incoming_shift_id' => null,
        'client_id' => $client->id,
        'outgoing_staff_id' => $worker->id,
        'incoming_staff_id' => null,
        'status' => ShiftHandoverService::STATUS_SUBMITTED,
        'submitted_at' => $end->copy()->utc(),
        'submitted_by' => $worker->id,
    ]);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('next_shift_briefing', null)
            ->where('previous_shift.id', $shift->id)
            ->where('previous_shift.handover_sent', true)
            ->where('previous_shift.timesheet.status', 'draft')
        );
});

it('does not show old completed shifts as the wrap summary', function () {
    [$site, $worker, $client] = previousShiftContext();
    $start = Carbon::parse('2026-04-27 19:00:00', 'Pacific/Auckland');
    $end = Carbon::parse('2026-04-27 23:00:00', 'Pacific/Auckland');

    Shift::factory()
        ->assignedToday($worker, $start)
        ->completed()
        ->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $worker->id,
            'actual_starts_at' => $start->copy()->utc(),
            'actual_ends_at' => $end->copy()->utc(),
            'completed_by' => $worker->id,
        ]);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('previous_shift', null)
        );
});
