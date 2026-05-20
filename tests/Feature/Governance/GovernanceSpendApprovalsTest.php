<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\GovernanceSetting;
use App\Domain\Governance\Models\SpendApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceSpendApprovalsTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_admin_can_view_index(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/governance/spend-approvals');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/SpendApprovals/Index')
            ->has('approvals')
            ->has('summary')
            ->has('thresholds')
            ->where('thresholds.capex', fn ($v) => (float) $v === 5000.0)
        );
    }

    public function test_admin_can_create_spend_approval(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/governance/spend-approvals', [
            'title' => 'Vehicle replacement — Auckland',
            'description' => 'Replace decommissioned support van',
            'category' => 'capex',
            'amount' => 28500,
            'currency' => 'NZD',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('spend_approvals', [
            'title' => 'Vehicle replacement — Auckland',
            'category' => 'capex',
            'amount' => 28500.00,
            'status' => SpendApproval::STATUS_DRAFT,
            'requires_board' => 1,
            'requested_by' => $admin->id,
        ]);
    }

    public function test_small_capex_does_not_require_board(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->post('/governance/spend-approvals', [
            'title' => 'Office printer',
            'category' => 'capex',
            'amount' => 800,
            'currency' => 'NZD',
        ])->assertRedirect();

        $approval = SpendApproval::latest('id')->first();
        $this->assertNotNull($approval);
        $this->assertFalse((bool) $approval->requires_board);
    }

    public function test_draft_can_be_submitted(): void
    {
        $admin = $this->createAdminUser();
        $approval = SpendApproval::create([
            'reference' => 'SA-2026-9999',
            'title' => 'Test', 'category' => 'opex',
            'amount' => 12000, 'currency' => 'NZD',
            'status' => SpendApproval::STATUS_DRAFT,
            'requested_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post("/governance/spend-approvals/{$approval->id}/submit")
            ->assertRedirect();

        $approval->refresh();
        $this->assertSame(SpendApproval::STATUS_SUBMITTED, $approval->status);
        $this->assertNotNull($approval->submitted_at);
    }

    public function test_submitted_can_be_approved(): void
    {
        $admin = $this->createAdminUser();
        $approval = SpendApproval::create([
            'reference' => 'SA-2026-0001',
            'title' => 'Test', 'category' => 'opex',
            'amount' => 12000, 'currency' => 'NZD',
            'status' => SpendApproval::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'requested_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post("/governance/spend-approvals/{$approval->id}/approve", [
            'decision_notes' => 'Approved on the spot — within delegated authority.',
        ])->assertRedirect();

        $approval->refresh();
        $this->assertSame(SpendApproval::STATUS_APPROVED, $approval->status);
        $this->assertSame($admin->id, $approval->decided_by);
        $this->assertNotNull($approval->decided_at);
    }

    public function test_submitted_can_be_rejected_with_reason(): void
    {
        $admin = $this->createAdminUser();
        $approval = SpendApproval::create([
            'reference' => 'SA-2026-0002',
            'title' => 'Test', 'category' => 'opex',
            'amount' => 12000, 'currency' => 'NZD',
            'status' => SpendApproval::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'requested_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post("/governance/spend-approvals/{$approval->id}/reject", [
            'decision_notes' => 'Rejected — funding not yet secured.',
        ])->assertRedirect();

        $approval->refresh();
        $this->assertSame(SpendApproval::STATUS_REJECTED, $approval->status);
    }

    public function test_reject_requires_reason(): void
    {
        $admin = $this->createAdminUser();
        $approval = SpendApproval::create([
            'reference' => 'SA-2026-0003',
            'title' => 'Test', 'category' => 'opex',
            'amount' => 12000, 'currency' => 'NZD',
            'status' => SpendApproval::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'requested_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post("/governance/spend-approvals/{$approval->id}/reject", [
            'decision_notes' => '',
        ])->assertSessionHasErrors('decision_notes');
    }

    public function test_cannot_approve_a_draft(): void
    {
        $admin = $this->createAdminUser();
        $approval = SpendApproval::create([
            'reference' => 'SA-2026-0004',
            'title' => 'Test', 'category' => 'opex',
            'amount' => 12000, 'currency' => 'NZD',
            'status' => SpendApproval::STATUS_DRAFT,
            'requested_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post("/governance/spend-approvals/{$approval->id}/approve")
            ->assertStatus(422);

        $approval->refresh();
        $this->assertSame(SpendApproval::STATUS_DRAFT, $approval->status);
    }

    public function test_cannot_edit_a_submitted_approval(): void
    {
        $admin = $this->createAdminUser();
        $approval = SpendApproval::create([
            'reference' => 'SA-2026-0005',
            'title' => 'Original', 'category' => 'opex',
            'amount' => 12000, 'currency' => 'NZD',
            'status' => SpendApproval::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'requested_by' => $admin->id,
        ]);

        $this->actingAs($admin)->put("/governance/spend-approvals/{$approval->id}", [
            'title' => 'Edited',
            'category' => 'opex',
            'amount' => 99999,
        ])->assertStatus(422);

        $approval->refresh();
        $this->assertSame('Original', $approval->title);
    }

    public function test_threshold_overrides_via_governance_setting(): void
    {
        $admin = $this->createAdminUser();
        GovernanceSetting::set('spend_approval.threshold.capex', 1000, GovernanceSetting::CATEGORY_SPEND_APPROVAL);

        $this->actingAs($admin)->post('/governance/spend-approvals', [
            'title' => 'Just over the new threshold',
            'category' => 'capex',
            'amount' => 1200,
            'currency' => 'NZD',
        ])->assertRedirect();

        $approval = SpendApproval::latest('id')->first();
        $this->assertTrue((bool) $approval->requires_board, 'Should flag board because 1200 ≥ 1000 setting');
    }

    public function test_audit_log_records_spend_approval_actions(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin)->post('/governance/spend-approvals', [
            'title' => 'Audit-logged spend',
            'category' => 'opex',
            'amount' => 200,
            'currency' => 'NZD',
        ])->assertRedirect();

        $this->assertDatabaseHas('governance_audit_log', [
            'action' => 'spend_approval.created',
            'resource_type' => 'SpendApproval',
            'user_id' => $admin->id,
        ]);
    }
}
