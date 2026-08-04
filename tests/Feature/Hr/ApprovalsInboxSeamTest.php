<?php

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrApprovalChain;
use App\Domain\Hr\Models\HrApprovalInstance;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Services\ApprovalWorkflowService;
use App\Models\Role;
use App\Models\Site;
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
 * prove the surface does what it claims, restrict it to the signed-in current
 * approver, and lock D-1's surface-only federation: real native approval queues
 * appear without moving their state transitions onto the chain service.
 *
 * D-1 stays open for Chane: no business flow calls initiateApproval yet (leave →
 * HrLeaveApprovalChain, expenses → inline ExpenseService, recruitment →
 * recruitment-local notify), so the spine is correct but unfed. Surface, don't
 * rebuild.
 */
beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Approvals inbox Site']);

    // hr.approvals.* live in SeedHrPermissionsSeeder → the hr role holds them.
    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->first()->id]);

    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'employee_number' => 'APP-'.$this->hr->id,
        'start_date' => today()->subYear(),
        'end_date' => null,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
});

function hrSeamApprovalChain(int $creatorId, string $processType, int $approverId): HrApprovalChain
{
    $chain = HrApprovalChain::query()->create([
        'name' => ucfirst($processType).' Approval',
        'process_type' => $processType,
        'is_active' => true,
        'created_by' => $creatorId,
    ]);

    $chain->steps()->create([
        'step_order' => 1,
        'approver_type' => 'user',
        'approver_user_id' => $approverId,
        'created_at' => now(),
    ]);

    return $chain;
}

test('S14 seam: the approvals inbox surfaces a pending instance of every claimed process type', function () {
    $service = app(ApprovalWorkflowService::class);

    // Feed one genuinely-pending approval of each supported type through the real
    // service path (the only creator of HrApprovalInstance).
    foreach (['leave', 'expense', 'timesheet', 'document'] as $type) {
        hrSeamApprovalChain($this->hr->id, $type, $this->hr->id);
        $service->initiateApproval($this->hr, $type, $this->hr);
    }

    $data = $this->actingAs($this->hr)
        ->get('/hr/approvals/pending')
        ->inertiaProps('instances.data');

    // All four claimed types surface for their configured current approver.
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

test('S14 seam: the approvals inbox conceals instances assigned to another current approver', function () {
    $otherApprover = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $otherApprover->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->firstOrFail()->id]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $otherApprover->id,
        'employee_number' => 'APP-'.$otherApprover->id,
        'start_date' => today()->subYear(),
        'end_date' => null,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $service = app(ApprovalWorkflowService::class);
    hrSeamApprovalChain($this->hr->id, 'leave', $this->hr->id);
    $mine = $service->initiateApproval($this->hr, 'leave', $this->hr);
    hrSeamApprovalChain($this->hr->id, 'expense', $otherApprover->id);
    $service->initiateApproval($otherApprover, 'expense', $this->hr);

    $ids = collect($this->actingAs($this->hr)
        ->get('/hr/approvals/pending')
        ->assertOk()
        ->inertiaProps('instances.data'))
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$mine->id]);
});

test('S14 seam: workflow initiation hides its required compatibility storage', function () {
    $legacyColumn = 'ten'.'ant_id';
    $initiator = User::factory()->create(['approved_at' => now()]);
    hrSeamApprovalChain($this->hr->id, 'leave', $this->hr->id);

    $instance = rescue(
        fn () => app(ApprovalWorkflowService::class)->initiateApproval($initiator, 'leave', $initiator),
        report: false,
    );

    expect($instance)->toBeInstanceOf(HrApprovalInstance::class)
        ->and($instance?->toArray())->not->toHaveKey($legacyColumn);
});

