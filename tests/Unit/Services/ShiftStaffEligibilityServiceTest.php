<?php

namespace Tests\Unit\Services;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\StaffTimeOff;
use App\Models\User;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftStaffEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ShiftStaffEligibilityService $service;

    protected Site $site;

    protected Client $client;

    protected ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ShiftStaffEligibilityService::class);
        $this->site = Site::factory()->create();
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
        ]);
        $this->serviceContext = ServiceContext::factory()->create();
    }

    public function test_eligible_staff_member_passes(): void
    {
        $staff = User::factory()->create();
        $shift = $this->makeShift([
            'coverage_roles' => ['caregiver'],
        ]);

        $result = $this->service->evaluate($shift, $staff);

        $this->assertTrue($result['is_eligible']);
        $this->assertSame([], $result['blocked_reasons']);
        $this->assertSame(['caregiver'], collect($result['matched_roles'])->pluck('key')->all());
    }

    public function test_role_mismatch_fails(): void
    {
        $staff = User::factory()->create();
        $shift = $this->makeShift([
            'coverage_roles' => ['driver'],
        ]);

        $result = $this->service->evaluate($shift, $staff);

        $this->assertFalse($result['is_eligible']);
        $this->assertSame(['Driver'], $result['missing_roles']);
    }

    public function test_compliance_requirement_failure_blocks_assignment(): void
    {
        $staff = User::factory()->create();
        $requirement = HrComplianceRequirement::query()->create([
            'tenant_id' => 1,
            'code' => 'med_training',
            'name' => 'Medication training',
            'category' => 'training',
            'check_type' => 'manual',
            'renewal_reminder_days' => 30,
            'hard_stop' => true,
            'is_active' => true,
        ]);

        HrStaffComplianceStatus::query()->create([
            'tenant_id' => 1,
            'user_id' => $staff->id,
            'requirement_id' => $requirement->id,
            'status' => 'expired',
            'last_checked_at' => now(),
            'next_check_at' => now()->addDay(),
        ]);

        $result = $this->service->evaluate($this->makeShift(), $staff);

        $this->assertFalse($result['is_eligible']);
        $this->assertTrue($result['has_compliance_block']);
    }

    public function test_time_off_conflict_and_existing_shift_conflict_both_fail(): void
    {
        $staff = User::factory()->create();
        $shift = $this->makeShift();

        StaffTimeOff::query()->create([
            'user_id' => $staff->id,
            'starts_at' => $shift->starts_at->copy()->subHour(),
            'ends_at' => $shift->ends_at->copy()->subHour(),
            'type' => 'leave',
            'label' => 'Annual leave',
            'created_by' => $staff->id,
        ]);

        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => $shift->starts_at->copy()->addHour(),
            'ends_at' => $shift->ends_at->copy()->addHour(),
            'status' => 'scheduled',
            'created_by' => $staff->id,
        ]);

        $result = $this->service->evaluate($shift, $staff);

        $this->assertFalse($result['is_eligible']);
        $this->assertTrue($result['has_time_off']);
        $this->assertTrue($result['has_staff_conflict']);
    }

    public function test_overfill_coverage_rule_mismatch_blocks_assignment(): void
    {
        $staff = User::factory()->create();
        $alreadyAssigned = User::factory()->create();
        $shift = $this->makeShift([
            'user_id' => null,
        ]);

        SiteCoverageRequirement::query()->create([
            'organization_id' => $staff->organization_id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'name' => 'Morning coverage',
            'coverage_type' => 'custom',
            'day_of_week' => strtolower($shift->starts_at->format('D')),
            'starts_time' => $shift->starts_at->format('H:i'),
            'ends_time' => $shift->ends_at->format('H:i'),
            'minimum_staff' => 1,
            'allow_overstaffing' => false,
            'is_active' => true,
        ]);

        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $alreadyAssigned->id,
            'starts_at' => $shift->starts_at->copy(),
            'ends_at' => $shift->ends_at->copy(),
            'status' => 'scheduled',
            'created_by' => $alreadyAssigned->id,
        ]);

        $result = $this->service->evaluate($shift->fresh(), $staff);

        $this->assertFalse($result['is_eligible']);
        $this->assertTrue($result['would_overfill_coverage']);
    }

    public function test_staff_can_become_ineligible_after_assignment_conditions_change(): void
    {
        $staff = User::factory()->create();
        $shift = $this->makeShift([
            'coverage_roles' => ['driver'],
        ]);

        HrDriverEligibility::query()->create([
            'tenant_id' => 1,
            'user_id' => $staff->id,
            'can_drive_clients' => true,
            'can_drive_clients_approved_at' => now(),
            'status' => 'eligible',
        ]);

        $initial = $this->service->evaluate($shift, $staff);
        $this->assertTrue($initial['is_eligible']);

        $staff->hrDriverEligibility()->update([
            'status' => 'suspended',
            'can_drive_clients' => false,
        ]);

        $rechecked = $this->service->evaluate($shift->fresh(), $staff->fresh());

        $this->assertFalse($rechecked['is_eligible']);
        $this->assertSame(['Driver'], $rechecked['missing_roles']);
    }

    protected function makeShift(array $attributes = []): Shift
    {
        return Shift::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => User::factory(),
            'starts_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(17, 0),
            'status' => 'scheduled',
            'coverage_roles' => [],
            'created_by' => User::factory(),
        ], $attributes));
    }
}
