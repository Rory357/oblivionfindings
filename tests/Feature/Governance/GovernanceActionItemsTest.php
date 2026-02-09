<?php

namespace Tests\Feature\Governance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceActionItemsTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_admin_can_view_action_items(): void
    {
        $admin = $this->createAdminUser();
        $action = $this->createActionItem($admin, $admin);

        $indexResponse = $this->actingAs($admin)->get('/governance/actions');
        $indexResponse->assertOk();
        $indexResponse->assertInertia(fn ($page) => $page
            ->component('Governance/Actions/Index')
        );

        $showResponse = $this->actingAs($admin)->get("/governance/actions/{$action->id}");
        $showResponse->assertOk();
        $showResponse->assertInertia(fn ($page) => $page
            ->component('Governance/Actions/Show')
        );
    }

    public function test_admin_can_complete_action_item(): void
    {
        $admin = $this->createAdminUser();
        $action = $this->createActionItem($admin, $admin);

        $response = $this->actingAs($admin)->post("/governance/actions/{$action->id}/complete", [
            'completion_notes' => 'Done',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('action_items', [
            'id' => $action->id,
            'status' => 'complete',
            'completed_by' => $admin->id,
        ]);
    }
}
