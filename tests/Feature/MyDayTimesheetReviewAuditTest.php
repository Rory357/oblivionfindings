<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Shifts\Timesheets\TimesheetAllocationService;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-12 10:30', 'Pacific/Auckland')->utc());
    $this->worker = User::factory()->frontlineWorker()->create();
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->profile = HrEmployeeProfile::factory()->create(['user_id' => $this->worker->id, 'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subYear(), 'end_date' => null]);
    foreach (['timesheets.create', 'timesheets.update', 'timesheets.submit', 'shifts.viewAssigned'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'timesheets', 'module' => 'Operations']);
        $this->worker->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    }
    $this->people = Client::factory()->count(3)->create(['site_id' => $this->site->id, 'status' => 'active']);
    $start = Carbon::parse('2026-09-12 07:00', 'Pacific/Auckland')->utc();
    $end = Carbon::parse('2026-09-12 10:00', 'Pacific/Auckland')->utc();
    $this->shift = Shift::factory()->create(['user_id' => $this->worker->id, 'site_id' => $this->site->id,
        'client_id' => $this->people[0]->id, 'status' => 'in_progress', 'starts_at' => $start, 'ends_at' => $end,
        'actual_starts_at' => $start, 'actual_ends_at' => $end, 'expected_break_minutes' => 0]);
    $this->session = HrAttendanceSession::create(['user_id' => $this->worker->id, 'shift_id' => $this->shift->id,
        'site_id' => $this->site->id, 'clock_in_at' => $start, 'clock_out_at' => $end, 'break_minutes' => 0,
        'status' => 'closed', 'source' => 'web', 'created_by' => $this->worker->id, 'closed_by' => $this->worker->id]);
    $this->sheet = Timesheet::factory()->create(['user_id' => $this->worker->id, 'created_by' => $this->worker->id,
        'client_id' => $this->people[0]->id, 'shift_id' => $this->shift->id, 'shift_site_id' => $this->site->id,
        'attendance_session_id' => $this->session->id, 'work_date' => '2026-09-12', 'starts_at' => $start, 'ends_at' => $end,
        'break_minutes' => 0, 'status' => 'draft']);
    $this->rows = $this->people->map(fn ($person) => ['client_id' => $person->id, 'hours' => 1, 'allocation_method' => 'equal_split'])->all();
    $this->revision = fn () => app(TimesheetAllocationService::class)->revision($this->sheet->fresh());
    $this->actingAs($this->worker);
});

it('offers all three people on the authorised site for time attribution', function () {
    $candidates = app(TimesheetAllocationService::class)->candidates($this->sheet, $this->worker);
    expect(array_column($candidates, 'id'))->toEqualCanonicalizing($this->people->modelKeys());
});

it('saves an unfinished split and reopens it without changing paid hours', function () {
    $this->rows[1]['hours'] = 0;
    $response = $this->putJson("/my-day/timesheets/{$this->sheet->id}/allocations", [
        'expected_revision' => ($this->revision)(), 'client_allocations' => $this->rows,
    ])->assertOk()->assertJsonPath('status', 'draft');
    expect($this->sheet->fresh()->clientAllocations)->toHaveCount(3)
        ->and((float) $this->sheet->fresh()->total_hours)->toBe(3.0)
        ->and($response->json('revision'))->toBe(($this->revision)());
    $this->postJson("/my-tasks/timesheet/{$this->sheet->id}/submit", [
        'expected_revision' => ($this->revision)(), 'client_allocations' => $this->rows,
    ])->assertUnprocessable();
    expect($this->sheet->fresh()->status)->toBe('draft');
});

it('submits a three-person split once through the canonical workflow', function () {
    $input = ['expected_revision' => ($this->revision)(), 'client_allocations' => $this->rows];
    $this->postJson("/my-tasks/timesheet/{$this->sheet->id}/submit", $input)->assertOk()->assertJsonPath('status', 'submitted');
    expect($this->sheet->fresh()->clientAllocations)->toHaveCount(3)
        ->and((float) $this->sheet->fresh()->total_hours)->toBe(3.0);
    $this->postJson("/my-tasks/timesheet/{$this->sheet->id}/submit", $input)->assertUnprocessable();
    expect($this->sheet->fresh()->clientAllocations)->toHaveCount(3);
});

it('can save the reviewed split after a blocked payroll check changes only reconciliation metadata', function () {
    $this->shift->update(['ends_at' => $this->shift->ends_at->copy()->addHours(4)]);
    $revision = ($this->revision)();
    $this->travel(1)->minutes();
    $input = ['expected_revision' => $revision, 'client_allocations' => $this->rows];
    $this->postJson("/my-tasks/timesheet/{$this->sheet->id}/submit", $input)->assertUnprocessable();
    expect($this->sheet->fresh()->status)->toBe('draft')->and(($this->revision)())->toBe($revision);
    $this->putJson("/my-day/timesheets/{$this->sheet->id}/allocations", $input)->assertOk();
    expect($this->sheet->fresh()->clientAllocations)->toHaveCount(3);
});

