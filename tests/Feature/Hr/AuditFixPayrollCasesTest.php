<?php

use App\Domain\Hr\Models\HrAsset;
use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrGoalCycle;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPayrollRunItem;
use App\Domain\Hr\Notifications\AssetAssignedNotification;
use App\Domain\Hr\Services\AssetService;
use App\Domain\Hr\Services\PayrollExportService;
use App\Domain\Shifts\Timesheets\TimesheetApprovalService;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->site = Site::factory()->create([
        'name' => 'HR audit case Site',
    ]);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    $supportRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);
    }

    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
    ]);

    $this->staffProfile = HrEmployeeProfile::query()->create([
        'user_id' => $this->staff->id,
        'employee_number' => 'EMP80001',
        'work_email' => "worker-{$this->staff->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'hourly_rate' => '30.00',
        'primary_site_id' => $this->site->id,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
});

function makeApprovedTimesheet(User $staff, User $approver, ?Client $client = null): Timesheet
{
    $client ??= Client::factory()->create([
        'site_id' => $staff->hrEmployeeProfile?->primary_site_id,
    ]);

    return Timesheet::query()->create([
        'user_id' => $staff->id,
        'client_id' => $client->id,
        'site_id' => $client->site_id,
        'work_date' => now()->subDay()->toDateString(),
        'starts_at' => now()->subDay()->setTime(9, 0),
        'ends_at' => now()->subDay()->setTime(17, 0),
        'break_minutes' => 0,
        'public_holiday' => false,
        'status' => 'approved',
        'submitted_at' => now()->subDay(),
        'approved_at' => now(),
        'approved_by' => $approver->id,
        'created_by' => $staff->id,
    ]);
}

/* ---------------------------------------------------------------------- */
/*  1. Approved leave lands in payroll run items */
/* ---------------------------------------------------------------------- */

test('approved paid leave lands in payroll run items and gross pay, unpaid leave is excluded', function () {
    makeApprovedTimesheet($this->staff, $this->hr);

    // 8h of PAID annual leave inside the period.
    HrLeaveRequest::query()->create([
        'user_id' => $this->staff->id,
        'leave_type' => 'annual',
        'starts_at' => now()->subDays(2)->startOfDay(),
        'ends_at' => now()->subDays(2)->startOfDay(),
        'hours_requested' => 8,
        'status' => 'approved',
        'created_by' => $this->staff->id,
    ]);

    // Unpaid leave in the same period must NOT be counted.
    HrLeaveRequest::query()->create([
        'user_id' => $this->staff->id,
        'leave_type' => 'unpaid',
        'starts_at' => now()->subDays(3)->startOfDay(),
        'ends_at' => now()->subDays(3)->startOfDay(),
        'hours_requested' => 8,
        'status' => 'approved',
        'created_by' => $this->staff->id,
    ]);

    $service = app(PayrollExportService::class);
    $items = $service->getRunItems(now()->subWeek()->startOfDay(), now()->endOfDay());

    expect($items)->toHaveKey($this->staff->id);
    expect((float) $items[$this->staff->id]['leave_hours'])->toBe(8.0);
    expect((float) $items[$this->staff->id]['leave_pay'])->toBe(240.0); // 8h × $30
    // 8h worked × $30 = 240 + 240 leave.
    expect((float) $items[$this->staff->id]['gross_pay'])->toBe(480.0);

    // Persisted onto the run item row too.
    $run = $service->createRun(now()->subWeek()->startOfDay(), now()->endOfDay(), $this->hr->id);
    $item = HrPayrollRunItem::query()
        ->where('payroll_run_id', $run->id)
        ->where('user_id', $this->staff->id)
        ->firstOrFail();

    expect((float) $item->leave_hours)->toBe(8.0);
    expect((float) $item->leave_pay)->toBe(240.0);
    expect((float) $item->gross_pay)->toBe(480.0);
});

