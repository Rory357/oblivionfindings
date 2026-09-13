<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetAdjustment;
use App\Domain\Governance\Models\BudgetLineItem;
use App\Domain\Governance\Models\Resolution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceBudgetsTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_admin_can_view_create_page(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/governance/budgets/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Budgets/Create')
        );
    }

    public function test_admin_can_create_budget(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/governance/budgets', [
            'fiscal_year' => now()->year,
            'title' => 'FY Budget',
            'total_budget' => 150000,
            'board_approved' => false,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('budgets', [
            'title' => 'FY Budget',
            'status' => 'drafting',
            'created_by' => $admin->id,
        ]);
    }

    public function test_admin_can_propose_and_update_budget(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin);

        $proposeResponse = $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/propose");
        $proposeResponse->assertRedirect();

        $budget->refresh();
        $this->assertEquals('proposed', $budget->status);
        $this->assertEquals($admin->id, $budget->proposed_by);
        $this->assertNotNull($budget->proposed_at);

        $updateResponse = $this->actingAs($admin)->put("/governance/budgets/{$budget->id}", [
            'total_budget' => 200000,
            'status' => 'approved',
        ]);
        $updateResponse->assertRedirect();

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'total_budget' => 200000,
            'status' => 'proposed',
        ]);
    }

    public function test_admin_can_view_budget_show(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin);

        $response = $this->actingAs($admin)->get("/governance/budgets/{$budget->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Budgets/Show')
        );
    }

    public function test_adjustment_below_threshold_is_approved_without_board_resolution(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000]);
        BudgetLineItem::create([
            'budget_id' => $budget->id,
            'category' => 'admin',
            'description' => 'Baseline Operations',
            'budget_amount' => 90000,
            'forecast_amount' => 90000,
            'actual_amount' => 0,
        ]);
        $lineItem = BudgetLineItem::create([
            'budget_id' => $budget->id,
            'category' => 'operations',
            'description' => 'Vehicle Maintenance',
            'budget_amount' => 10000,
            'forecast_amount' => 10000,
            'actual_amount' => 0,
        ]);

        // 1000 is 1% of 100,000 (< 5% threshold)
        $requestResponse = $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjust", [
            'budget_line_item_id' => $lineItem->id,
            'adjustment_type' => 'increase',
            'amount' => 1000,
            'reason' => 'Routine parts cost increase',
        ]);
        $requestResponse->assertRedirect();
        $requestResponse->assertSessionHasNoErrors();

        $adjustment = BudgetAdjustment::where('budget_id', $budget->id)->latest('id')->first();
        $this->assertNotNull($adjustment);
        $this->assertFalse((bool) $adjustment->threshold_applies);
        $this->assertEquals('submitted', $adjustment->status);

        // Approve without a resolution
        $approveResponse = $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjustments/{$adjustment->id}/approve");
        $approveResponse->assertRedirect();
        $approveResponse->assertSessionHasNoErrors();

        $adjustment->refresh();
        $this->assertEquals('approved', $adjustment->status);
        $this->assertEquals($admin->id, $adjustment->approved_by);
        $this->assertNotNull($adjustment->approved_at);

        $lineItem->refresh();
        $this->assertEquals(11000.00, (float) $lineItem->budget_amount);

        $budget->refresh();
        $this->assertEquals(101000.00, (float) $budget->total_budget);
    }

    public function test_adjustment_at_or_above_threshold_requires_board_resolution_to_approve(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000]);
        $lineItem = BudgetLineItem::create([
            'budget_id' => $budget->id,
            'category' => 'capital',
            'description' => 'Server Equipment',
            'budget_amount' => 20000,
            'forecast_amount' => 20000,
            'actual_amount' => 0,
        ]);

        // 6,000 is 6% of 100,000 (>= 5% threshold)
        $requestResponse = $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjust", [
            'budget_line_item_id' => $lineItem->id,
            'adjustment_type' => 'increase',
            'amount' => 6000,
            'reason' => 'Infrastructure upgrade',
        ]);
        $requestResponse->assertRedirect();
        $requestResponse->assertSessionHasNoErrors();

        $adjustment = BudgetAdjustment::where('budget_id', $budget->id)->latest('id')->first();
        $this->assertNotNull($adjustment);
        $this->assertTrue((bool) $adjustment->threshold_applies);

        // Attempting to approve without a board resolution fails
        $approveResponse = $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjustments/{$adjustment->id}/approve");
        $approveResponse->assertSessionHasErrors(['approval_resolution_id']);

        $adjustment->refresh();
        $this->assertEquals('submitted', $adjustment->status);
        $this->assertNull($adjustment->approved_at);

        $lineItem->refresh();
        $this->assertEquals(20000.00, (float) $lineItem->budget_amount);
    }

    public function test_adjustment_above_threshold_fails_with_draft_or_defeated_resolution(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000]);
        $lineItem = BudgetLineItem::create([
            'budget_id' => $budget->id,
            'category' => 'capital',
            'description' => 'Storage Hardware',
            'budget_amount' => 20000,
            'forecast_amount' => 20000,
            'actual_amount' => 0,
        ]);

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjust", [
            'budget_line_item_id' => $lineItem->id,
            'adjustment_type' => 'increase',
            'amount' => 6000,
            'reason' => 'Hardware refresh',
        ]);
        $adjustment = BudgetAdjustment::where('budget_id', $budget->id)->latest('id')->first();

        // 1. Draft resolution
        $draftResolution = $this->createResolution($admin, [
            'title' => 'Draft Resolution',
            'status' => 'draft',
            'outcome' => 'carried',
            'cost_impact' => ['amount' => 6000, 'currency' => 'NZD', 'funding_source' => 'Capital', 'is_none' => false],
        ]);

        $responseDraft = $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjustments/{$adjustment->id}/approve", [
            'approval_resolution_id' => $draftResolution->id,
        ]);
        $responseDraft->assertSessionHasErrors(['approval_resolution_id']);

        // 2. Defeated resolution
        $defeatedResolution = $this->createResolution($admin, [
            'title' => 'Defeated Resolution',
            'status' => 'closed',
            'outcome' => 'defeated',
            'cost_impact' => ['amount' => 6000, 'currency' => 'NZD', 'funding_source' => 'Capital', 'is_none' => false],
        ]);

        $responseDefeated = $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjustments/{$adjustment->id}/approve", [
            'approval_resolution_id' => $defeatedResolution->id,
        ]);
        $responseDefeated->assertSessionHasErrors(['approval_resolution_id']);
    }

    public function test_adjustment_above_threshold_fails_when_resolution_amount_mismatches(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000]);
        $lineItem = BudgetLineItem::create([
            'budget_id' => $budget->id,
            'category' => 'capital',
            'description' => 'Security Gate',
            'budget_amount' => 15000,
            'forecast_amount' => 15000,
            'actual_amount' => 0,
        ]);

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjust", [
            'budget_line_item_id' => $lineItem->id,
            'adjustment_type' => 'increase',
            'amount' => 6000,
            'reason' => 'Perimeter gate upgrade',
        ]);
        $adjustment = BudgetAdjustment::where('budget_id', $budget->id)->latest('id')->first();

        // Resolution specifies cost_impact amount of 10,000, mismatching adjustment amount of 6,000
        $resolution = $this->createResolution($admin, [
            'title' => 'Perimeter Gate Board Motion',
            'status' => 'closed',
            'outcome' => 'carried',
            'cost_impact' => ['amount' => 10000, 'currency' => 'NZD', 'funding_source' => 'Capital', 'is_none' => false],
        ]);

        $response = $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjustments/{$adjustment->id}/approve", [
            'approval_resolution_id' => $resolution->id,
        ]);
        $response->assertSessionHasErrors(['approval_resolution_id']);
    }

    public function test_adjustment_above_threshold_succeeds_with_carried_closed_resolution(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000]);
        BudgetLineItem::create([
            'budget_id' => $budget->id,
            'category' => 'operations',
            'description' => 'Baseline Operations',
            'budget_amount' => 80000,
            'forecast_amount' => 80000,
            'actual_amount' => 0,
        ]);
        $lineItem = BudgetLineItem::create([
            'budget_id' => $budget->id,
            'category' => 'capital',
            'description' => 'Network Switchgear',
            'budget_amount' => 20000,
            'forecast_amount' => 20000,
            'actual_amount' => 0,
        ]);

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjust", [
            'budget_line_item_id' => $lineItem->id,
            'adjustment_type' => 'increase',
            'amount' => 7500,
            'reason' => 'High speed switchgear replacement',
        ]);
        $adjustment = BudgetAdjustment::where('budget_id', $budget->id)->latest('id')->first();

        $resolution = $this->createResolution($admin, [
            'title' => 'Switchgear Capex Resolution',
            'status' => 'closed',
            'outcome' => 'carried',
            'cost_impact' => ['amount' => 7500, 'currency' => 'NZD', 'funding_source' => 'Capital', 'is_none' => false],
        ]);

        $response = $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjustments/{$adjustment->id}/approve", [
            'approval_resolution_id' => $resolution->id,
        ]);
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $adjustment->refresh();
        $this->assertEquals('approved', $adjustment->status);
        $this->assertEquals($resolution->id, $adjustment->approval_resolution_id);
        $this->assertEquals($admin->id, $adjustment->approved_by);

        $lineItem->refresh();
        $this->assertEquals(27500.00, (float) $lineItem->budget_amount);

        $budget->refresh();
        $this->assertEquals(107500.00, (float) $budget->total_budget);
    }

    public function test_carried_resolution_cannot_be_reused_across_multiple_adjustments(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000]);
        BudgetLineItem::create([
            'budget_id' => $budget->id,
            'category' => 'operations',
            'description' => 'Baseline Operations',
            'budget_amount' => 60000,
            'forecast_amount' => 60000,
            'actual_amount' => 0,
        ]);
        $lineItem1 = BudgetLineItem::create([
            'budget_id' => $budget->id,
            'category' => 'capital',
            'description' => 'First Asset',
            'budget_amount' => 20000,
            'forecast_amount' => 20000,
            'actual_amount' => 0,
        ]);
        $lineItem2 = BudgetLineItem::create([
            'budget_id' => $budget->id,
            'category' => 'capital',
            'description' => 'Second Asset',
            'budget_amount' => 20000,
            'forecast_amount' => 20000,
            'actual_amount' => 0,
        ]);

        $resolution = $this->createResolution($admin, [
            'title' => 'Single Use Resolution',
            'status' => 'closed',
            'outcome' => 'carried',
            'cost_impact' => ['amount' => 6000, 'currency' => 'NZD', 'funding_source' => 'Capital', 'is_none' => false],
        ]);

        // Adjustment 1
        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjust", [
            'budget_line_item_id' => $lineItem1->id,
            'adjustment_type' => 'increase',
            'amount' => 6000,
            'reason' => 'First adjustment',
        ]);
        $adj1 = BudgetAdjustment::where('budget_id', $budget->id)
            ->where('budget_line_item_id', $lineItem1->id)
            ->latest('id')
            ->first();

        // Approve adjustment 1 with resolution
        $resp1 = $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjustments/{$adj1->id}/approve", [
            'approval_resolution_id' => $resolution->id,
        ]);
        $resp1->assertSessionHasNoErrors();
        $this->assertEquals('approved', $adj1->refresh()->status);

        // Adjustment 2
        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjust", [
            'budget_line_item_id' => $lineItem2->id,
            'adjustment_type' => 'increase',
            'amount' => 6000,
            'reason' => 'Second adjustment trying to reuse same resolution',
        ]);
        $adj2 = BudgetAdjustment::where('budget_id', $budget->id)
            ->where('budget_line_item_id', $lineItem2->id)
            ->latest('id')
            ->first();

        // Attempting to approve adjustment 2 with same resolution should fail
        $resp2 = $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjustments/{$adj2->id}/approve", [
            'approval_resolution_id' => $resolution->id,
        ]);
        $resp2->assertSessionHasErrors(['approval_resolution_id']);
        $this->assertEquals('submitted', $adj2->refresh()->status);
    }

    public function test_approved_adjustment_replay_is_idempotent(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000]);
        BudgetLineItem::create([
            'budget_id' => $budget->id,
            'category' => 'admin',
            'description' => 'Baseline Operations',
            'budget_amount' => 95000,
            'forecast_amount' => 95000,
            'actual_amount' => 0,
        ]);
        $lineItem = BudgetLineItem::create([
            'budget_id' => $budget->id,
            'category' => 'operations',
            'description' => 'Office Supplies',
            'budget_amount' => 5000,
            'forecast_amount' => 5000,
            'actual_amount' => 0,
        ]);

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjust", [
            'budget_line_item_id' => $lineItem->id,
            'adjustment_type' => 'increase',
            'amount' => 500,
            'reason' => 'Paper and consumables',
        ]);
        $adjustment = BudgetAdjustment::where('budget_id', $budget->id)->latest('id')->first();

        // First approval
        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjustments/{$adjustment->id}/approve")
            ->assertSessionHasNoErrors();

        $lineItem->refresh();
        $this->assertEquals(5500.00, (float) $lineItem->budget_amount);
        $budget->refresh();
        $this->assertEquals(100500.00, (float) $budget->total_budget);

        // Second approval attempt (replay)
        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjustments/{$adjustment->id}/approve")
            ->assertSessionHasNoErrors();

        $lineItem->refresh();
        $this->assertEquals(5500.00, (float) $lineItem->budget_amount);
        $budget->refresh();
        $this->assertEquals(100500.00, (float) $budget->total_budget);
    }

    public function test_one_sided_reallocation_adjustment_is_rejected(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000]);
        $lineItem = BudgetLineItem::create([
            'budget_id' => $budget->id,
            'category' => 'operations',
            'description' => 'Field Gear',
            'budget_amount' => 10000,
            'forecast_amount' => 10000,
            'actual_amount' => 0,
        ]);

        $response = $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjust", [
            'budget_line_item_id' => $lineItem->id,
            'adjustment_type' => 'reallocate',
            'amount' => 1000,
            'reason' => 'Move funds without a paired destination',
        ]);

        $response->assertSessionHasErrors(['adjustment_type']);
        $this->assertDatabaseMissing('budget_adjustments', [
            'budget_id' => $budget->id,
            'adjustment_type' => 'reallocate',
        ]);
    }
}