it('rejects stale changes and preserves the latest saved split', function () {
    $revision = ($this->revision)();
    $this->putJson("/my-day/timesheets/{$this->sheet->id}/allocations", ['expected_revision' => $revision, 'client_allocations' => $this->rows])->assertOk();
    $this->rows[0]['hours'] = 2;
    $this->putJson("/my-day/timesheets/{$this->sheet->id}/allocations", ['expected_revision' => $revision, 'client_allocations' => $this->rows])
        ->assertUnprocessable()->assertJsonValidationErrors('timesheet');
    expect((float) $this->sheet->fresh()->clientAllocations()->sum('hours'))->toBe(3.0);
});

it('rejects a person from another site without changing allocation or status', function () {
    $foreign = Client::factory()->create();
    $this->rows[0]['client_id'] = $foreign->id;
    $this->postJson("/my-tasks/timesheet/{$this->sheet->id}/submit", ['expected_revision' => ($this->revision)(), 'client_allocations' => $this->rows])->assertUnprocessable();
    expect($this->sheet->fresh()->clientAllocations)->toHaveCount(0)->and($this->sheet->fresh()->status)->toBe('draft');
});

it('rejects mixed methods duplicate people and rounding mismatches', function (string $fault) {
    if ($fault === 'mixed') {
        $this->rows[1]['allocation_method'] = 'manual';
    }
    if ($fault === 'duplicate') {
        $this->rows[1]['client_id'] = $this->rows[0]['client_id'];
    }
    if ($fault === 'rounding') {
        $this->rows[1]['hours'] = 0.99;
    }
    $this->postJson("/my-tasks/timesheet/{$this->sheet->id}/submit", ['expected_revision' => ($this->revision)(), 'client_allocations' => $this->rows])->assertUnprocessable();
    expect($this->sheet->fresh()->clientAllocations)->toHaveCount(0);
})->with(['mixed', 'duplicate', 'rounding']);

it('rejects overlapping support periods even when their total balances', function () {
    $rows = [
        ['client_id' => $this->people[0]->id, 'hours' => 1.5, 'allocation_method' => 'time_segmented', 'starts_at' => '2026-09-12T07:00:00+12:00', 'ends_at' => '2026-09-12T08:30:00+12:00'],
        ['client_id' => $this->people[1]->id, 'hours' => 1.5, 'allocation_method' => 'time_segmented', 'starts_at' => '2026-09-12T07:30:00+12:00', 'ends_at' => '2026-09-12T09:00:00+12:00'],
    ];
    $this->postJson("/my-tasks/timesheet/{$this->sheet->id}/submit", ['expected_revision' => ($this->revision)(), 'client_allocations' => $rows])->assertUnprocessable()->assertJsonValidationErrors('client_allocations');
});

it('rechecks current site access and submission permission', function (string $revoke) {
    $revision = ($this->revision)();
    if ($revoke === 'site') {
        $this->profile->update(['primary_site_id' => Site::factory()->create()->id]);
    } else {
        $this->worker->permissionOverrides()->updateExistingPivot(Permission::where('key', 'timesheets.submit')->value('id'), ['allowed' => false]);
    }
    $this->postJson("/my-tasks/timesheet/{$this->sheet->id}/submit", ['expected_revision' => $revision, 'client_allocations' => $this->rows])->assertForbidden();
    expect($this->sheet->fresh()->clientAllocations)->toHaveCount(0);
})->with(['site', 'permission']);

it('does not submit while the worker is still clocked in', function () {
    $this->session->update(['clock_out_at' => null, 'status' => 'open']);
    $this->postJson("/my-tasks/timesheet/{$this->sheet->id}/submit", ['expected_revision' => ($this->revision)(), 'client_allocations' => $this->rows])->assertUnprocessable();
    expect($this->sheet->fresh()->status)->toBe('draft')->and($this->sheet->fresh()->clientAllocations)->toHaveCount(0);
});

it('cannot bypass an unfinished saved split through the legacy submit command', function () {
    $this->rows[0]['hours'] = 0;
    $this->putJson("/my-day/timesheets/{$this->sheet->id}/allocations", ['expected_revision' => ($this->revision)(), 'client_allocations' => $this->rows])->assertOk();
    $this->postJson("/my-tasks/timesheet/{$this->sheet->id}/submit", [])->assertUnprocessable();
    expect($this->sheet->fresh()->status)->toBe('draft');
});

it('rejects both saving and submitting an approved timesheet', function () {
    $this->sheet->update(['status' => 'approved', 'approved_at' => now()]);
    $input = ['expected_revision' => ($this->revision)(), 'client_allocations' => $this->rows];
    $this->putJson("/my-day/timesheets/{$this->sheet->id}/allocations", $input)->assertUnprocessable();
    $this->postJson("/my-tasks/timesheet/{$this->sheet->id}/submit", $input)->assertUnprocessable();
    expect($this->sheet->fresh()->clientAllocations)->toHaveCount(0)->and($this->sheet->fresh()->status)->toBe('approved');
});

