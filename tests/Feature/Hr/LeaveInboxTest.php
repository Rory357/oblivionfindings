<?php

use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->first()->id]);
    $this->hr->setAttribute('tenant_id', 1);

    $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->staff->setAttribute('tenant_id', 1);
});

function makePending(User $staff, User $approver, int $offsetDays, array $overrides = []): HrLeaveRequest
{
    return HrLeaveRequest::query()->create(array_merge([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'leave_type' => 'annual',
        'period' => 'full_day',
        'starts_at' => now()->addDays($offsetDays)->startOfDay(),
        'ends_at' => now()->addDays($offsetDays)->endOfDay(),
        'hours_requested' => 8,
        'status' => 'pending',
        'submitted_at' => now()->subHours($offsetDays),
        'approval_due_at' => now()->addHours(20),
        'escalated_to' => $approver->id,
        'escalation_level' => 1,
    ], $overrides));
}

test('the inbox is a cross-page queue: all_pending count reflects the true total beyond page 1', function () {
    for ($i = 1; $i <= 22; $i++) {
        makePending($this->staff, $this->hr, $i);
    }

    $response = $this->actingAs($this->hr)->get('/hr/leave');
    $response->assertOk();

    $inbox = $response->inertiaProps('approvalInbox');
    expect($inbox)->toHaveKeys(['awaiting_my_decision', 'escalated_to_me', 'all_pending', 'recently_decided']);

    // The paginated list is page-bound (20), but the inbox sees ALL 22 pending.
    expect(count($response->inertiaProps('requests.data')))->toBe(20);
    expect($inbox['all_pending']['count'])->toBe(22);
    expect(count($inbox['all_pending']['items']))->toBe(22);
    expect($inbox['awaiting_my_decision']['count'])->toBe(22);
});

test('inbox rows carry balance_impact with an insufficient flag', function () {
    HrLeaveBalance::query()->create([
        'tenant_id' => 1, 'user_id' => $this->staff->id, 'leave_type' => 'annual', 'year' => now()->year,
        'balance_hours' => 8, 'accrued_hours' => 8, 'used_hours' => 0, 'pending_hours' => 32,
        'source' => 'system', 'last_synced_at' => now(), 'updated_by' => $this->hr->id,
    ]);
    $req = makePending($this->staff, $this->hr, 3, ['hours_requested' => 32]);

    $response = $this->actingAs($this->hr)->get('/hr/leave');
    $row = collect($response->inertiaProps('approvalInbox.all_pending.items'))->firstWhere('id', $req->id);

    expect($row['balance_impact'])->not->toBeNull();
    expect($row['balance_impact']['insufficient'])->toBeTrue();
    expect($row['balance_impact']['projected_after'])->toBeLessThan(0);
});

test('inbox rows surface a roster conflict when an overlapping shift exists', function () {
    $req = makePending($this->staff, $this->hr, 4);

    Shift::factory()->create([
        'user_id' => $this->staff->id,
        'starts_at' => now()->addDays(4)->setTime(7, 0),
        'ends_at' => now()->addDays(4)->setTime(15, 0),
        'status' => 'scheduled',
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/leave');
    $row = collect($response->inertiaProps('approvalInbox.all_pending.items'))->firstWhere('id', $req->id);

    expect($row['roster_conflict']['has_conflict'])->toBeTrue();
    expect($row['roster_conflict']['count'])->toBeGreaterThan(0);
});
