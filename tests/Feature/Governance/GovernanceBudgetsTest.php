<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\Budget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceBudgetsTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

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
            'status' => 'approved',
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
}