it('uses the requested current shift when two shifts share a work date', function () {
    $next = Shift::factory()->published()->create(['user_id' => $this->worker->id, 'site_id' => $this->site->id, 'client_id' => $this->people[1]->id,
        'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(4), 'expected_break_minutes' => 0]);
    $this->from('/my-day')->post('/my-tasks/timesheet/ensure-today', ['shift_id' => $next->id])->assertRedirect('/my-day')->assertSessionHas('open_timesheet_id');
    $new = Timesheet::where('shift_id', $next->id)->sole();
    expect($new->id)->not->toBe($this->sheet->id)->and($new->client_id)->toBe($this->people[1]->id)->and($new->shift_site_id)->toBe($this->site->id);
    $this->post('/my-tasks/timesheet/ensure-today', ['shift_id' => $next->id])->assertRedirect();
    expect(Timesheet::where('shift_id', $next->id)->count())->toBe(1);
});

it('finds an open overnight shift by its attendance rather than the current date', function () {
    // Put the other attended shift on an earlier day; a worker cannot have
    // overlapping attendance merely to exercise date-based selection.
    $earlierStart = $this->shift->starts_at->copy()->subDays(2);
    $earlierEnd = $this->shift->ends_at->copy()->subDays(2);
    $this->shift->update(['starts_at' => $earlierStart, 'ends_at' => $earlierEnd, 'actual_starts_at' => $earlierStart, 'actual_ends_at' => $earlierEnd]);
    $this->session->update(['clock_in_at' => $earlierStart, 'clock_out_at' => $earlierEnd]);
    $this->sheet->update(['starts_at' => $earlierStart, 'ends_at' => $earlierEnd, 'work_date' => '2026-09-10']);
    $start = Carbon::parse('2026-09-11 22:00', 'Pacific/Auckland')->utc();
    $overnight = Shift::factory()->published()->create(['user_id' => $this->worker->id, 'site_id' => $this->site->id, 'client_id' => $this->people[1]->id,
        'starts_at' => $start, 'ends_at' => now()->subHours(2), 'status' => 'in_progress', 'expected_break_minutes' => 0]);
    HrAttendanceSession::create(['user_id' => $this->worker->id, 'shift_id' => $overnight->id, 'site_id' => $this->site->id,
        'clock_in_at' => $start, 'status' => 'open', 'source' => 'web', 'created_by' => $this->worker->id]);
    $this->post('/my-tasks/timesheet/ensure-today', [])->assertRedirect();
    expect(Timesheet::where('shift_id', $overnight->id)->count())->toBe(1);
});

it('keeps complete dates for support periods across midnight', function () {
    $start = Carbon::parse('2026-09-11 23:00', 'Pacific/Auckland')->utc();
    $end = $start->copy()->addHours(3);
    $this->shift->update(['starts_at' => $start, 'ends_at' => $end, 'actual_starts_at' => $start, 'actual_ends_at' => $end]);
    $this->session->update(['clock_in_at' => $start, 'clock_out_at' => $end]);
    $this->sheet->update(['work_date' => '2026-09-11', 'starts_at' => $start, 'ends_at' => $end]);
    $rows = $this->people->map(fn ($person, $i) => ['client_id' => $person->id, 'hours' => 1, 'allocation_method' => 'time_segmented',
        'starts_at' => $start->copy()->addHours($i)->toIso8601String(), 'ends_at' => $start->copy()->addHours($i + 1)->toIso8601String()])->all();
    $this->postJson("/my-tasks/timesheet/{$this->sheet->id}/submit", ['expected_revision' => ($this->revision)(), 'client_allocations' => $rows])->assertOk();
    expect($this->sheet->fresh()->clientAllocations)->toHaveCount(3);
});

it('rejects support periods outside the paid shift even when balanced', function () {
    $rows = [['client_id' => $this->people[0]->id, 'hours' => 3, 'allocation_method' => 'time_segmented',
        'starts_at' => '2026-09-12T06:00:00+12:00', 'ends_at' => '2026-09-12T09:00:00+12:00']];
    $this->postJson("/my-tasks/timesheet/{$this->sheet->id}/submit", ['expected_revision' => ($this->revision)(), 'client_allocations' => $rows])->assertUnprocessable()->assertJsonValidationErrors('client_allocations.0.starts_at');
});

it('rejects ambiguous or nonexistent local times instead of silently changing their meaning', function (string $wall) {
    $rows = [['client_id' => $this->people[0]->id, 'hours' => 0, 'allocation_method' => 'time_segmented', 'starts_at' => $wall]];
    $this->putJson("/my-day/timesheets/{$this->sheet->id}/allocations", ['expected_revision' => ($this->revision)(), 'client_allocations' => $rows])
        ->assertUnprocessable()->assertJsonValidationErrors('client_allocations');
})->with(['2026-04-05T02:30:00', '2026-09-27T02:30:00']);
