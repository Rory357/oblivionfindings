<?php

use App\Domain\Hr\Models\HrApprovalChain;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\ApprovalWorkflowService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

function approvalAuthorizationStaff(
    array $userOverrides = [],
    array $profileOverrides = [],
    array $rbacRoleNames = ['hr'],
): User {
    $user = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
        ...$userOverrides,
    ]);

    $user->roles()->syncWithoutDetaching(
        Role::query()->whereIn('name', $rbacRoleNames)->pluck('id')->all(),
    );

    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => 'APPROVER-'.$user->id,
        'position_role' => 'team_lead',
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
        ...$profileOverrides,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $hrRoleId = Role::query()->where('name', 'hr')->firstOrFail()->id;
    $this->currentApprover = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->otherManager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->initiator = User::factory()->create(['approved_at' => now()]);

    foreach ([$this->currentApprover, $this->otherManager] as $approver) {
        $approver->roles()->syncWithoutDetaching([$hrRoleId]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $approver->id,
            'employee_number' => 'ACT-'.$approver->id,
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $this->currentApprover->id,
            'updated_by' => $this->currentApprover->id,
        ]);
    }

    $chain = HrApprovalChain::query()->create([
        'name' => 'Current approver authorization',
        'process_type' => 'leave',
        'is_active' => true,
        'created_by' => $this->currentApprover->id,
    ]);
    $chain->steps()->create([
        'step_order' => 1,
        'approver_type' => 'user',
        'approver_user_id' => $this->currentApprover->id,
        'created_at' => now(),
    ]);

    $this->instance = app(ApprovalWorkflowService::class)
        ->initiateApproval($this->initiator, 'leave', $this->initiator);
});

test('a manager who is not the current approver cannot action an approval instance', function () {
    $this->actingAs($this->otherManager)
        ->post("/hr/approvals/{$this->instance->id}/action", ['action' => 'approved'])
        ->assertNotFound();

    expect($this->instance->fresh()->status)->toBe('pending');
    $this->assertDatabaseMissing('hr_approval_actions', [
        'approval_instance_id' => $this->instance->id,
        'actioned_by' => $this->otherManager->id,
    ]);
});

test('the configured current approver can action an approval instance', function () {
    $this->actingAs($this->currentApprover)
        ->post("/hr/approvals/{$this->instance->id}/action", ['action' => 'approved'])
        ->assertRedirect();

    expect($this->instance->fresh()->status)->toBe('approved');
    $this->assertDatabaseHas('hr_approval_actions', [
        'approval_instance_id' => $this->instance->id,
        'actioned_by' => $this->currentApprover->id,
        'action' => 'approved',
    ]);
});

test('an ineligible configured user is neither selected nor allowed to action the approval', function (
    array $userOverrides,
    array $profileOverrides,
    array $rbacRoleNames,
) {
    $candidate = approvalAuthorizationStaff($userOverrides, $profileOverrides, $rbacRoleNames);
    $this->instance->chain->steps()->firstOrFail()->update([
        'approver_type' => 'user',
        'approver_user_id' => $candidate->id,
        'approver_role_id' => null,
    ]);

    expect(app(ApprovalWorkflowService::class)->getCurrentApprover($this->instance))->toBeNull();

    expect(fn () => app(ApprovalWorkflowService::class)->processAction(
        $this->instance,
        $candidate,
        'approved',
    ))->toThrow(ModelNotFoundException::class);

    expect($this->instance->fresh()->status)->toBe('pending');
    $this->assertDatabaseMissing('hr_approval_actions', [
        'approval_instance_id' => $this->instance->id,
        'actioned_by' => $candidate->id,
    ]);
})->with([
    'unapproved user' => [['approved_at' => null], [], ['hr']],
    'legacy client portal identity' => [['role' => 'client'], [], ['hr']],
    'legacy next-of-kin portal identity' => [['role' => 'next_of_kin'], [], ['hr']],
    'RBAC client portal identity' => [[], [], ['hr', 'client']],
    'RBAC next-of-kin portal identity' => [[], [], ['hr', 'next_of_kin']],
    'inactive employee profile' => [[], ['is_active' => false], ['hr']],
    'ended employee profile' => [[], ['end_date' => today()->subDay()], ['hr']],
]);

test('a role approval step skips ineligible role holders and selects current approved staff', function (
    array $userOverrides,
    array $profileOverrides,
    array $rbacRoleNames,
) {
    $selectionRole = Role::query()->where('name', 'team_lead')->firstOrFail();
    $ineligible = approvalAuthorizationStaff(
        $userOverrides,
        $profileOverrides,
        [...$rbacRoleNames, 'team_lead'],
    );
    $eligible = approvalAuthorizationStaff([], [], ['team_lead']);

    expect($ineligible->id)->toBeLessThan($eligible->id);

    $this->instance->chain->steps()->firstOrFail()->update([
        'approver_type' => 'role',
        'approver_user_id' => null,
        'approver_role_id' => $selectionRole->id,
    ]);

    expect(app(ApprovalWorkflowService::class)->getCurrentApprover($this->instance)?->id)
        ->toBe($eligible->id);
})->with([
    'unapproved role holder' => [['approved_at' => null], [], []],
    'legacy client portal role holder' => [['role' => 'client'], [], []],
    'legacy next-of-kin portal role holder' => [['role' => 'next_of_kin'], [], []],
    'RBAC client portal role holder' => [[], [], ['client']],
    'RBAC next-of-kin portal role holder' => [[], [], ['next_of_kin']],
    'inactive employee profile role holder' => [[], ['is_active' => false], []],
    'ended employee profile role holder' => [[], ['end_date' => today()->subDay()], []],
]);
