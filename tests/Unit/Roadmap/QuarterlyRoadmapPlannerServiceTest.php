<?php

namespace Tests\Unit\Roadmap;

use App\Domain\Roadmap\Models\QuarterlyRoadmapPlan;
use App\Domain\Roadmap\Services\QuarterlyRoadmapPlannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RoadmapTestHelpers;
use Tests\TestCase;

class QuarterlyRoadmapPlannerServiceTest extends TestCase
{
    use RefreshDatabase;
    use RoadmapTestHelpers;

    protected QuarterlyRoadmapPlannerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoadmapModule();
        $this->service = app(QuarterlyRoadmapPlannerService::class);
    }

    public function test_generate_draft_creates_ranked_plan_items_for_eligible_statuses(): void
    {
        $admin = $this->createAdminUser();

        $includedA = $this->createInitiative($admin, [
            'title' => 'A initiative',
            'status' => 'approved',
            'impact_profile' => ['safety' => 5, 'compliance' => 4, 'reputation' => 4, 'financial' => 3, 'efficiency' => 3, 'urgency' => 4, 'complexity' => 1, 'dependency' => 1, 'multi_site' => 4],
        ]);
        $includedB = $this->createInitiative($admin, [
            'title' => 'B initiative',
            'status' => 'proposed',
            'impact_profile' => ['safety' => 2, 'compliance' => 2, 'reputation' => 2, 'financial' => 3, 'efficiency' => 3, 'urgency' => 2, 'complexity' => 3, 'dependency' => 3, 'multi_site' => 2],
        ]);
        $this->createInitiative($admin, [
            'title' => 'Ignored draft',
            'status' => 'draft',
        ]);

        $plan = $this->service->generateDraft(now()->year, now()->quarter, 'board_ceo', $admin->id);

        $this->assertSame('draft', $plan->status);
        $this->assertSame(2, $plan->items->count());
        $this->assertEquals([1, 2], $plan->items->pluck('rank')->values()->all());
        $this->assertEqualsCanonicalizing([$includedA->id, $includedB->id], $plan->items->pluck('initiative_id')->all());

        $this->assertDatabaseHas('roadmap_change_log_entries', [
            'entity_type' => QuarterlyRoadmapPlan::class,
            'entity_id' => $plan->id,
            'change_type' => 'plan.generated',
        ]);
    }

    public function test_workflow_transitions_and_validation_guards(): void
    {
        $admin = $this->createAdminUser();
        $this->createInitiative($admin, ['status' => 'approved']);

        $plan = $this->service->generateDraft(now()->year, now()->quarter, 'board_ceo', $admin->id);

        $this->expectException(\RuntimeException::class);
        $this->service->submitForExecutiveReview($plan, $admin->id);
    }

    public function test_publish_requires_approved_plan_and_is_immutable(): void
    {
        $admin = $this->createAdminUser();
        $this->createInitiative($admin, ['status' => 'approved']);

        $plan = $this->service->generateDraft(now()->year, now()->quarter, 'board_ceo', $admin->id);

        $this->expectException(\RuntimeException::class);
        $this->service->publish($plan, $admin->id);
    }

    public function test_publish_and_revision_flow_creates_snapshot_and_revision_copy(): void
    {
        $admin = $this->createAdminUser();
        $this->createInitiative($admin, ['status' => 'approved']);
        $this->createInitiative($admin, ['status' => 'in_progress']);

        $plan = $this->service->generateDraft(now()->year, now()->quarter, 'board_ceo', $admin->id);
        $plan = $this->service->submitForManagerReview($plan, $admin->id);
        $plan = $this->service->submitForExecutiveReview($plan, $admin->id);
        $plan = $this->service->approve($plan, $admin->id);
        $plan = $this->service->publish($plan, $admin->id);

        $this->assertSame('published', $plan->status);
        $this->assertNotNull($plan->snapshot_hash);
        $this->assertIsArray($plan->snapshot_payload);
        $this->assertSame(1, $plan->revision_no);

        $revision = $this->service->createRevisionFromPublished($plan, $admin->id, 'Budget adjustment');

        $this->assertSame('draft', $revision->status);
        $this->assertSame(2, $revision->revision_no);
        $this->assertSame($plan->items()->count(), $revision->items()->count());

        $this->expectException(\RuntimeException::class);
        $this->service->publish($plan, $admin->id);
    }

    public function test_revision_requires_published_plan(): void
    {
        $admin = $this->createAdminUser();
        $this->createInitiative($admin, ['status' => 'approved']);

        $plan = $this->service->generateDraft(now()->year, now()->quarter, 'board_ceo', $admin->id);

        $this->expectException(\RuntimeException::class);
        $this->service->createRevisionFromPublished($plan, $admin->id);
    }
}
