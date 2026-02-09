<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\RiskAcceptance;
use App\Domain\Governance\Models\RiskEventLink;
use App\Domain\Governance\Models\RiskTreatment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceRiskRegisterTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_can_create_risk(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/governance/risks', [
            'category' => 'financial',
            'title' => 'Funding shortfall',
            'description' => 'Funding risk',
            'likelihood_score' => 3,
            'impact_score' => 4,
            'control_effectiveness' => 'moderate',
            'mitigation_strategy' => 'treat',
            'review_frequency' => 'quarterly',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('risk_register_entries', [
            'title' => 'Funding shortfall',
            'category' => 'financial',
        ]);
    }

    public function test_can_update_risk(): void
    {
        $admin = $this->createAdminUser();
        $risk = $this->createRisk($admin);

        $response = $this->actingAs($admin)->put("/governance/risks/{$risk->id}", [
            'title' => 'Updated Risk',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('risk_register_entries', [
            'id' => $risk->id,
            'title' => 'Updated Risk',
        ]);
    }

    public function test_can_add_treatment(): void
    {
        $admin = $this->createAdminUser();
        $risk = $this->createRisk($admin);

        $response = $this->actingAs($admin)->post("/governance/risks/{$risk->id}/treatments", [
            'action_description' => 'Implement controls',
            'assigned_to' => $admin->id,
            'due_date' => now()->addDays(10)->toDateString(),
            'expected_score_reduction' => 2,
            'evidence_required' => false,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('risk_treatments', [
            'risk_register_entry_id' => $risk->id,
            'action_description' => 'Implement controls',
        ]);
    }

    public function test_can_accept_risk(): void
    {
        $admin = $this->createAdminUser();
        $risk = $this->createRisk($admin);

        $response = $this->actingAs($admin)->post("/governance/risks/{$risk->id}/accept", [
            'justification' => str_repeat('Justification ', 5),
            'expiry_months' => 6,
            'conditions' => ['monitor monthly'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('risk_acceptances', [
            'risk_register_entry_id' => $risk->id,
        ]);

        $risk->refresh();
        $this->assertEquals('accepted', $risk->status);
    }

    public function test_can_close_risk(): void
    {
        $admin = $this->createAdminUser();
        $risk = $this->createRisk($admin);

        $response = $this->actingAs($admin)->post("/governance/risks/{$risk->id}/close", [
            'rationale' => str_repeat('Closed because ', 2),
        ]);

        $response->assertRedirect();
        $risk->refresh();
        $this->assertEquals('voided', $risk->status);
    }

    public function test_can_link_event(): void
    {
        $admin = $this->createAdminUser();
        $risk = $this->createRisk($admin);

        $response = $this->actingAs($admin)->post("/governance/risks/{$risk->id}/link-event", [
            'event_type' => 'incident',
            'event_id' => 123,
            'event_reference' => 'INC-123',
            'event_severity' => 'high',
            'event_occurred_at' => now()->subDay()->toDateTimeString(),
            'link_rationale' => 'Related incident',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('risk_event_links', [
            'risk_register_entry_id' => $risk->id,
            'event_type' => 'incident',
            'event_id' => 123,
        ]);
    }
}
