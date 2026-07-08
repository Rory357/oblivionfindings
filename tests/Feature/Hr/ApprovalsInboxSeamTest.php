<?php

use App\Domain\Hr\Models\HrApprovalChain;
use App\Domain\Hr\Services\ApprovalWorkflowService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

/**
 * Seam S14 — the /hr/approvals inbox (the intended single approvals surface).
 *
 * The inbox (`ApprovalController::pending`) reads `HrApprovalInstance::pending()`
 * and maps each to its chain's `process_type`. Instances are created ONLY by
 * `ApprovalWorkflowService::initiateApproval`, whose supported process types are
 * `leave` / `expense` / `timesheet` / `document` (the chain enum). These tests
 * prove the surface does what it claims — a pending instance of every claimed
 * type appears — and document the D-1 gap: recruitment approvals never reach it.
 *
 * They also lock in F-78: `initiateApproval` used to stamp the instance with the
 * raw `$initiator->tenant_id` (always null — users are tenanted by
 * organization_id), so a service-created instance filed under tenant NULL was
 * invisible to the inbox (which filters on the resolved org tenant). Fixed to
 * stamp the resolved tenant; test 1 would show an empty inbox without it.
 *
 * D-1 stays open for Chane: no business flow calls initiateApproval yet (leave →
 * HrLeaveApprovalChain, expenses → inline ExpenseService, recruitment →
 * recruitment-local notify), so the spine is correct but unfed. Surface, don't
 * rebuild.
 */
beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    // hr.approvals.* live in SeedHrPermissionsSeeder → the hr role holds them.
    $this->hr = User::factory()->create(['organization_id' => 1, 'role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->first()->id]);
});

function hrSeamApprovalChain(int $creatorId, string $processType): HrApprovalChain
{
    $chain = HrApprovalChain::query()->create([
        'tenant_id' => 1,
        'name' => ucfirst($processType).' Approval',
        'process_type' => $processType,
        'is_active' => true,
        'created_by' => $creatorId,
    ]);

    $chain->steps()->create([
        'step_order' => 1,
        'approver_type' => 'manager',
        'created_at' => now(),
    ]);

    return $chain;
}

test('S14 seam: the approvals inbox surfaces a pending instance of every claimed process type', function () {
    $service = app(ApprovalWorkflowService::class);

    // Feed one genuinely-pending approval of each supported type through the real
    // service path (the only creator of HrApprovalInstance).
    foreach (['leave', 'expense', 'timesheet', 'document'] as $type) {
        hrSeamApprovalChain($this->hr->id, $type);
        $service->initiateApproval($this->hr, $type, $this->hr);
    }

    $data = $this->actingAs($this->hr)
        ->get('/hr/approvals/pending')
        ->inertiaProps('instances.data');

    // All four claimed types surface (and F-78: they are visible at all — the
    // instances were stamped with the inbox's resolved tenant, not NULL).
    expect(collect($data)->pluck('process_type')->sort()->values()->all())
        ->toBe(['document', 'expense', 'leave', 'timesheet']);
    expect(collect($data)->every(fn ($i) => $i['status'] === 'pending'))->toBeTrue();
});

test('S14 seam (D-1 gap): recruitment approvals live outside the spine and never reach the inbox', function () {
    // 'recruitment' is not a chain process type (the storeChain enum is
    // leave/expense/timesheet/document), so a recruitment approvable can never
    // create an HrApprovalInstance — initiating one throws.
    expect(fn () => app(ApprovalWorkflowService::class)->initiateApproval($this->hr, 'recruitment', $this->hr))
        ->toThrow(\LogicException::class, "process type 'recruitment'");

    // And the inbox — which reads only HrApprovalInstance — surfaces nothing for
    // recruitment: offers awaiting approval (HrOffer.approval_status='pending')
    // are handled by the recruitment-local notify flow, off the spine. D-1.
    $data = $this->actingAs($this->hr)
        ->get('/hr/approvals/pending')
        ->inertiaProps('instances.data');

    expect($data)->toBe([]);
});
