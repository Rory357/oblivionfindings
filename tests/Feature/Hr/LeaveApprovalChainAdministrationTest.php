<?php

use App\Domain\Hr\Models\HrLeaveApprovalChain;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    $this->hr = User::factory()->create(['organization_id' => 1, 'role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->firstOrFail()->id]);
    $this->worker = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $this->approver = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $this->delegate = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
});

test('leave approval routing rows are tenant scoped on the chains page and create endpoint', function () {
    $foreign = User::factory()->create(['organization_id' => 2, 'approved_at' => now()]);
    HrLeaveApprovalChain::query()->create([
        'tenant_id' => 2,
        'user_id' => $foreign->id,
        'approver_user_id' => $foreign->id,
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
    expect($rows)->toHaveCount(1)
        ->and($rows->first()['user_id'])->toBe($this->worker->id)
        ->and($rows->first()['approver_user_id'])->toBe($this->approver->id);

    $this->actingAs($this->hr)->post('/hr/approvals/leave-chains', [
        'user_id' => $foreign->id,
        'approver_user_id' => $this->approver->id,
        'approval_level' => 2,
    ])->assertSessionHasErrors('user_id');
});

test('leave routing supports update reorder activation and deletion without touching generic chains', function () {
    $first = HrLeaveApprovalChain::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->worker->id,
        'approver_user_id' => $this->approver->id,
        'approval_level' => 1,
        'escalation_after_hours' => 48,
        'is_active' => true,
    ]);
    $second = HrLeaveApprovalChain::query()->create([
        'tenant_id' => 1,
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
