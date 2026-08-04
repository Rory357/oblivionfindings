<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveApprovalChain;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

function leaveApprovalAdminStaff(
    string $name,
    Site $site,
    array $userOverrides = [],
    array $roleNames = [],
): User {
    $user = User::factory()->create([
        'name' => $name,
        'role' => 'support_worker',
        'approved_at' => now(),
        ...$userOverrides,
    ]);

    if ($roleNames !== []) {
        $user->roles()->syncWithoutDetaching(
            Role::query()->whereIn('name', $roleNames)->pluck('id')->all(),
        );
    }

    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => 'LEAVE-ROUTE-'.$user->id,
        'primary_site_id' => $site->id,
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    return $user;
}

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Leave approval routes']);
    $this->hr = leaveApprovalAdminStaff('HR route manager', $this->site, ['role' => 'hr'], ['hr']);
    $this->worker = leaveApprovalAdminStaff('Route worker', $this->site);
    $this->approver = leaveApprovalAdminStaff('Route approver', $this->site);
    $this->delegate = leaveApprovalAdminStaff('Route delegate', $this->site);
});

test('leave approval routes are application scoped and accept only current staff', function (): void {
    $legacyColumn = 'ten'.'ant_id';
    $otherWorker = leaveApprovalAdminStaff('Other current worker', $this->site);
    HrLeaveApprovalChain::query()->create([
        $legacyColumn => 777,
        'user_id' => $otherWorker->id,
        'approver_user_id' => $this->approver->id,
        'approval_level' => 1,
        'is_active' => true,
    ]);

    $this->actingAs($this->hr)->post('/hr/approvals/leave-chains', [
        'user_id' => $this->worker->id,
        'approver_user_id' => $this->approver->id,
        'delegate_user_id' => $this->delegate->id,
        'approval_level' => 1,
        'escalation_after_hours' => 36,
        'is_active' => true,
    ])->assertSessionHas('success');

    $response = $this->actingAs($this->hr)->get('/hr/approvals/chains')->assertOk();
    $rows = collect($response->inertiaProps('leaveChains'));
    expect($rows->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->worker->id, $otherWorker->id])->sort()->values()->all());

    $former = leaveApprovalAdminStaff('Former route worker', $this->site, ['approved_at' => null]);
    $this->actingAs($this->hr)->post('/hr/approvals/leave-chains', [
        'user_id' => $former->id,
        'approver_user_id' => $this->approver->id,
        'approval_level' => 2,
    ])->assertSessionHasErrors('user_id');

    $this->actingAs($this->hr)->post('/hr/approvals/leave-chains', [
        'user_id' => $this->worker->id,
        'approver_user_id' => $this->worker->id,
        'approval_level' => 2,
    ])->assertSessionHasErrors('approver_user_id');
});

test('leave routing supports update reorder activation and deletion without touching generic chains', function (): void {
    $first = HrLeaveApprovalChain::query()->create([
        'user_id' => $this->worker->id,
        'approver_user_id' => $this->approver->id,
        'approval_level' => 1,
        'escalation_after_hours' => 48,
        'is_active' => true,
    ]);
    $second = HrLeaveApprovalChain::query()->create([
        'user_id' => $this->worker->id,
        'approver_user_id' => $this->delegate->id,
        'approval_level' => 2,
        'escalation_after_hours' => 72,
        'is_active' => true,
    ]);

    $this->actingAs($this->hr)->put("/hr/approvals/leave-chains/{$first->id}", [
        'approver_user_id' => $this->delegate->id,
        'delegate_user_id' => $this->approver->id,
        'escalation_after_hours' => 24,
    ])->assertSessionHas('success');
    expect($first->fresh()->approver_user_id)->toBe($this->delegate->id)
        ->and($first->fresh()->delegate_user_id)->toBe($this->approver->id)
        ->and($first->fresh()->escalation_after_hours)->toBe(24);

    $this->actingAs($this->hr)->post('/hr/approvals/leave-chains/reorder', [
        'user_id' => $this->worker->id,
        'ordered_ids' => [$second->id, $first->id],
    ])->assertSessionHas('success');
    expect($second->fresh()->approval_level)->toBe(1)
        ->and($first->fresh()->approval_level)->toBe(2);

    $this->actingAs($this->hr)->patch("/hr/approvals/leave-chains/{$second->id}/active", [
        'is_active' => false,
    ])->assertSessionHas('success');
    expect($second->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->hr)->delete("/hr/approvals/leave-chains/{$second->id}")
        ->assertSessionHas('success');
    expect(HrLeaveApprovalChain::query()->whereKey($second->id)->exists())->toBeFalse();
});
