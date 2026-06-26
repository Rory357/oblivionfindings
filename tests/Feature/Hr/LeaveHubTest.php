<?php

use App\Domain\Hr\Models\HrLeaveBalance;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

test('the leave hub index ships the request-modal staff and leave types', function () {
    $response = $this->actingAs($this->hr)->get('/hr/leave');
    $response->assertOk();

    expect(collect($response->inertiaProps('leaveTypes'))->pluck('value'))
        ->toContain('annual');
    // staff is shipped (may be empty if no profiles) — assert the key exists.
    expect($response->inertiaProps('staff'))->not->toBeNull();
});

test('the create route redirects to the hub (modal supersedes the page)', function () {
    $this->actingAs($this->hr)
        ->get('/hr/leave/create')
        ->assertRedirect(route('hr.leave.index'));
});

test('a leave request can be submitted via the store endpoint', function () {
    $this->actingAs($this->hr)
        ->post('/hr/leave', [
            'user_id' => $this->hr->id,
            'leave_type' => 'annual',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->addDay()->toDateString(),
            'hours_requested' => 8,
            'reason' => 'Family time',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('hr_leave_requests', [
        'user_id' => $this->hr->id,
        'leave_type' => 'annual',
        'status' => 'pending',
    ]);
});

test('the balances view pivots per staff member with remaining = balance − used − pending', function () {
    HrLeaveBalance::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->hr->id,
        'leave_type' => 'annual',
        'year' => now()->year,
        'balance_hours' => 160,
        'accrued_hours' => 160,
        'used_hours' => 40,
        'pending_hours' => 8,
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/leave/balances?year='.now()->year);
    $response->assertOk();

    // Balances are now a flat array, one pivoted row per staff member
    // (annual / sick / alternative each carrying remaining + entitlement).
    $rows = collect($response->inertiaProps('balances'));
    $row = $rows->firstWhere('user_id', $this->hr->id);

    expect($row)->not->toBeNull();
    expect((float) $row['annual']['entitlement'])->toBe(160.0);
    expect((float) $row['annual']['remaining'])->toBe(112.0); // 160 - 40 - 8
    expect((float) $row['pending'])->toBe(8.0);
});
