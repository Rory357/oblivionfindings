<?php

namespace Tests\Unit\Roadmap;

use App\Domain\Governance\Models\Resolution;
use App\Domain\Roadmap\Models\DelegationOfAuthorityRule;
use App\Domain\Roadmap\Services\RoadmapDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RoadmapTestHelpers;
use Tests\TestCase;

class RoadmapDecisionServiceTest extends TestCase
{
    use RefreshDatabase;
    use RoadmapTestHelpers;

    protected RoadmapDecisionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoadmapModule();
        $this->service = app(RoadmapDecisionService::class);

        DelegationOfAuthorityRule::query()->delete();

        DelegationOfAuthorityRule::create([
            'scope' => 'initiative_budget',
            'amount_min' => 0,
            'amount_max' => 10000,
            'required_approver_role' => 'provider_manager',
            'is_active' => true,
        ]);

        DelegationOfAuthorityRule::create([
            'scope' => 'initiative_budget',
            'amount_min' => 10000.01,
            'amount_max' => null,
            'required_approver_role' => 'board_chair',
            'is_active' => true,
        ]);
    }

    public function test_resolve_applicable_rule_selects_matching_amount_band(): void
    {
        $small = $this->service->resolveApplicableRule(null, 'initiative_budget', 5000);
        $large = $this->service->resolveApplicableRule(null, 'initiative_budget', 25000);

        $this->assertNotNull($small);
        $this->assertNotNull($large);
        $this->assertSame('provider_manager', $small->required_approver_role);
        $this->assertSame('board_chair', $large->required_approver_role);
    }

    public function test_ensure_decision_request_returns_null_when_initiative_has_no_cost(): void
    {
        $admin = $this->createAdminUser();
        $initiative = $this->createInitiative($admin, [
            'cost_estimate_low' => null,
            'cost_estimate_high' => null,
        ]);

        $result = $this->service->ensureDecisionRequestForInitiative($initiative, $admin->id);

        $this->assertNull($result);
        $this->assertDatabaseCount('roadmap_decision_requests', 0);
    }

    public function test_ensure_decision_request_creates_pending_request_and_board_resolution(): void
    {
        $admin = $this->createAdminUser();
        $initiative = $this->createInitiative($admin, [
            'cost_estimate_low' => 25000,
            'cost_estimate_high' => 60000,
            'priority_band' => 'high',
        ]);

        $request = $this->service->ensureDecisionRequestForInitiative($initiative, $admin->id);

        $this->assertNotNull($request);
        $this->assertSame('pending', $request->status);
        $this->assertNotNull($request->governance_resolution_id);

        $resolution = Resolution::query()->findOrFail($request->governance_resolution_id);
        $this->assertSame('draft', $resolution->status);
        $this->assertSame('budget_approval', $resolution->decision_type);
        $this->assertIsArray($resolution->options);
        $this->assertCount(3, $resolution->options);
    }

    public function test_ensure_decision_request_updates_existing_pending_request(): void
    {
        $admin = $this->createAdminUser();
        $initiative = $this->createInitiative($admin, [
            'cost_estimate_low' => 12000,
            'cost_estimate_high' => 14000,
            'priority_band' => 'medium',
        ]);

        $first = $this->service->ensureDecisionRequestForInitiative($initiative, $admin->id);
        $initiative->update(['cost_estimate_high' => 28000]);
        $second = $this->service->ensureDecisionRequestForInitiative($initiative->fresh(), $admin->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('28000.00', (string) $second->fresh()->amount);
        $this->assertDatabaseCount('roadmap_decision_requests', 1);
    }

    public function test_resolve_request_approved_opens_associated_resolution(): void
    {
        $admin = $this->createAdminUser();
        $initiative = $this->createInitiative($admin, [
            'cost_estimate_high' => 50000,
            'priority_band' => 'high',
        ]);

        $request = $this->service->ensureDecisionRequestForInitiative($initiative, $admin->id);
        $this->assertNotNull($request?->governance_resolution_id);

        $this->service->resolveRequest($request->fresh(), 'approved', $admin->id, 'Approved for this quarter');

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertNotNull($request->resolved_at);

        $resolution = Resolution::query()->findOrFail($request->governance_resolution_id);
        $this->assertSame('open', $resolution->status);
        $this->assertNotNull($resolution->deadline);
    }

    public function test_resolve_request_rejected_cancels_associated_resolution(): void
    {
        $admin = $this->createAdminUser();
        $initiative = $this->createInitiative($admin, [
            'cost_estimate_high' => 50000,
            'priority_band' => 'high',
        ]);

        $request = $this->service->ensureDecisionRequestForInitiative($initiative, $admin->id);

        $this->service->resolveRequest($request->fresh(), 'rejected', $admin->id, 'Budget unavailable');

        $request->refresh();
        $this->assertSame('rejected', $request->status);

        $resolution = Resolution::query()->findOrFail($request->governance_resolution_id);
        $this->assertSame('cancelled', $resolution->status);
        $this->assertSame('Budget unavailable', $resolution->outcome_notes);
    }
}
