<?php

namespace Tests\Feature\Roadmap;

use App\Domain\Roadmap\Models\DecisionRequest;
use App\Domain\Roadmap\Models\InitiativeSuggestion;
use App\Domain\Roadmap\Models\QuarterlyRoadmapPlan;
use App\Domain\Roadmap\Models\QuarterlyRoadmapPlanItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RoadmapTestHelpers;
use Tests\TestCase;

class RoadmapDetailPagesTest extends TestCase
{
    use RefreshDatabase;
    use RoadmapTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoadmapModule();
    }

    public function test_initiatives_route_renders_register_page_and_keeps_json_contract(): void
    {
        $manager = $this->createUserWithRole('roadmap_manager');
        $this->createInitiative($manager, [
            'title' => 'Rostering coverage initiative',
            'status' => 'approved',
            'stream' => 'operations',
            'target_fiscal_year' => 2026,
            'target_quarter' => 2,
        ]);

        $this->actingAs($manager)
            ->get('/roadmap/initiatives?status=approved&stream=operations&fiscal_year=2026&quarter=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Roadmap/Initiatives/Index')
                ->where('filters.status', 'approved')
                ->where('filters.stream', 'operations')
                ->where('filters.fiscal_year', '2026')
                ->where('filters.quarter', '2')
                ->where('items.data.0.title', 'Rostering coverage initiative')
                ->has('can')
            );

        $this->actingAs($manager)
            ->getJson('/roadmap/initiatives?status=approved&per_page=1')
            ->assertOk()
            ->assertJsonStructure(['items' => ['data']])
            ->assertJsonPath('items.data.0.title', 'Rostering coverage initiative');
    }

    public function test_suggestions_route_renders_triage_backlog_and_keeps_json_contract(): void
    {
        $manager = $this->createUserWithRole('roadmap_manager');

        InitiativeSuggestion::create([
            'source' => 'rostering',
            'source_key' => 'coverage-shortage',
            'title' => 'Recurring weekend coverage shortage',
            'summary' => 'Coverage report shows repeated Saturday morning deficits.',
            'dedupe_key' => 'rostering:coverage-shortage',
            'status' => InitiativeSuggestion::STATUS_TRIAGE_PENDING,
            'triage_owner_id' => $manager->id,
            'last_seen_at' => now(),
            'first_seen_at' => now()->subDay(),
        ]);

        $this->actingAs($manager)
            ->get('/roadmap/suggestions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Roadmap/Suggestions/Index')
                ->where('filters.status', InitiativeSuggestion::STATUS_TRIAGE_PENDING)
                ->where('items.data.0.title', 'Recurring weekend coverage shortage')
                ->has('managers')
                ->has('can')
            );

        $this->actingAs($manager)
            ->getJson('/roadmap/suggestions?status=triage_pending&per_page=1')
            ->assertOk()
            ->assertJsonStructure(['items' => ['data']])
            ->assertJsonPath('items.data.0.title', 'Recurring weekend coverage shortage');
    }

    public function test_quarterly_plan_routes_render_history_and_detail_pages_and_keep_json_contract(): void
    {
        $manager = $this->createUserWithRole('roadmap_manager');
        $initiative = $this->createInitiative($manager, ['title' => 'Roster analytics rollout']);
        $plan = QuarterlyRoadmapPlan::create([
            'fiscal_year' => 2026,
            'quarter' => 2,
            'status' => QuarterlyRoadmapPlan::STATUS_DRAFT,
            'preset_profile' => 'board_ceo',
            'generated_at' => now(),
            'generated_by' => $manager->id,
        ]);
        QuarterlyRoadmapPlanItem::create([
            'quarterly_plan_id' => $plan->id,
            'initiative_id' => $initiative->id,
            'rank' => 1,
            'planned_capex' => 2500,
            'planned_opex' => 500,
            'score_at_snapshot' => 72.5,
        ]);

        $this->actingAs($manager)
            ->get('/roadmap/quarterly-plans?fiscal_year=2026&quarter=2&status=draft')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Roadmap/QuarterlyPlans/Index')
                ->where('filters.fiscal_year', '2026')
                ->where('filters.quarter', '2')
                ->where('filters.status', 'draft')
                ->where('items.data.0.fiscal_year', 2026)
                ->has('can')
            );

        $this->actingAs($manager)
            ->get("/roadmap/quarterly-plans/{$plan->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Roadmap/QuarterlyPlans/Show')
                ->where('item.id', $plan->id)
                ->where('item.items.0.initiative.title', 'Roster analytics rollout')
                ->has('can')
            );

        $this->actingAs($manager)
            ->getJson('/roadmap/quarterly-plans?per_page=1')
            ->assertOk()
            ->assertJsonStructure(['items' => ['data']])
            ->assertJsonPath('items.data.0.id', $plan->id);

        $this->actingAs($manager)
            ->getJson("/roadmap/quarterly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('item.id', $plan->id);
    }

    public function test_decisions_route_renders_pending_queue_and_keeps_json_contract(): void
    {
        $ceo = $this->createUserWithRole('ceo');
        $initiative = $this->createInitiative($ceo, ['title' => 'Service continuity investment']);

        DecisionRequest::create([
            'source_type' => $initiative->getMorphClass(),
            'source_id' => $initiative->id,
            'request_type' => 'budget',
            'status' => 'pending',
            'required_role' => 'ceo',
            'requested_by' => $ceo->id,
            'due_date' => now()->addWeek()->toDateString(),
            'rationale' => 'Approve coverage analytics spend.',
            'recommendation' => 'Approve.',
        ]);

        $this->actingAs($ceo)
            ->get('/roadmap/decisions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Roadmap/Decisions/Index')
                ->where('filters.status', 'pending')
                ->where('items.data.0.request_type', 'budget')
                ->has('can')
            );

        $this->actingAs($ceo)
            ->getJson('/roadmap/decisions?status=pending&per_page=1')
            ->assertOk()
            ->assertJsonStructure(['items' => ['data']])
            ->assertJsonPath('items.data.0.request_type', 'budget');
    }
}
