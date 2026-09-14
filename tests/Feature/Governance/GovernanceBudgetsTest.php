<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetAdjustment;
use App\Domain\Governance\Models\BudgetLineItem;
use App\Domain\Governance\Models\GovernanceResolutionBinding;
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

    public function test_create_and_edit_deep_links_open_the_wizard_dialogs(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin);

        $this->actingAs($admin)->get('/governance/budgets/create')
            ->assertRedirect('/governance/budgets?create=1');

        $this->actingAs($admin)->get('/governance/budgets?create=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Budgets/Index')
                ->where('canCreate', true)
                ->where('formOptions.categories.operations', 'Operations')
                ->has('formOptions.categories', 7));

        $this->actingAs($admin)->get("/governance/budgets/{$budget->id}/edit")
            ->assertRedirect("/governance/budgets/{$budget->id}?edit=1");

        $this->actingAs($admin)->get("/governance/budgets/{$budget->id}?edit=1")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Budgets/Show')
                ->where('canEdit', true)
                ->has('categories', 7));
    }

    public function test_view_only_user_gets_no_budget_wizard_options(): void
    {
        $viewer = $this->createUserWithRole('board_observer');
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin);

        $this->assertTrue($viewer->canDo('governance.budgets.view'));
        $this->assertFalse($viewer->canDo('governance.budgets.create'));

        $this->actingAs($viewer)->get('/governance/budgets/create')->assertForbidden();
        $this->actingAs($viewer)->get("/governance/budgets/{$budget->id}/edit")->assertForbidden();

        $this->actingAs($viewer)->get('/governance/budgets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canCreate', false)
                ->where('formOptions', null));

        $this->actingAs($viewer)->get("/governance/budgets/{$budget->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canEdit', false));
    }

    public function test_wizard_creates_budget_with_nested_line_items(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->post('/governance/budgets', [
            'fiscal_year' => '2027',
            'title' => 'FY2027 Operating Budget',
            'description' => 'Operating envelope for supported living homes.',
            'total_budget' => 999,
            'board_approved' => false,
            'line_items' => [
                ['category' => 'staffing', 'description' => 'Support worker wages', 'account_code' => '6100', 'budget_amount' => 120000, 'forecast_amount' => null, 'notes' => ''],
                ['category' => 'fleet', 'description' => 'Van leases', 'account_code' => '', 'budget_amount' => 30000.5, 'forecast_amount' => 28000, 'notes' => 'Two vans'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $budget = Budget::query()->where('title', 'FY2027 Operating Budget')->firstOrFail();
        $this->assertSame('drafting', $budget->status);
        $this->assertSame('Operating envelope for supported living homes.', $budget->description);
        $this->assertSame('150000.50', $budget->total_budget, 'The envelope is recalculated to the sum of the lines.');
        $this->assertSame(2, $budget->lineItems()->count());
        $this->assertDatabaseHas('budget_line_items', [
            'budget_id' => $budget->id,
            'description' => 'Support worker wages',
            'account_code' => '6100',
            'forecast_amount' => 120000,
        ]);
        $this->assertDatabaseHas('budget_line_items', [
            'budget_id' => $budget->id,
            'description' => 'Van leases',
            'forecast_amount' => 28000,
            'notes' => 'Two vans',
        ]);
    }

    public function test_wizard_rejects_incomplete_line_items(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->post('/governance/budgets', [
            'fiscal_year' => '2027',
            'total_budget' => 1000,
            'line_items' => [
                ['category' => 'staffing', 'description' => '', 'budget_amount' => ''],
            ],
        ])->assertSessionHasErrors(['line_items.0.description', 'line_items.0.budget_amount']);

        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_edit_wizard_syncs_line_items_and_keeps_actuals(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['title' => 'Draft budget', 'total_budget' => 30000]);
        $kept = BudgetLineItem::create(['budget_id' => $budget->id, 'category' => 'operations', 'description' => 'Power', 'budget_amount' => 10000, 'forecast_amount' => 10000, 'actual_amount' => 2500]);
        $removed = BudgetLineItem::create(['budget_id' => $budget->id, 'category' => 'admin', 'description' => 'Stationery', 'budget_amount' => 20000, 'forecast_amount' => 20000, 'actual_amount' => 0]);

        $this->actingAs($admin)->put("/governance/budgets/{$budget->id}", [
            'fiscal_year' => (string) $budget->fiscal_year,
            'title' => 'Revised draft budget',
            'description' => null,
            'total_budget' => 30000,
            'line_items' => [
                ['id' => $kept->id, 'category' => 'operations', 'description' => 'Power and gas', 'account_code' => null, 'budget_amount' => 12000, 'forecast_amount' => 12000, 'notes' => null],
                ['category' => 'capital', 'description' => 'Accessible bathroom', 'account_code' => '1510', 'budget_amount' => 45000, 'forecast_amount' => null, 'notes' => null],
            ],
        ])->assertRedirect("/governance/budgets/{$budget->id}")->assertSessionHasNoErrors();

        $budget->refresh();
        $this->assertSame('Revised draft budget', $budget->title);
        $this->assertSame('57000.00', $budget->total_budget);
        $this->assertDatabaseMissing('budget_line_items', ['id' => $removed->id]);
        $this->assertSame('Power and gas', $kept->fresh()->description);
        $this->assertSame('2500.00', $kept->fresh()->actual_amount, 'Recorded actual spend is not touched by the wizard.');
        $this->assertDatabaseHas('budget_line_items', ['budget_id' => $budget->id, 'description' => 'Accessible bathroom']);
    }

    public function test_edit_wizard_cannot_touch_lines_of_another_budget_or_an_approved_budget(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin);
        $other = $this->createBudget($admin, ['title' => 'Other budget']);
        $foreign = BudgetLineItem::create(['budget_id' => $other->id, 'category' => 'operations', 'description' => 'Foreign line', 'budget_amount' => 5000, 'forecast_amount' => 5000, 'actual_amount' => 0]);

        $this->actingAs($admin)->put("/governance/budgets/{$budget->id}", [
            'line_items' => [
                ['id' => $foreign->id, 'category' => 'operations', 'description' => 'Hijacked', 'budget_amount' => 1],
            ],
        ])->assertNotFound();
        $this->assertSame('Foreign line', $foreign->fresh()->description);

        $approved = $this->createBudget($admin, ['status' => 'approved', 'title' => 'Approved budget']);
        $this->actingAs($admin)->put("/governance/budgets/{$approved->id}", [
            'line_items' => [
                ['category' => 'operations', 'description' => 'Sneaked in', 'budget_amount' => 1],
            ],
        ])->assertForbidden();
        $this->assertSame(0, $approved->lineItems()->count());
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

    public function test_proposed_budget_is_approved_only_by_its_bound_decision_paper(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['title' => 'FY Care Budget']);
        BudgetLineItem::create(['budget_id' => $budget->id, 'category' => 'staffing', 'description' => 'Rostered care', 'budget_amount' => 100000, 'forecast_amount' => 100000, 'actual_amount' => 0]);

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/propose")->assertRedirect();

        $budget->refresh();
        $paper = $budget->approvalResolution;
        $this->assertNotNull($paper);
        $binding = $paper->authorityBindings()->firstOrFail();
        $this->assertSame(GovernanceResolutionBinding::SUBJECT_BUDGET, $binding->subject_type);
        $this->assertSame($budget->id, $binding->subject_id);
        $this->assertSame('v'.$budget->version_number, $binding->subject_revision);

        $paper->update(['status' => 'closed', 'outcome' => 'carried', 'closed_at' => now()]);

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/approve")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $budget->fresh()->status);
        $this->assertNotNull($binding->fresh()->consumed_at);
    }

    public function test_budget_changed_after_proposal_is_not_approved_by_the_carried_paper(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['title' => 'FY Care Budget']);
        BudgetLineItem::create(['budget_id' => $budget->id, 'category' => 'staffing', 'description' => 'Rostered care', 'budget_amount' => 100000, 'forecast_amount' => 100000, 'actual_amount' => 0]);

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/propose")->assertRedirect();
        $budget->refresh();

        // A proposed budget is still editable; the board voted on the old lines.
        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/line-items", [
            'category' => 'capital',
            'description' => 'New vehicle',
            'budget_amount' => 60000,
        ])->assertSessionHasNoErrors();

        $budget->approvalResolution->update(['status' => 'closed', 'outcome' => 'carried', 'closed_at' => now()]);

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/approve")
            ->assertRedirect()
            ->assertSessionHasErrors('resolution_id')
            ->assertSessionHas('error');

        $this->assertSame('proposed', $budget->fresh()->status);
        $this->assertNull($budget->approvalResolution->authorityBindings()->firstOrFail()->consumed_at);
    }

    public function test_request_adjustment_carried_resolution_is_not_offered_or_authority(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000]);
        $lineItem = BudgetLineItem::create(['budget_id' => $budget->id, 'category' => 'capital', 'description' => 'Hoists', 'budget_amount' => 100000, 'forecast_amount' => 100000, 'actual_amount' => 0]);
        $unbound = $this->createResolution($admin, ['status' => 'closed', 'outcome' => 'carried', 'cost_impact' => ['amount' => 9000]]);

        $this->actingAs($admin)->get("/governance/budgets/{$budget->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('carriedResolutions', 0));

        // A resolution attached while requesting can never grant approval.
        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjust", [
            'budget_line_item_id' => $lineItem->id,
            'adjustment_type' => 'increase',
            'amount' => 9000,
            'reason' => 'Ceiling hoists',
            'approval_resolution_id' => $unbound->id,
        ]);
        $adjustment = BudgetAdjustment::where('budget_id', $budget->id)->latest('id')->firstOrFail();

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjustments/{$adjustment->id}/approve")
            ->assertSessionHasErrors('approval_resolution_id');
        $this->assertSame('submitted', $adjustment->fresh()->status);

        // Only the bound, unused paper is offered for the adjustment row.
        $bound = $this->createBoundCarriedResolution($admin, GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT, $adjustment->id, [
            'cost_impact' => ['amount' => 9000],
        ]);
        $this->actingAs($admin)->get("/governance/budgets/{$budget->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('carriedResolutions', 1)
                ->where('carriedResolutions.0.id', $bound->id));
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

        // Authority is the explicit binding to this exact adjustment revision.
        $resolution = $this->createBoundCarriedResolution($admin, GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT, $adjustment->id, [
            'title' => 'Switchgear Capex Resolution',
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

        // The resolution is legitimately bound to adjustment 1 only.
        $resolution = $this->createBoundCarriedResolution($admin, GovernanceResolutionBinding::SUBJECT_BUDGET_ADJUSTMENT, $adj1->id, [
            'title' => 'Single Use Resolution',
            'cost_impact' => ['amount' => 6000, 'currency' => 'NZD', 'funding_source' => 'Capital', 'is_none' => false],
        ]);

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