test('an employee with approved leave but no timesheets still gets a run item', function () {
    $leaveOnly = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);

    HrEmployeeProfile::query()->create([
        'user_id' => $leaveOnly->id,
        'employee_number' => 'EMP80002',
        'work_email' => "worker-{$leaveOnly->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'hourly_rate' => '25.00',
        'primary_site_id' => $this->site->id,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    HrLeaveRequest::query()->create([
        'user_id' => $leaveOnly->id,
        'leave_type' => 'sick',
        'starts_at' => now()->subDays(2)->startOfDay(),
        'ends_at' => now()->subDays(2)->startOfDay(),
        'hours_requested' => 4,
        'status' => 'approved',
        'created_by' => $leaveOnly->id,
    ]);

    $items = app(PayrollExportService::class)
        ->getRunItems(now()->subWeek()->startOfDay(), now()->endOfDay());

    expect($items)->toHaveKey($leaveOnly->id);
    expect($items[$leaveOnly->id]['timesheet_ids'])->toBe([]);
    expect((float) $items[$leaveOnly->id]['leave_hours'])->toBe(4.0);
    expect((float) $items[$leaveOnly->id]['leave_pay'])->toBe(100.0); // 4h × $25
    expect((float) $items[$leaveOnly->id]['gross_pay'])->toBe(100.0);
});

/* ---------------------------------------------------------------------- */
/*  2. Payroll lock blocks status downgrades */
/* ---------------------------------------------------------------------- */

test('a submitted timesheet inside a locked payroll run cannot be returned for changes or approved', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);
    makeApprovedTimesheet($this->staff, $this->hr, $client);

    $service = app(PayrollExportService::class);
    $run = $service->createRun(now()->subWeek()->startOfDay(), now()->endOfDay(), $this->hr->id);
    $service->lockRun($run->fresh(), $this->hr->id);

    // A second timesheet, submitted before the lock, with a work_date inside
    // the now-locked period.
    $submitted = Timesheet::query()->create([
        'user_id' => $this->staff->id,
        'client_id' => $client->id,
        'work_date' => now()->subDays(2)->toDateString(),
        'starts_at' => now()->subDays(2)->setTime(9, 0),
        'ends_at' => now()->subDays(2)->setTime(17, 0),
        'break_minutes' => 0,
        'public_holiday' => false,
        'status' => 'submitted',
        'submitted_at' => now()->subDays(2),
        'created_by' => $this->staff->id,
    ]);

    $approvals = app(TimesheetApprovalService::class);

    // Downgrade (return for changes) must be blocked by the payroll lock.
    expect(fn () => $approvals->returnForChanges($submitted, $this->hr, 'please fix'))
        ->toThrow(ValidationException::class);
    expect($submitted->fresh()->status)->toBe('submitted');

    // Rejection is a status change inside the locked period too.
    expect(fn () => $approvals->reject($submitted, $this->hr, 'no'))
        ->toThrow(ValidationException::class);
    expect($submitted->fresh()->status)->toBe('submitted');

    // Approving into a frozen run would create hours the run never pays.
    expect(fn () => $approvals->approve($submitted, $this->hr))
        ->toThrow(ValidationException::class);
    expect($submitted->fresh()->status)->toBe('submitted');
});

/* ---------------------------------------------------------------------- */
/*  3. Confidential case access_list enforcement */
/* ---------------------------------------------------------------------- */

function grantCaseView(User $user): void
{
    $perm = Permission::query()->where('key', 'hr.cases.view')->firstOrFail();
    $user->permissionOverrides()->syncWithoutDetaching([$perm->id => ['allowed' => true]]);
}

