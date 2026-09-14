<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\RiskAcceptance;
use App\Domain\Governance\Models\RiskEventLink;
use App\Domain\Governance\Models\RiskTreatment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_retired_create_page_redirects_to_register_wizard_with_form_options(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->get('/governance/risks/create')
            ->assertRedirect('/governance/risks?create=1');

        $this->actingAs($admin)->get('/governance/risks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Governance/Risks/Index')
                ->where('canCreate', true)
                ->has('formOptions.categories', 8)
                ->has('formOptions.owners')
            );
    }

    public function test_view_only_member_gets_no_risk_wizard_options(): void
    {
        $member = $this->createUserWithRole('board_member');

        $this->actingAs($member)->get('/governance/risks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Governance/Risks/Index')
                ->where('canCreate', false)
                ->where('formOptions', null)
            );
    }

    public function test_retired_edit_page_redirects_to_record_wizard_with_form_options(): void
    {
        $admin = $this->createAdminUser();
        $risk = $this->createRisk($admin);

        $this->actingAs($admin)->get("/governance/risks/{$risk->id}/edit")
            ->assertRedirect("/governance/risks/{$risk->id}?edit=1");

        $this->actingAs($admin)->get("/governance/risks/{$risk->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Governance/Risks/Show')
                ->where('canEdit', true)
                ->has('formOptions.categories')
                ->has('formOptions.owners')
            );
    }

    public function test_wizard_create_stays_on_the_register(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->from('/governance/risks')
            ->post('/governance/risks', [
                '_modal' => true,
                'category' => 'workforce',
                'title' => 'Wizard registered risk',
                'description' => 'Staffing shortfall on night shifts',
                'likelihood_score' => 4,
                'impact_score' => 4,
                'control_effectiveness' => 'weak',
                'mitigation_strategy' => 'treat',
                'review_frequency' => 'monthly',
            ])
            ->assertRedirect('/governance/risks')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('risk_register_entries', [
            'title' => 'Wizard registered risk',
            'residual_score' => 13,
            'review_frequency' => 'monthly',
        ]);
    }

    public function test_register_filters_by_search_and_above_appetite(): void
    {
        $admin = $this->createAdminUser();
        $this->createRisk($admin, ['title' => 'Cyber breach', 'risk_reference' => 'R-CYBER1']);
        $above = $this->createRisk($admin, [
            'title' => 'Funding cliff',
            'risk_reference' => 'R-FUND01',
            'likelihood_score' => 5,
            'impact_score' => 5,
            'control_effectiveness' => 'none',
            'appetite_threshold' => 10,
        ]);

        $this->actingAs($admin)->get('/governance/risks?search=cyber')
            ->assertInertia(fn (Assert $page) => $page
                ->has('risks.data', 1)
                ->where('risks.data.0.title', 'Cyber breach')
                ->where('filters.search', 'cyber')
            );

        $this->actingAs($admin)->get('/governance/risks?above_appetite=1')
            ->assertInertia(fn (Assert $page) => $page
                ->has('risks.data', 1)
                ->where('risks.data.0.id', $above->id)
                ->where('risks.data.0.treatments_count', 0)
            );
    }

    public function test_heatmap_counts_each_risk_once_and_filters_by_category(): void
    {
        $admin = $this->createAdminUser();
        // Likelihood 2 × impact 2 and likelihood 1 × impact 4 share score 4.
        $this->createRisk($admin, ['likelihood_score' => 2, 'impact_score' => 2, 'category' => 'financial']);
        $this->createRisk($admin, ['likelihood_score' => 1, 'impact_score' => 4, 'category' => 'workforce']);

        $cell = fn (array $heatmap, int $likelihood, int $impact) => $heatmap[5 - $likelihood][$impact - 1];

        $response = $this->actingAs($admin)->get('/governance/risks/heatmap')->assertOk();
        $heatmap = $response->inertiaProps('heatmap');
        $this->assertSame(1, $cell($heatmap, 2, 2)['count']);
        $this->assertSame(1, $cell($heatmap, 1, 4)['count']);
        $this->assertSame(0, $cell($heatmap, 4, 1)['count']);
        $this->assertSame(2, collect($heatmap)->flatten(1)->sum('count'));

        $filtered = $this->actingAs($admin)->get('/governance/risks/heatmap?category=workforce')->assertOk();
        $this->assertSame(1, collect($filtered->inertiaProps('heatmap'))->flatten(1)->sum('count'));
        $this->assertSame('workforce', $filtered->inertiaProps('filters.category'));
    }
}
