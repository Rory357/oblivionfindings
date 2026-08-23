<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\GovernanceSetting;
use App\Domain\Governance\Models\SpendApproval;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceSpendApprovalsTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
        $this->site = Site::factory()->create();
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
            'site_id' => $this->site->id,
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
            'site_id' => $this->site->id,
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
            'site_id' => $this->site->id,
        ]);

        $this->actingAs($admin)->post("/governance/spend-approvals/{$approval->id}/submit", [
            'expected_version' => 1,
        ])
            ->assertRedirect();

        $approval->refresh();
        $this->assertSame(SpendApproval::STATUS_SUBMITTED, $approval->status);
        $this->assertNotNull($approval->submitted_at);
    }

    public function test_submitted_can_be_approved(): void
    {
        $requester = $this->createAdminUser();
        $decider = $this->createAdminUser();
        $approval = $this->submittedApproval($requester, 'SA-2026-0001');

        $this->actingAs($decider)->post("/governance/spend-approvals/{$approval->id}/approve", [
            ...$this->decisionPayload($approval),
            'decision_notes' => 'Approved on the spot — within delegated authority.',
        ])->assertRedirect();

        $approval->refresh();
        $this->assertSame(SpendApproval::STATUS_APPROVED, $approval->status);
        $this->assertSame($decider->id, $approval->decided_by);
        $this->assertNotNull($approval->decided_at);
    }

    public function test_submitted_can_be_rejected_with_reason(): void
    {
        $requester = $this->createAdminUser();
        $decider = $this->createAdminUser();
        $approval = $this->submittedApproval($requester, 'SA-2026-0002');

        $this->actingAs($decider)->post("/governance/spend-approvals/{$approval->id}/reject", [
            ...$this->decisionPayload($approval),
            'decision_notes' => 'Rejected — funding not yet secured.',
        ])->assertRedirect();

        $approval->refresh();
        $this->assertSame(SpendApproval::STATUS_REJECTED, $approval->status);
    }

    public function test_reject_requires_reason(): void
    {
        $requester = $this->createAdminUser();
        $decider = $this->createAdminUser();
        $approval = $this->submittedApproval($requester, 'SA-2026-0003');

        $this->actingAs($decider)->post("/governance/spend-approvals/{$approval->id}/reject", [
            ...$this->decisionPayload($approval),
            'decision_notes' => '',
        ])->assertSessionHasErrors('decision_notes');
    }

    public function test_approve_requires_reason(): void
    {
        $requester = $this->createAdminUser();
        $decider = $this->createAdminUser();
        $approval = $this->submittedApproval($requester, 'SA-2026-0006');

        $this->actingAs($decider)->post("/governance/spend-approvals/{$approval->id}/approve", [
            ...$this->decisionPayload($approval),
            'decision_notes' => '   ',
        ])->assertSessionHasErrors('decision_notes');

        $this->assertSame(SpendApproval::STATUS_SUBMITTED, $approval->fresh()->status);
        $this->assertDatabaseMissing('spend_approval_decisions', ['spend_approval_id' => $approval->id]);
    }

    public function test_cannot_approve_a_draft(): void
    {
        $requester = $this->createAdminUser();
        $decider = $this->createAdminUser();
        $approval = SpendApproval::create([
            'reference' => 'SA-2026-0004',
            'title' => 'Test', 'category' => 'opex',
            'amount' => 12000, 'currency' => 'NZD',
            'status' => SpendApproval::STATUS_DRAFT,
            'requested_by' => $requester->id,
            'site_id' => $this->site->id,
        ]);

        $this->actingAs($decider)->post("/governance/spend-approvals/{$approval->id}/approve", [
            'decision_key' => (string) Str::uuid(),
            'expected_version' => 1,
            'expected_content_digest' => str_repeat('0', 64),
            'decision_notes' => 'Drafts cannot be decided.',
        ])->assertSessionHasErrors('decision');

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
            'site_id' => $this->site->id,
        ]);

        $this->actingAs($admin)->put("/governance/spend-approvals/{$approval->id}", [
            'title' => 'Edited',
            'category' => 'opex',
            'amount' => 99999,
        ])->assertForbidden();

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
            'site_id' => $this->site->id,
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
            'site_id' => $this->site->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('governance_audit_log', [
            'action' => 'spend_approval.created',
            'resource_type' => 'SpendApproval',
            'user_id' => $admin->id,
        ]);
    }

    private function submittedApproval(User $requester, string $reference): SpendApproval
    {
        $approval = SpendApproval::create([
            'reference' => $reference,
            'title' => 'Test',
            'category' => 'opex',
            'amount' => 12000,
            'currency' => 'NZD',
            'site_id' => $this->site->id,
            'status' => SpendApproval::STATUS_DRAFT,
            'requested_by' => $requester->id,
            'version' => 1,
        ]);

        $approval->update([
            'status' => SpendApproval::STATUS_SUBMITTED,
            'submitted_by' => $requester->id,
            'submitted_at' => now(),
            'submission_version' => 2,
            'content_digest' => $approval->decisionContentDigest(),
            'version' => 2,
        ]);

        return $approval->fresh();
    }

    private function decisionPayload(SpendApproval $approval): array
    {
        return [
            'decision_key' => (string) Str::uuid(),
            'expected_version' => $approval->version,
            'expected_content_digest' => $approval->content_digest,
        ];
    }
}
