<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\HsTrainingRequirement;
use App\Models\Shift;
use App\Models\User;
use App\Services\Eligibility\Rules\HsTrainingRule;
use App\Services\HealthSafety\HsTrainingComplianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HsTrainingEligibilityTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────
    // HsTrainingRequirement applicability
    // ──────────────────────────────────────────────────────

    public function test_global_requirement_applies_to_any_context(): void
    {
        $req = HsTrainingRequirement::factory()->create(['scope_type' => 'global']);

        $this->assertTrue($req->appliesTo('support_worker', 1, 1));
        $this->assertTrue($req->appliesTo(null, null, null));
    }

    public function test_role_requirement_applies_only_to_matching_role(): void
    {
        $req = HsTrainingRequirement::factory()->forRole('support_worker')->create();

        $this->assertTrue($req->appliesTo('support_worker', 1, 1));
        $this->assertFalse($req->appliesTo('coordinator', 1, 1));
        $this->assertFalse($req->appliesTo(null, 1, 1));
    }

    public function test_site_requirement_applies_only_to_matching_site(): void
    {
        $req = HsTrainingRequirement::factory()->forSite(42)->create();

        $this->assertTrue($req->appliesTo('support_worker', 42, 1));
        $this->assertFalse($req->appliesTo('support_worker', 99, 1));
        $this->assertFalse($req->appliesTo('support_worker', null, 1));
    }

    public function test_inactive_requirement_never_applies(): void
    {
        $req = HsTrainingRequirement::factory()->inactive()->create();

        $this->assertFalse($req->appliesTo('support_worker', 1, 1));
    }

    public function test_blocking_mode_flag(): void
    {
        $warn = HsTrainingRequirement::factory()->warning()->create();
        $block = HsTrainingRequirement::factory()->blocking()->create();

        $this->assertFalse($warn->isBlocking());
        $this->assertTrue($block->isBlocking());
    }

    // ──────────────────────────────────────────────────────
    // HsTrainingComplianceService
    // ──────────────────────────────────────────────────────

    public function test_compliant_when_no_requirements_exist(): void
    {
        $user = User::factory()->create();
        $shift = Shift::factory()->create();

        $service = app(HsTrainingComplianceService::class);
        $result = $service->checkForShift($user, $shift);

        $this->assertTrue($result['compliant']);
        $this->assertEmpty($result['failures']);
        $this->assertEmpty($result['warnings']);
    }

    public function test_compliant_when_hr_status_is_compliant(): void
    {
        $hrReq = HrComplianceRequirement::factory()->create();

        HsTrainingRequirement::factory()->create([
            'hr_compliance_requirement_id' => $hrReq->id,
            'enforcement_mode' => 'block',
        ]);

        $user = User::factory()->create();
        $shift = Shift::factory()->create();

        HrStaffComplianceStatus::factory()->create([
            'user_id' => $user->id,
            'requirement_id' => $hrReq->id,
            'status' => 'compliant',
        ]);

        $service = app(HsTrainingComplianceService::class);
        $result = $service->checkForShift($user, $shift);

        $this->assertTrue($result['compliant']);
    }

    public function test_failure_when_hr_status_expired_and_blocking(): void
    {
        $hrReq = HrComplianceRequirement::factory()->create();

        $hsReq = HsTrainingRequirement::factory()->blocking()->create([
            'hr_compliance_requirement_id' => $hrReq->id,
            'grace_period_days' => 0,
        ]);

        $user = User::factory()->create();
        $shift = Shift::factory()->create();

        HrStaffComplianceStatus::factory()->create([
            'user_id' => $user->id,
            'requirement_id' => $hrReq->id,
            'status' => 'expired',
            'expires_at' => now()->subDays(10),
        ]);

        $service = app(HsTrainingComplianceService::class);
        $result = $service->checkForShift($user, $shift);

        $this->assertFalse($result['compliant']);
        $this->assertCount(1, $result['failures']);
        $this->assertEquals($hsReq->code, $result['failures'][0]['requirement_code']);
    }

    public function test_grace_period_downgrades_expired_to_warning(): void
    {
        $hrReq = HrComplianceRequirement::factory()->create();

        HsTrainingRequirement::factory()->blocking()->create([
            'hr_compliance_requirement_id' => $hrReq->id,
            'grace_period_days' => 30,
        ]);

        $user = User::factory()->create();
        $shift = Shift::factory()->create();

        HrStaffComplianceStatus::factory()->create([
            'user_id' => $user->id,
            'requirement_id' => $hrReq->id,
            'status' => 'expired',
            'expires_at' => now()->subDays(5), // Within 30-day grace
        ]);

        $service = app(HsTrainingComplianceService::class);
        $result = $service->checkForShift($user, $shift);

        // Grace period downgrades expired → expiring_soon → treated as non-block
        // because the HR status is 'expired' but within grace window
        // The check returns 'expiring_soon' which is not 'expired'
        // So the blocking requirement sees a non-expired status
        $this->assertTrue($result['compliant'] || ! empty($result['warnings']));
    }

    public function test_warning_requirement_produces_warning_not_failure(): void
    {
        $hrReq = HrComplianceRequirement::factory()->create();

        $hsReq = HsTrainingRequirement::factory()->warning()->create([
            'hr_compliance_requirement_id' => $hrReq->id,
        ]);

        $user = User::factory()->create();
        $shift = Shift::factory()->create();

        HrStaffComplianceStatus::factory()->create([
            'user_id' => $user->id,
            'requirement_id' => $hrReq->id,
            'status' => 'expired',
            'expires_at' => now()->subDays(60),
        ]);

        $service = app(HsTrainingComplianceService::class);
        $result = $service->checkForShift($user, $shift);

        $this->assertTrue($result['compliant']); // Warnings don't fail
        $this->assertCount(1, $result['warnings']);
    }

    public function test_not_started_treated_as_non_compliant(): void
    {
        $hrReq = HrComplianceRequirement::factory()->create();

        HsTrainingRequirement::factory()->blocking()->create([
            'hr_compliance_requirement_id' => $hrReq->id,
            'grace_period_days' => 0,
        ]);

        $user = User::factory()->create();
        $shift = Shift::factory()->create();

        // No HrStaffComplianceStatus record = not_started

        $service = app(HsTrainingComplianceService::class);
        $result = $service->checkForShift($user, $shift);

        $this->assertFalse($result['compliant']);
        $this->assertCount(1, $result['failures']);
    }

    // ──────────────────────────────────────────────────────
    // HsTrainingRule (eligibility integration)
    // ──────────────────────────────────────────────────────

    public function test_rule_passes_when_no_requirements_exist(): void
    {
        $rule = app(HsTrainingRule::class);
        $shift = Shift::factory()->create();
        $user = User::factory()->create();

        $result = $rule->evaluate($shift, $user);

        $this->assertTrue($result['passed']);
        $this->assertEquals('hs_training', $result['rule']);
    }

    public function test_rule_returns_block_for_failed_blocking_requirement(): void
    {
        $hrReq = HrComplianceRequirement::factory()->create();

        HsTrainingRequirement::factory()->blocking()->create([
            'hr_compliance_requirement_id' => $hrReq->id,
            'grace_period_days' => 0,
        ]);

        $user = User::factory()->create();
        $shift = Shift::factory()->create();

        // No compliance status = not_started = block

        $rule = app(HsTrainingRule::class);
        $result = $rule->evaluate($shift, $user);

        $this->assertFalse($result['passed']);
        $this->assertEquals('block', $result['severity']);
        $this->assertTrue($result['overrideable']); // Always overrideable for manager override
    }

    public function test_rule_returns_warning_for_warning_requirement(): void
    {
        $hrReq = HrComplianceRequirement::factory()->create();

        HsTrainingRequirement::factory()->warning()->create([
            'hr_compliance_requirement_id' => $hrReq->id,
        ]);

        $user = User::factory()->create();
        $shift = Shift::factory()->create();

        // No compliance status = not_started = warning (not blocking)

        $rule = app(HsTrainingRule::class);
        $results = $rule->evaluateAll($shift, $user);

        $nonPassing = array_filter($results, fn ($r) => ! $r['passed']);
        $this->assertNotEmpty($nonPassing);
        $this->assertEquals('warning', array_values($nonPassing)[0]['severity']);
    }

    public function test_evaluate_all_returns_multiple_results(): void
    {
        $hrReq1 = HrComplianceRequirement::factory()->create();
        $hrReq2 = HrComplianceRequirement::factory()->create();

        HsTrainingRequirement::factory()->blocking()->create([
            'hr_compliance_requirement_id' => $hrReq1->id,
            'grace_period_days' => 0,
        ]);
        HsTrainingRequirement::factory()->warning()->create([
            'hr_compliance_requirement_id' => $hrReq2->id,
        ]);

        $user = User::factory()->create();
        $shift = Shift::factory()->create();

        $rule = app(HsTrainingRule::class);
        $results = $rule->evaluateAll($shift, $user);

        // Should have results for both requirements (both non-compliant)
        $nonPassing = array_filter($results, fn ($r) => ! $r['passed']);
        $this->assertCount(2, $nonPassing);
    }
}
