<?php

use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\TimesheetClientAllocation;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $supportRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);
    }

    $submitPermission = Permission::query()->where('key', 'timesheets.submit')->firstOrFail();
    $this->staff->permissionOverrides()->syncWithoutDetaching([
        $submitPermission->id => ['allowed' => true],
    ]);
});

test('time segmented allocation hours must match the submitted segment duration', function () {
    $client = Client::factory()->create();
    $serviceContext = ServiceContext::factory()->create();
    $startsAt = Carbon::parse('2026-06-08 09:00:00');
    $endsAt = Carbon::parse('2026-06-08 11:00:00');

    $shift = Shift::query()->create([
        'client_id' => $client->id,
        'service_context_id' => $serviceContext->id,
        'user_id' => $this->staff->id,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'status' => 'scheduled',
        'created_by' => $this->staff->id,
    ]);

    $timesheet = Timesheet::factory()->create([
        'shift_id' => $shift->id,
        'client_id' => $client->id,
        'user_id' => $this->staff->id,
        'work_date' => $startsAt->toDateString(),
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'break_minutes' => 0,
        'status' => 'draft',
        'created_by' => $this->staff->id,
    ]);

    $this->actingAs($this->staff)
        ->from('/my-day')
        ->post("/my-tasks/timesheet/{$timesheet->id}/submit", [
            'client_allocations' => [[
                'client_id' => $client->id,
                'hours' => 2,
                'allocation_method' => TimesheetClientAllocation::METHOD_TIME_SEGMENTED,
                'starts_at' => '2026-06-08T09:00:00',
                'ends_at' => '2026-06-08T10:00:00',
                'sort_order' => 0,
            ]],
        ])
        ->assertSessionHasErrors(['client_allocations.0.hours']);

    expect($timesheet->fresh()->status)->toBe('draft')
        ->and(TimesheetClientAllocation::query()->where('timesheet_id', $timesheet->id)->exists())->toBeFalse();
});
