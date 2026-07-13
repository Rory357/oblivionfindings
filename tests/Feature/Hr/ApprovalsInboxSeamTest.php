<?php

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrApprovalChain;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrOffer;
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
 * type appears — and lock D-1's surface-only federation: real native approval
 * queues appear without moving their state transitions onto the chain service.
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

    $byType = collect($data)->keyBy('process_type');
    expect($byType['leave']['item_label'])->toBe("Leave request #{$this->hr->id}")
        ->and($byType['expense']['item_label'])->toBe("Expense claim #{$this->hr->id}")
        ->and($byType['timesheet']['item_label'])->toBe("Timesheet #{$this->hr->id}")
        ->and($byType['document']['item_label'])->toBe("Document #{$this->hr->id}")
        ->and($byType->every(fn ($item) => str_contains($item['initiated_at'], 'T')))->toBeTrue();
});

test('S14 seam (D-1): real native approvables surface with tenant-safe links while staying off the spine', function () {
    // 'recruitment' is not a chain process type (the storeChain enum is
    // leave/expense/timesheet/document), so a recruitment approvable can never
    // create an HrApprovalInstance — initiating one throws.
    expect(fn () => app(ApprovalWorkflowService::class)->initiateApproval($this->hr, 'recruitment', $this->hr))
        ->toThrow(LogicException::class, "process type 'recruitment'");

    $requester = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

    $leave = HrLeaveRequest::factory()->create([
        'tenant_id' => 1,
        'user_id' => $requester->id,
        'status' => 'pending',
        'submitted_at' => now()->subMinutes(40),
    ]);
    $expense = HrExpenseClaim::factory()->create([
        'tenant_id' => 1,
        'user_id' => $requester->id,
        'status' => 'submitted',
        'submitted_at' => now()->subMinutes(30),
    ]);
    $candidate = HrCandidate::factory()->create([
        'tenant_id' => 1,
        'status' => 'offer_pending',
        'created_by' => $this->hr->id,
    ]);
    $application = HrApplication::factory()->create([
        'tenant_id' => 1,
        'candidate_id' => $candidate->id,
        'position_title' => 'Support Worker',
    ]);
    $offer = HrOffer::query()->create([
        'application_id' => $application->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'proposed_start_date' => now()->addMonth()->toDateString(),
        'employment_type' => 'full_time',
        'approval_status' => 'pending_approval',
        'approval_requested_at' => now()->subMinutes(20),
        'created_by' => $this->hr->id,
    ]);
    $requisition = HrJobRequisition::query()->create([
        'tenant_id' => 1,
        'title' => 'Team Leader',
        'slug' => 'team-leader-approval',
        'position_role' => 'team_lead',
        'employment_type' => 'full_time',
        'openings' => 1,
        'requires_approval' => true,
        'status' => 'pending_approval',
        'created_by' => $this->hr->id,
    ]);

    // Foreign-tenant and already-completed records must not leak into the inbox.
    HrLeaveRequest::factory()->create(['tenant_id' => 2, 'status' => 'pending']);
    HrExpenseClaim::factory()->create(['tenant_id' => 1, 'status' => 'approved']);

    $response = $this->actingAs($this->hr)->get('/hr/approvals/pending');
    $response->assertOk();

    $native = collect($response->inertiaProps('nativeApprovals'));
    expect($native->pluck('type')->all())->toBe(['requisition', 'offer', 'expense', 'leave']);
    expect($native->pluck('url', 'type')->all())->toBe([
        'requisition' => '/hr/recruitment?tab=requisitions',
        'offer' => '/hr/recruitment?tab=offers',
        'expense' => "/hr/compensation/expenses/{$expense->id}",
        'leave' => "/hr/leave/{$leave->id}",
    ]);
    expect($native->pluck('id', 'type')->all())->toBe([
        'requisition' => $requisition->id,
        'offer' => $offer->id,
        'expense' => $expense->id,
        'leave' => $leave->id,
    ]);
    expect($response->inertiaProps('instances.data'))->toBe([]);
});