test('a confidential case is hidden from a viewer not on the access list and visible to an access list member', function () {
    $outsider = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $member = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    grantCaseView($outsider);
    grantCaseView($member);
    foreach ([$outsider, $member] as $viewer) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $this->site->id,
            'is_active' => true,
        ]);
    }

    $case = HrCase::query()->create([
        'case_number' => 'HR-'.Str::padLeft((string) random_int(1, 99999), 5, '0'),
        'user_id' => $this->staff->id,
        'case_type' => 'grievance',
        'severity' => 'high',
        'status' => 'open',
        'title' => 'Confidential grievance',
        'description' => 'Sensitive detail',
        'reported_by' => $this->hr->id,
        'assigned_to' => $this->hr->id,
        'opened_at' => now(),
        'is_confidential' => true,
        'access_list' => [$member->id],
        'created_by' => $this->hr->id,
    ]);

    // Confidential direct objects are concealed as not found, including from
    // otherwise valid current staff at the same Site.
    $this->actingAs($outsider)->get("/hr/cases/{$case->id}")->assertNotFound();
    $this->actingAs($outsider)->get('/hr/cases')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/cases/index')
            ->where('cases.data', fn ($rows) => collect($rows)->pluck('id')->doesntContain($case->id)));

    // Access-list member: visible.
    $this->actingAs($member)->get("/hr/cases/{$case->id}")->assertOk();
    $this->actingAs($member)->get('/hr/cases')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/cases/index')
            ->where('cases.data', fn ($rows) => collect($rows)->pluck('id')->contains($case->id)));

    // Case manager (hr role has hr.cases.manage): always visible.
    $this->actingAs($this->hr)->get("/hr/cases/{$case->id}")->assertOk();
});

/* ---------------------------------------------------------------------- */
/*  4. Asset assignment notifies the employee */
/* ---------------------------------------------------------------------- */

test('assigning an asset notifies the assignee', function () {
    Notification::fake();

    $asset = HrAsset::query()->create([
        'asset_tag' => 'AST-9001',
        'name' => 'Issued access card',
        'category' => 'card',
        'status' => 'available',
        'qr_token' => (string) Str::uuid(),
    ]);

    app(AssetService::class)->assignAsset($asset, $this->staffProfile, [
        'assigned_by' => $this->hr->id,
        'condition_on_assign' => 'good',
        'due_at' => now()->addMonth()->toDateString(),
    ]);

    Notification::assertSentTo($this->staff, AssetAssignedNotification::class);
});

/* ---------------------------------------------------------------------- */
/*  5. Cycle close auto-completes 100% objectives */
/* ---------------------------------------------------------------------- */

test('closing a goal cycle auto-completes objectives at 100 percent and leaves the rest for rollover', function () {
    $cycle = HrGoalCycle::query()->create([
        'name' => 'Q3 2026',
        'type' => 'quarter',
        'status' => 'active',
        'starts_at' => now()->startOfQuarter()->toDateString(),
        'ends_at' => now()->endOfQuarter()->toDateString(),
    ]);

    $done = HrGoal::query()->create([
        'user_id' => $this->staff->id,
        'title' => 'Finished objective',
        'goal_type' => 'objective',
        'cycle_id' => $cycle->id,
        'progress_percentage' => 100,
        'status' => 'active',
        'start_date' => now()->startOfQuarter()->toDateString(),
        'due_date' => now()->endOfQuarter()->toDateString(),
        'created_by' => $this->hr->id,
    ]);

    $open = HrGoal::query()->create([
        'user_id' => $this->staff->id,
        'title' => 'Half-done objective',
        'goal_type' => 'objective',
        'cycle_id' => $cycle->id,
        'progress_percentage' => 50,
        'status' => 'active',
        'start_date' => now()->startOfQuarter()->toDateString(),
        'due_date' => now()->endOfQuarter()->toDateString(),
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/goals/cycles/{$cycle->id}/close")
        ->assertRedirect();

    expect($cycle->fresh()->status)->toBe('closed');

    $done->refresh();
    expect($done->status)->toBe('completed');
    expect($done->completed_at)->not->toBeNull();

    $open->refresh();
    expect($open->status)->toBe('active');
    expect($open->completed_at)->toBeNull();
});
