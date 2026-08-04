<?php

use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->manager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->manager->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->first()->id]);
    $this->site = ensureCanonicalHrStaffProfile($this->manager);

    $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    ensureCanonicalHrStaffProfile($this->staff, $this->site);
});

test('requests export streams a formula-guarded CSV', function () {
    HrLeaveRequest::query()->create([
        'user_id' => $this->staff->id, 'leave_type' => 'annual', 'period' => 'full_day',
        'starts_at' => now()->addDays(5)->startOfDay(), 'ends_at' => now()->addDays(5)->endOfDay(),
        'hours_requested' => 8, 'status' => 'pending', 'submitted_at' => now(), 'escalation_level' => 1,
        'reason' => '=1+1',
    ]);

    $response = $this->actingAs($this->manager)->get(route('hr.leave.export', ['format' => 'csv']));
    $response->assertOk();

    $csv = $response->streamedContent();
    expect($csv)->toContain('Staff');
    expect($csv)->toContain('Leave type');
    expect($csv)->toContain("'=1+1"); // formula injection neutralised
});

test('balances export downloads a CSV', function () {
    HrLeaveBalance::query()->create([
        'user_id' => $this->staff->id, 'leave_type' => 'annual', 'year' => now()->year,
        'balance_hours' => 160, 'accrued_hours' => 160, 'used_hours' => 40, 'pending_hours' => 8,
    ]);

    $this->actingAs($this->manager)
        ->get(route('hr.leave.balances.export', ['format' => 'csv', 'year' => now()->year]))
        ->assertOk();
});

test('reports export downloads a CSV', function () {
    $this->actingAs($this->manager)
        ->get(route('hr.leave.reports.export', ['format' => 'csv']))
        ->assertOk();
});

test('a non-manager cannot export', function () {
    $this->actingAs($this->staff)
        ->get(route('hr.leave.export'))
        ->assertForbidden();
});