test('S14 seam (D-1): real native approvables surface with canonical links while staying off the spine', function () {
    // 'recruitment' is not a chain process type (the storeChain enum is
    // leave/expense/timesheet/document), so a recruitment approvable can never
    // create an HrApprovalInstance — initiating one throws.
    expect(fn () => app(ApprovalWorkflowService::class)->initiateApproval($this->hr, 'recruitment', $this->hr))
        ->toThrow(LogicException::class, "process type 'recruitment'");

    $requester = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $requester->id,
        'employee_number' => 'APP-'.$requester->id,
        'primary_site_id' => $this->site->id,
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $leave = HrLeaveRequest::factory()->create([
        'user_id' => $requester->id,
        'status' => 'pending',
        'submitted_at' => now()->subMinutes(40),
    ]);
    $expense = HrExpenseClaim::factory()->create([
        'user_id' => $requester->id,
        'status' => 'submitted',
        'submitted_at' => now()->subMinutes(30),
    ]);
    $candidate = HrCandidate::factory()->create([
        'status' => 'offer_pending',
        'created_by' => $this->hr->id,
    ]);
    $application = HrApplication::factory()->create([
        'candidate_id' => $candidate->id,
        'position_title' => 'Support Worker',
        'target_site_id' => $this->site->id,
    ]);
    $offer = HrOffer::query()->create([
        'application_id' => $application->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'proposed_start_date' => now()->addMonth()->toDateString(),
        'employment_type' => 'full_time',
        'primary_site_id' => $this->site->id,
        'approval_status' => 'pending_approval',
        'approval_requested_at' => now()->subMinutes(20),
        'created_by' => $this->hr->id,
    ]);
    $requisition = HrJobRequisition::query()->create([
        'title' => 'Team Leader',
        'slug' => 'team-leader-approval',
        'position_role' => 'team_lead',
        'employment_type' => 'full_time',
        'openings' => 1,
        'site_id' => $this->site->id,
        'requires_approval' => true,
        'status' => 'pending_approval',
        'created_by' => $this->hr->id,
    ]);

    $hiddenSite = Site::factory()->create(['name' => 'Hidden approvals Site']);
    $hiddenRequester = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $hiddenRequester->id,
        'employee_number' => 'APP-HIDDEN-'.$hiddenRequester->id,
        'primary_site_id' => $hiddenSite->id,
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
    $hiddenLeave = HrLeaveRequest::factory()->create([
        'user_id' => $hiddenRequester->id,
        'status' => 'pending',
        'submitted_at' => now()->subMinutes(15),
    ]);
    $hiddenExpense = HrExpenseClaim::factory()->create([
        'user_id' => $hiddenRequester->id,
        'status' => 'submitted',
        'submitted_at' => now()->subMinutes(14),
    ]);
    $hiddenCandidate = HrCandidate::factory()->create([
        'status' => 'offer_pending',
        'created_by' => $this->hr->id,
    ]);
    $hiddenApplication = HrApplication::factory()->create([
        'candidate_id' => $hiddenCandidate->id,
        'position_title' => 'Hidden Support Worker',
        'target_site_id' => $hiddenSite->id,
    ]);
    $hiddenOffer = HrOffer::query()->create([
        'application_id' => $hiddenApplication->id,
        'position_title' => 'Hidden Support Worker',
        'position_role' => 'support_worker',
        'proposed_start_date' => now()->addMonth()->toDateString(),
        'employment_type' => 'full_time',
        'primary_site_id' => $hiddenSite->id,
        'approval_status' => 'pending_approval',
        'approval_requested_at' => now()->subMinutes(13),
        'created_by' => $this->hr->id,
    ]);
    $hiddenRequisition = HrJobRequisition::query()->create([
        'title' => 'Hidden Team Leader',
        'slug' => 'hidden-team-leader-approval',
        'position_role' => 'team_lead',
        'employment_type' => 'full_time',
        'openings' => 1,
        'site_id' => $hiddenSite->id,
        'requires_approval' => true,
        'status' => 'pending_approval',
        'created_by' => $this->hr->id,
    ]);

    // Already-completed records must not surface in a pending inbox.
    HrExpenseClaim::factory()->create(['status' => 'approved']);

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
    $nativeKeys = $native->map(fn (array $item): string => $item['type'].':'.$item['id']);
    expect($nativeKeys)
        ->not->toContain(
            'leave:'.$hiddenLeave->id,
            'expense:'.$hiddenExpense->id,
            'offer:'.$hiddenOffer->id,
            'requisition:'.$hiddenRequisition->id,
        );
    expect($response->inertiaProps('instances.data'))->toBe([]);
});
