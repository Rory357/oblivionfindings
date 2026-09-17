<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetAdjustment;
use App\Domain\Governance\Models\BudgetLineItem;
use App\Domain\Governance\Models\GovernanceResolutionBinding;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\GovernanceNestedMutationService;
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
            ->assertInertia(fn ($page) => $page
                ->where('canEdit', false)
                ->where('canPropose', false)
                ->where('canApprove', false)
                ->where('canRequestChange', false)
                ->where('canDecideChanges', false)
                ->where('canRecordActuals', false)
                ->where('allocationOptions', null));
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
        ])->assertSessionHasErrors([
            'line_items.0.description' => 'Describe what this line pays for.',
            'line_items.0.budget_amount' => 'Enter the amount budgeted for this line.',
        ]);

        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_budget_already_approved_outside_the_system_needs_the_date_and_minutes_reference(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->post('/governance/budgets', [
            'fiscal_year' => '2026',
            'title' => 'Last year budget',
            'total_budget' => 5000,
            'board_approved' => true,
        ])->assertSessionHasErrors([
            'approved_on' => 'Enter the date the board approved this budget.',
            'approval_reference' => 'Enter the minutes reference for the meeting that approved this budget.',
        ]);
        $this->assertDatabaseCount('budgets', 0);

        $approvedOn = now()->subMonth()->toDateString();

        $this->actingAs($admin)->post('/governance/budgets', [
            'fiscal_year' => '2026',
            'title' => 'Last year budget',
            'total_budget' => 5000,
            'board_approved' => true,
            'approved_on' => $approvedOn,
            'approval_reference' => 'Board minutes 18 June, item 4',
        ])->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Budget recorded as approved by the board.');

        $budget = Budget::query()->where('title', 'Last year budget')->firstOrFail();
        $this->assertSame('approved', $budget->status);
        $this->assertSame('Board minutes 18 June, item 4', $budget->external_approval_reference);
        $this->assertNotNull($budget->approved_by_board_at);
        $this->assertNull($budget->approval_resolution_id);

        $this->actingAs($admin)->get("/governance/budgets/{$budget->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('approval.key', 'approved')
                ->where('approval.detail', fn ($detail) => str_contains($detail, 'Minutes reference: Board minutes 18 June, item 4.'))
                ->where('budget.financial_year_label', '2025/26'));
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

    /** An approved budget's figures change only through a budget change. */
    public function test_approved_budget_total_cannot_be_edited_directly(): void
    {
        $admin = $this->createAdminUser();
        $approved = $this->createBudget($admin, ['status' => 'approved', 'total_budget' => 100000]);

        $this->actingAs($admin)->put("/governance/budgets/{$approved->id}", [
            'total_budget' => 250000,
            'title' => 'Quietly bigger',
        ])->assertForbidden();

        $approved->refresh();
        $this->assertSame('100000.00', $approved->total_budget);
        $this->assertSame('Test Budget', $approved->title);

        $this->actingAs($admin)->get("/governance/budgets/{$approved->id}")
            ->assertInertia(fn ($page) => $page->where('canEdit', false));
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
        $this->assertSame('Approve the budget: FY Care Budget', $paper->title);
        $binding = $paper->authorityBindings()->firstOrFail();
        $this->assertSame(GovernanceResolutionBinding::SUBJECT_BUDGET, $binding->subject_type);
        $this->assertSame($budget->id, $binding->subject_id);
        $this->assertSame('v'.$budget->version_number, $binding->subject_revision);

        // Nothing to approve until the board has voted.
        $this->actingAs($admin)->get("/governance/budgets/{$budget->id}")
            ->assertInertia(fn ($page) => $page
                ->where('approval.key', 'drafted')
                ->where('canApprove', false));

        $paper->update(['status' => 'closed', 'outcome' => 'carried', 'closed_at' => now()]);

        $this->actingAs($admin)->get("/governance/budgets/{$budget->id}")
            ->assertInertia(fn ($page) => $page
                ->where('approval.key', 'passed')
                ->where('canApprove', true));

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

        $this->actingAs($admin)->get("/governance/budgets/{$budget->id}")
            ->assertInertia(fn ($page) => $page
                ->where('approval.key', 'stale')
                ->where('approval.stale', true)
                ->where('canApprove', false)
                ->where('canPropose', true)
                ->where('canReturnToDrafting', true));

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/approve")
            ->assertRedirect()
            ->assertSessionHasErrors('resolution_id')
            ->assertSessionHas('error', "The budget was edited after its resolution was prepared, so the board hasn't approved these figures. Put the updated budget to the board.");

        $this->assertSame('proposed', $budget->fresh()->status);
        $this->assertNull($budget->approvalResolution->authorityBindings()->firstOrFail()->consumed_at);
    }

    /** P0-12: an out-of-date budget can be sent to the board again, and then approved. */
    public function test_budget_edited_before_the_vote_is_sent_again_with_matching_figures(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['title' => 'Homes budget']);
        BudgetLineItem::create(['budget_id' => $budget->id, 'category' => 'staffing', 'description' => 'Rostered care', 'budget_amount' => 100000, 'forecast_amount' => 100000, 'actual_amount' => 0]);

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/propose")->assertSessionHasNoErrors();
        $paperId = $budget->fresh()->approval_resolution_id;

        // Sending again while the paper already matches is refused.
        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/propose")
            ->assertSessionHas('error', "This budget's resolution is already prepared and matches the budget. The secretary adds it to a meeting agenda.");

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/line-items", [
            'category' => 'fleet',
            'description' => 'Van lease',
            'budget_amount' => 20000,
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/propose")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'The updated budget has been sent to the board. Its resolution now matches these figures.');

        $budget->refresh();
        $paper = Resolution::query()->findOrFail($budget->approval_resolution_id);
        $this->assertSame($paperId, $paper->id, 'The draft resolution is updated rather than duplicated.');
        $this->assertEquals(120000, $paper->cost_impact['amount']);
        $this->assertSame(['state' => Budget::RESOLUTION_DRAFTED, 'stale' => false], $budget->resolutionState());

        $paper->update(['status' => 'closed', 'outcome' => 'carried', 'closed_at' => now()]);

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('approved', $budget->fresh()->status);
        $this->assertSame('120000.00', $budget->fresh()->total_budget);
    }

    public function test_budget_returns_to_drafting_only_when_the_board_cannot_approve_it_as_it_is(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin);
        BudgetLineItem::create(['budget_id' => $budget->id, 'category' => 'staffing', 'description' => 'Rostered care', 'budget_amount' => 100000, 'forecast_amount' => 100000, 'actual_amount' => 0]);

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/propose")->assertSessionHasNoErrors();
        $budget->refresh();

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/return-to-drafting")
            ->assertSessionHasErrors('budget')
            ->assertSessionHas('error', "This budget's resolution hasn't gone to a vote yet. Edit the budget directly, then send the updated budget to the board.");
        $this->assertSame('proposed', $budget->fresh()->status);

        $budget->approvalResolution->update(['status' => 'closed', 'outcome' => 'defeated', 'closed_at' => now()]);

        $this->actingAs($admin)->get("/governance/budgets/{$budget->id}")
            ->assertInertia(fn ($page) => $page
                ->where('approval.key', 'not_passed')
                ->where('canReturnToDrafting', true)
                ->where('canApprove', false));

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/return-to-drafting")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Budget returned to drafting. Update it, then send it to the board again.');

        $budget->refresh();
        $this->assertSame('drafting', $budget->status);
        $this->assertNull($budget->approval_resolution_id);

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/return-to-drafting")
            ->assertSessionHas('error', 'Only a budget that is waiting for the board can be returned to drafting.');
    }

    public function test_request_adjustment_carried_resolution_is_not_offered_or_authority(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000, 'status' => 'approved']);
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
        ])->assertSessionHasNoErrors();
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
                ->where('carriedResolutions.0.id', $bound->id)
                ->where('budget.changes.0.ready_resolution.id', $bound->id));
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

    /** P0-12: changes are for approved budgets; drafts are edited directly. */
    public function test_budget_changes_can_only_be_requested_on_an_approved_budget(): void
    {
        $admin = $this->createAdminUser();

        foreach (['drafting', 'proposed'] as $status) {
            $budget = $this->createBudget($admin, ['status' => $status, 'total_budget' => 100000]);
            $line = BudgetLineItem::create(['budget_id' => $budget->id, 'category' => 'operations', 'description' => 'Power', 'budget_amount' => 100000, 'forecast_amount' => 100000, 'actual_amount' => 0]);

            $this->actingAs($admin)->get("/governance/budgets/{$budget->id}")
                ->assertInertia(fn ($page) => $page->where('canRequestChange', false));

            $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjust", [
                'budget_line_item_id' => $line->id,
                'adjustment_type' => 'increase',
                'amount' => 100,
                'reason' => 'More power',
            ])->assertSessionHasErrors([
                'budget' => 'Budget changes are only for approved budgets. While a budget is a draft or waiting for the board, edit its lines instead.',
            ]);

            $this->assertSame(0, $budget->adjustments()->count());
        }
    }

    public function test_budget_change_needs_a_line_and_explains_the_board_threshold(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['status' => 'approved', 'total_budget' => 100000]);
        BudgetLineItem::create(['budget_id' => $budget->id, 'category' => 'operations', 'description' => 'Power', 'budget_amount' => 100000, 'forecast_amount' => 100000, 'actual_amount' => 0]);

        $this->actingAs($admin)->get("/governance/budgets/{$budget->id}")
            ->assertInertia(fn ($page) => $page
                ->where('canRequestChange', true)
                ->where('canDecideChanges', true)
                ->where('canRecordActuals', true)
                ->where('changeThreshold.amount', fn ($amount) => (float) $amount === 5000.0)
                ->where('changeThreshold.sentence', 'Changes of $5,000 or more (5% of this budget) need a board decision.'));

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjust", [
            'adjustment_type' => 'increase',
            'amount' => 100,
            'reason' => 'More power',
        ])->assertSessionHasErrors(['budget_line_item_id' => 'Choose which budget line this change applies to.']);

        $this->assertSame(0, $budget->adjustments()->count());
    }

    public function test_adjustment_below_threshold_is_approved_without_board_resolution(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000, 'status' => 'approved']);
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
        $budget = $this->createBudget($admin, ['total_budget' => 100000, 'status' => 'approved']);
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
        $approveResponse->assertSessionHasErrors([
            'approval_resolution_id' => "This change needs a board decision. Once a resolution approving it has passed, record the board's approval.",
        ]);

        $adjustment->refresh();
        $this->assertEquals('submitted', $adjustment->status);
        $this->assertNull($adjustment->approved_at);

        $lineItem->refresh();
        $this->assertEquals(20000.00, (float) $lineItem->budget_amount);
    }

    public function test_adjustment_above_threshold_fails_with_draft_or_defeated_resolution(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000, 'status' => 'approved']);
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
        ])->assertSessionHasNoErrors();
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
        $responseDraft->assertSessionHasErrors([
            'approval_resolution_id' => "This resolution can't be used yet — voting must be finished and the result recorded as passed.",
        ]);

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
        $budget = $this->createBudget($admin, ['total_budget' => 100000, 'status' => 'approved']);
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
        ])->assertSessionHasNoErrors();
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
        $response->assertSessionHasErrors([
            'approval_resolution_id' => 'The board approved $10,000.00 but this change is for $6,000.00.',
        ]);
    }

    public function test_adjustment_above_threshold_succeeds_with_carried_closed_resolution(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000, 'status' => 'approved']);
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
        ])->assertSessionHasNoErrors();
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
        $budget = $this->createBudget($admin, ['total_budget' => 100000, 'status' => 'approved']);
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
        ])->assertSessionHasNoErrors();
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
        ])->assertSessionHasNoErrors();
        $adj2 = BudgetAdjustment::where('budget_id', $budget->id)
            ->where('budget_line_item_id', $lineItem2->id)
            ->latest('id')
            ->first();

        // Attempting to approve adjustment 2 with same resolution should fail
        $resp2 = $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjustments/{$adj2->id}/approve", [
            'approval_resolution_id' => $resolution->id,
        ]);
        $resp2->assertSessionHasErrors([
            'approval_resolution_id' => 'This resolution has already been used to approve another budget change.',
        ]);
        $this->assertEquals('submitted', $adj2->refresh()->status);
    }

    public function test_approved_adjustment_replay_is_idempotent(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000, 'status' => 'approved']);
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
        ])->assertSessionHasNoErrors();
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

    public function test_reallocation_between_lines_is_not_offered(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000, 'status' => 'approved']);
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

        $response->assertSessionHasErrors(['adjustment_type' => GovernanceNestedMutationService::REALLOCATION_UNAVAILABLE]);
        $this->assertDatabaseMissing('budget_adjustments', [
            'budget_id' => $budget->id,
            'adjustment_type' => 'reallocate',
        ]);
    }

    public function test_decrease_cannot_take_a_line_below_zero(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000, 'status' => 'approved']);
        $line = BudgetLineItem::create(['budget_id' => $budget->id, 'category' => 'fleet', 'description' => 'Van lease', 'budget_amount' => 2000, 'forecast_amount' => 2000, 'actual_amount' => 0]);

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjust", [
            'budget_line_item_id' => $line->id,
            'adjustment_type' => 'decrease',
            'amount' => 2500,
            'reason' => 'Lease ended early',
        ])->assertSessionHasErrors(['amount' => "Van lease only has \$2,000 budgeted, so it can't go down by \$2,500."]);

        $this->assertSame(0, $budget->adjustments()->count());
    }

    public function test_declining_a_change_needs_a_reason_and_is_final(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000, 'status' => 'approved']);
        $line = BudgetLineItem::create(['budget_id' => $budget->id, 'category' => 'operations', 'description' => 'Power', 'budget_amount' => 100000, 'forecast_amount' => 100000, 'actual_amount' => 0]);

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjust", [
            'budget_line_item_id' => $line->id,
            'adjustment_type' => 'increase',
            'amount' => 200,
            'reason' => 'Winter heating',
        ])->assertSessionHasNoErrors();
        $adjustment = $budget->adjustments()->latest('id')->firstOrFail();

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjustments/{$adjustment->id}/reject", [
            'review_notes' => '',
        ])->assertSessionHasErrors(['review_notes' => "Say why you're declining this change."]);
        $this->assertSame('submitted', $adjustment->fresh()->status);

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjustments/{$adjustment->id}/reject", [
            'review_notes' => 'Covered by the existing heating line.',
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Budget change declined. The person who asked for it can see your reason.');

        $adjustment->refresh();
        $this->assertSame('rejected', $adjustment->status);
        $this->assertSame('Covered by the existing heating line.', $adjustment->review_notes);

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjustments/{$adjustment->id}/approve")
            ->assertSessionHasErrors(['adjustment' => 'This change has already been approved or declined.']);
        $this->assertSame('100000.00', $line->fresh()->budget_amount);

        $this->actingAs($admin)->get("/governance/budgets/{$budget->id}")
            ->assertInertia(fn ($page) => $page
                ->where('budget.changes.0.status', 'rejected')
                ->where('budget.changes.0.review_notes', 'Covered by the existing heating line.')
                ->where('budget.changes.0.line.description', 'Power'));
    }

    /** P0-12: figures can't move while the board is voting on the budget. */
    public function test_change_on_a_budget_waiting_for_the_board_cannot_be_approved(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100000, 'status' => 'proposed']);
        $line = BudgetLineItem::create(['budget_id' => $budget->id, 'category' => 'operations', 'description' => 'Power', 'budget_amount' => 100000, 'forecast_amount' => 100000, 'actual_amount' => 0]);

        // A change left over from before changes were limited to approved budgets.
        $adjustment = BudgetAdjustment::create([
            'budget_id' => $budget->id,
            'budget_line_item_id' => $line->id,
            'adjustment_type' => 'increase',
            'amount' => 100,
            'reason' => 'Legacy request',
            'proposed_by' => $admin->id,
            'proposed_at' => now(),
            'status' => 'submitted',
            'threshold_applies' => false,
        ]);

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/adjustments/{$adjustment->id}/approve")
            ->assertSessionHasErrors(['adjustment' => "This budget is waiting for the board's vote, so its figures can't change now. Decide this change after the vote."]);

        $this->assertSame('submitted', $adjustment->fresh()->status);
        $this->assertSame('100000.00', $line->fresh()->budget_amount);
    }

    public function test_only_budget_approvers_can_decide_changes(): void
    {
        $admin = $this->createAdminUser();
        $secretary = $this->createUserWithRole('board_secretary');
        $this->assertTrue($secretary->canDo('governance.budgets.create'));
        $this->assertFalse($secretary->canDo('governance.budgets.approve'));

        $budget = $this->createBudget($admin, ['total_budget' => 100000, 'status' => 'approved']);
        $line = BudgetLineItem::create(['budget_id' => $budget->id, 'category' => 'operations', 'description' => 'Power', 'budget_amount' => 100000, 'forecast_amount' => 100000, 'actual_amount' => 0]);

        $this->actingAs($secretary)->post("/governance/budgets/{$budget->id}/adjust", [
            'budget_line_item_id' => $line->id,
            'adjustment_type' => 'increase',
            'amount' => 100,
            'reason' => 'Winter heating',
        ])->assertSessionHasNoErrors();
        $adjustment = $budget->adjustments()->latest('id')->firstOrFail();

        $this->actingAs($secretary)->get("/governance/budgets/{$budget->id}")
            ->assertInertia(fn ($page) => $page
                ->where('canRequestChange', true)
                ->where('canDecideChanges', false)
                ->where('canApprove', false));

        $this->actingAs($secretary)->post("/governance/budgets/{$budget->id}/adjustments/{$adjustment->id}/approve")->assertForbidden();
        $this->actingAs($secretary)->post("/governance/budgets/{$budget->id}/adjustments/{$adjustment->id}/reject", [
            'review_notes' => 'No',
        ])->assertForbidden();

        $this->assertSame('submitted', $adjustment->fresh()->status);
    }

    public function test_recording_actual_spend_marks_the_budget_as_having_actuals(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 30000, 'status' => 'approved']);
        $power = BudgetLineItem::create(['budget_id' => $budget->id, 'category' => 'operations', 'description' => 'Power', 'budget_amount' => 10000, 'forecast_amount' => 10000, 'actual_amount' => 0]);
        $rent = BudgetLineItem::create(['budget_id' => $budget->id, 'category' => 'operations', 'description' => 'Rent', 'budget_amount' => 20000, 'forecast_amount' => 20000, 'actual_amount' => 0]);

        $this->actingAs($admin)->get("/governance/budgets/{$budget->id}")
            ->assertInertia(fn ($page) => $page
                ->where('budget.actuals_recorded', false)
                ->where('budget.actuals_recorded_at', null));

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/record-actuals", [
            'actuals' => [
                ['id' => $power->id, 'actual_amount' => 2500],
                ['id' => $rent->id, 'actual_amount' => 0],
            ],
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Actual spend recorded.');

        $this->assertSame('2500.00', $power->fresh()->actual_amount);
        $this->assertNotNull($budget->fresh()->actuals_recorded_at);

        $this->actingAs($admin)->get("/governance/budgets/{$budget->id}")
            ->assertInertia(fn ($page) => $page
                ->where('budget.actuals_recorded', true)
                ->whereNot('budget.actuals_recorded_at', null));

        $this->actingAs($admin)->post("/governance/budgets/{$budget->id}/record-actuals", [
            'actuals' => [
                ['id' => $power->id, 'actual_amount' => -5],
            ],
        ])->assertSessionHasErrors(['actuals.0.actual_amount' => "Amounts can't be negative."]);
    }

    public function test_index_totals_count_only_approved_budgets_for_the_current_financial_year(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));

        $admin = $this->createAdminUser();

        $current = $this->createBudget($admin, ['fiscal_year' => '2027', 'status' => 'approved', 'title' => 'Approved 2026/27', 'actuals_recorded_at' => now()]);
        BudgetLineItem::create(['budget_id' => $current->id, 'category' => 'operations', 'description' => 'Power', 'budget_amount' => 40000, 'forecast_amount' => 40000, 'actual_amount' => 1000]);

        $draft = $this->createBudget($admin, ['fiscal_year' => '2027', 'title' => 'Draft 2026/27']);
        BudgetLineItem::create(['budget_id' => $draft->id, 'category' => 'operations', 'description' => 'Power', 'budget_amount' => 90000, 'forecast_amount' => 90000, 'actual_amount' => 0]);

        $lastYear = $this->createBudget($admin, ['fiscal_year' => '2026', 'status' => 'approved', 'title' => 'Approved 2025/26']);
        BudgetLineItem::create(['budget_id' => $lastYear->id, 'category' => 'operations', 'description' => 'Power', 'budget_amount' => 70000, 'forecast_amount' => 70000, 'actual_amount' => 65000]);

        $this->createBudget($admin, ['fiscal_year' => '2027', 'status' => 'proposed', 'title' => 'Waiting 2026/27']);

        $this->actingAs($admin)->get('/governance/budgets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.financial_year', '2026/27')
                ->where('summary.total', 4)
                ->where('summary.approved_this_year', 1)
                ->where('summary.waiting', 1)
                ->where('summary.drafts', 1)
                ->where('summary.budgeted_this_year', fn ($total) => (float) $total === 40000.0)
                ->where('summary.spent_this_year', fn ($total) => (float) $total === 1000.0)
                ->where('summary.actuals_recorded_this_year', true)
                ->where('budgets', fn ($budgets) => collect($budgets)->firstWhere('id', $lastYear->id)['financial_year_label'] === '2025/26'));
    }
}
