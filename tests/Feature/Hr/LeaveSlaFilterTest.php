<?php

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-02-18 10:00:00'));

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $role = Role::query()->where('name', 'hr')->first();
    if ($role) {
        $this->hr->roles()->syncWithoutDetaching([$role->id]);
    }
    $this->site = ensureCanonicalHrStaffProfile($this->hr);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    ensureCanonicalHrStaffProfile($this->staff, $this->site);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('leave dashboard sla filter returns only overdue pending requests', function () {
    $overdue = HrLeaveRequest::query()->create([
        'user_id' => $this->staff->id,
        'leave_type' => 'annual',
        'starts_at' => now()->addDays(5)->toDateString(),
        'ends_at' => now()->addDays(6)->toDateString(),
        'hours_requested' => 8,
        'status' => 'pending',
        'submitted_at' => now()->subHours(30),
        'approval_due_at' => now()->subHours(2),
    ]);

    HrLeaveRequest::query()->create([
        'user_id' => $this->staff->id,
        'leave_type' => 'sick',
        'starts_at' => now()->addDays(2)->toDateString(),
        'ends_at' => now()->addDays(2)->toDateString(),
        'hours_requested' => 8,
        'status' => 'pending',
        'submitted_at' => now()->subHours(2),
        'approval_due_at' => now()->addHours(3),
    ]);

    HrLeaveRequest::query()->create([
        'user_id' => $this->staff->id,
        'leave_type' => 'annual',
        'starts_at' => now()->addDays(8)->toDateString(),
        'ends_at' => now()->addDays(9)->toDateString(),
        'hours_requested' => 16,
        'status' => 'approved',
        'submitted_at' => now()->subDays(3),
        'reviewed_at' => now()->subDays(2),
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/leave?sla=overdue');
    $response->assertOk();

    $rows = collect($response->inertiaProps('requests.data'));
    $ids = $rows->pluck('id')->all();

    expect($response->inertiaProps('filters.sla'))->toBe('overdue');
    expect($ids)->toBe([$overdue->id]);
    expect($rows->first()['is_overdue'])->toBeTrue();
});
