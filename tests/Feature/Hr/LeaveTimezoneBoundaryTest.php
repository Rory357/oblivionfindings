<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\LeaveService;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Eligibility\Rules\AvailabilityRule;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['app.worker_timezone' => 'Pacific/Auckland']);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->site = Site::factory()->create(['name' => 'Leave timezone Site']);
    ensureCanonicalHrStaffProfile($this->hr, $this->site);

    HrEmployeeProfile::factory()->create([
        'user_id' => $this->staff->id,
        'employee_number' => 'LEAVE-TZ-001',
        'work_email' => "leave-tz-{$this->staff->id}@example.test",
        'position_role' => 'support_worker',
        'primary_site_id' => $this->site->id,
        'hours_per_week' => 40,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
});

test('leave dates are stored as worker local day bounds in UTC for roster blocking', function () {
    $leave = app(LeaveService::class)->submitRequest($this->staff, [
        'leave_type' => 'annual',
        'starts_at' => '2026-06-15',
        'ends_at' => '2026-06-15',
        'hours_requested' => 8,
        'reason' => 'Family appointment.',
        'created_by' => $this->staff->id,
    ]);

    $approved = app(LeaveService::class)->approveRequest($leave, $this->hr);

    $rawLeave = DB::table('hr_leave_requests')->where('id', $approved->id)->first();

    expect(substr((string) $rawLeave->starts_at, 0, 19))->toBe('2026-06-14 12:00:00')
        ->and(substr((string) $rawLeave->ends_at, 0, 19))->toBe('2026-06-15 11:59:59');

    $insideLeave = Shift::factory()->create([
        'user_id' => $this->staff->id,
        'starts_at' => '2026-06-14 21:00:00',
        'ends_at' => '2026-06-15 05:00:00',
        'created_by' => $this->hr->id,
    ]);

    $beforeLeave = Shift::factory()->create([
        'user_id' => $this->staff->id,
        'starts_at' => '2026-06-13 21:00:00',
        'ends_at' => '2026-06-14 05:00:00',
        'created_by' => $this->hr->id,
    ]);

    $rule = app(AvailabilityRule::class);
    $leaveResult = fn (Shift $shift) => collect($rule->evaluateAll($shift, $this->staff))
        ->firstWhere('rule', 'availability_leave');

    expect($leaveResult($insideLeave))->toMatchArray([
        'rule' => 'availability_leave',
        'passed' => false,
        'severity' => 'block',
    ]);

    expect($leaveResult($beforeLeave))->toMatchArray([
        'rule' => 'availability_leave',
        'passed' => true,
    ]);
});
