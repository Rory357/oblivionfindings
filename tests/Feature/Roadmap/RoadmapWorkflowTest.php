<?php

namespace Tests\Feature\Roadmap;

use App\Domain\Roadmap\Models\Initiative;
use App\Domain\Roadmap\Models\InitiativeCategory;
use App\Domain\Roadmap\Models\InitiativeSuggestion;
use App\Domain\Roadmap\Models\QuarterlyRoadmapPlan;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\GovernancePermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\RoadmapPermissionsSeeder;
use Database\Seeders\RoadmapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoadmapWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(GovernancePermissionsSeeder::class);
        $this->seed(RoadmapPermissionsSeeder::class);
        $this->seed(RoadmapSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $adminRole->permissions()->sync(Permission::pluck('id')->all());

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    public function test_admin_can_quick_add_initiative(): void
    {
        $category = InitiativeCategory::where('key', 'operations')->firstOrFail();
        $owner = User::factory()->create([
            'role' => 'roadmap_manager',
            'approved_at' => now(),
        ]);
        $owner->roles()->syncWithoutDetaching([
            Role::where('name', 'roadmap_manager')->firstOrFail()->id,
        ]);
        $sponsor = User::factory()->create([
            'role' => 'cfo',
            'approved_at' => now(),
        ]);
        $sponsor->roles()->syncWithoutDetaching([
            Role::where('name', 'cfo')->firstOrFail()->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/roadmap/initiatives', [
                'title' => 'Reduce repetitive incident paperwork',
                'category_id' => $category->id,
                'stream' => 'operations',
                'owner_user_id' => $owner->id,
                'sponsor_user_id' => $sponsor->id,
                'cost_estimate_low' => 2000,
                'cost_estimate_high' => 6000,
                'next_decision' => 'Approve pilot',
                'impact_profile' => [
                    'safety' => 3,
                    'compliance' => 3,
                    'reputation' => 2,
                    'financial' => 3,
                    'efficiency' => 4,
                    'urgency' => 3,
                    'complexity' => 2,
                    'dependency' => 2,
                    'multi_site' => 4,
                ],
            ]);

        $response->assertCreated();

        $initiativeId = $response->json('item.id');
        $this->assertNotNull($initiativeId);

        $this->assertDatabaseHas('roadmap_initiatives', [
            'id' => $initiativeId,
            'title' => 'Reduce repetitive incident paperwork',
            'owner_user_id' => $owner->id,
            'sponsor_user_id' => $sponsor->id,
        ]);
    }

    public function test_suggestion_can_be_triaged_and_converted(): void
    {
        $suggestion = InitiativeSuggestion::create([
            'source' => 'control_room',
            'title' => 'Recurring camera offline alerts',
            'summary' => 'Camera offline alerts exceeded threshold.',
            'dedupe_key' => 'control:camera_offline:site_1:14d',
            'status' => InitiativeSuggestion::STATUS_TRIAGE_PENDING,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'hit_count' => 1,
        ]);

        $triage = $this->actingAs($this->admin)
            ->postJson("/roadmap/suggestions/{$suggestion->id}/triage", [
                'status' => 'accepted',
            ]);

        $triage->assertOk();

        $convert = $this->actingAs($this->admin)
            ->postJson("/roadmap/suggestions/{$suggestion->id}/convert", [
                'category_key' => 'it',
                'stream' => 'it',
                'next_decision' => 'Approve remediation',
                'impact_profile' => [
                    'safety' => 4,
                    'compliance' => 4,
                    'reputation' => 3,
                    'financial' => 2,
                    'efficiency' => 3,
                    'urgency' => 4,
                    'complexity' => 2,
                    'dependency' => 2,
                    'multi_site' => 4,
                ],
            ]);

        $convert->assertCreated();

        $initiativeId = $convert->json('initiative.id');
        $this->assertDatabaseHas('roadmap_initiatives', [
            'id' => $initiativeId,
        ]);
        $this->assertDatabaseHas('roadmap_suggestions', [
            'id' => $suggestion->id,
            'status' => 'converted',
            'converted_initiative_id' => $initiativeId,
        ]);
    }

    public function test_suggestion_can_be_assigned_to_triage_owner_and_owner_persists(): void
    {
        $triageOwner = User::factory()->create([
            'role' => 'roadmap_manager',
            'approved_at' => now(),
        ]);
        $triageOwner->roles()->syncWithoutDetaching([
            Role::where('name', 'roadmap_manager')->firstOrFail()->id,
        ]);

        $suggestion = InitiativeSuggestion::create([
            'source' => 'incidents',
            'title' => 'Recurring falls in one home',
            'summary' => 'Cluster detected in last 30 days.',
            'dedupe_key' => 'incident:falls:home_3:30d',
            'status' => InitiativeSuggestion::STATUS_TRIAGE_PENDING,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'hit_count' => 4,
        ]);

        $assign = $this->actingAs($this->admin)
            ->postJson("/roadmap/suggestions/{$suggestion->id}/triage", [
                'status' => 'triage_pending',
                'triage_owner_id' => $triageOwner->id,
            ]);

        $assign->assertOk();
        $assign->assertJsonPath('item.triage_owner_id', $triageOwner->id);

        $this->assertDatabaseHas('roadmap_suggestions', [
            'id' => $suggestion->id,
            'status' => InitiativeSuggestion::STATUS_TRIAGE_PENDING,
            'triage_owner_id' => $triageOwner->id,
        ]);

        $accept = $this->actingAs($this->admin)
            ->postJson("/roadmap/suggestions/{$suggestion->id}/triage", [
                'status' => 'accepted',
            ]);

        $accept->assertOk();
        $accept->assertJsonPath('item.triage_owner_id', $triageOwner->id);

        $list = $this->actingAs($this->admin)
            ->getJson('/roadmap/suggestions?status=accepted');

        $list->assertOk();
        $list->assertJsonPath('items.data.0.triage_owner.id', $triageOwner->id);
    }

    public function test_suggestion_triage_notes_can_be_saved_and_carried_to_convert(): void
    {
        $suggestion = InitiativeSuggestion::create([
            'source' => 'assets',
            'title' => 'Recurring maintenance backlog',
            'summary' => 'Multiple devices are beyond maintenance window.',
            'dedupe_key' => 'asset:backlog:notes:q1',
            'status' => InitiativeSuggestion::STATUS_TRIAGE_PENDING,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'hit_count' => 2,
        ]);

        $saveNotes = $this->actingAs($this->admin)
            ->postJson("/roadmap/suggestions/{$suggestion->id}/triage", [
                'status' => 'triage_pending',
                'triage_notes' => 'Prioritise homes with repeated emergency callouts first.',
            ]);

        $saveNotes->assertOk();
        $saveNotes->assertJsonPath(
            'item.triage_notes',
            'Prioritise homes with repeated emergency callouts first.',
        );

        $convert = $this->actingAs($this->admin)
            ->postJson("/roadmap/suggestions/{$suggestion->id}/convert", [
                'category_key' => 'maintenance',
                'stream' => 'maintenance',
                'next_decision' => 'Approve contractor block booking',
                'triage_notes' => 'Escalate to facilities manager with 30-day remediation target.',
            ]);

        $convert->assertCreated();

        $initiativeId = $convert->json('initiative.id');
        $this->assertDatabaseHas('roadmap_initiatives', [
            'id' => $initiativeId,
        ]);
        $this->assertDatabaseHas('roadmap_suggestions', [
            'id' => $suggestion->id,
            'status' => InitiativeSuggestion::STATUS_CONVERTED,
            'triage_notes' => 'Escalate to facilities manager with 30-day remediation target.',
        ]);
    }

    public function test_quarterly_plan_can_publish_and_revise_with_immutable_snapshot(): void
    {
        $category = InitiativeCategory::where('key', 'it')->firstOrFail();

        Initiative::create([
            'title' => 'MFA uplift',
            'category_id' => $category->id,
            'stream' => 'it',
            'status' => Initiative::STATUS_APPROVED,
            'cost_estimate_high' => 15000,
            'next_decision' => 'Board approval',
            'decision_due_at' => now()->addDays(10),
            'impact_profile' => [
                'safety' => 4,
                'compliance' => 5,
                'reputation' => 4,
                'financial' => 2,
                'efficiency' => 2,
                'urgency' => 4,
                'complexity' => 2,
                'dependency' => 1,
                'multi_site' => 5,
            ],
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $generate = $this->actingAs($this->admin)
            ->postJson('/roadmap/quarterly-plans/generate', [
                'fiscal_year' => now()->year,
                'quarter' => now()->quarter,
                'preset' => 'board_ceo',
            ]);

        $generate->assertCreated();
        $planId = $generate->json('item.id');

        $this->actingAs($this->admin)->postJson("/roadmap/quarterly-plans/{$planId}/submit-manager")->assertOk();
        $this->actingAs($this->admin)->postJson("/roadmap/quarterly-plans/{$planId}/submit-executive")->assertOk();
        $this->actingAs($this->admin)->postJson("/roadmap/quarterly-plans/{$planId}/approve")->assertOk();

        $publish = $this->actingAs($this->admin)
            ->postJson("/roadmap/quarterly-plans/{$planId}/publish");

        $publish->assertOk();

        $this->assertDatabaseHas('roadmap_quarterly_plans', [
            'id' => $planId,
            'status' => 'published',
        ]);

        $plan = QuarterlyRoadmapPlan::findOrFail($planId);
        $this->assertNotNull($plan->snapshot_hash);
        $this->assertNotNull($plan->snapshot_payload);

        $revise = $this->actingAs($this->admin)
            ->postJson("/roadmap/quarterly-plans/{$planId}/revise", [
                'change_summary' => 'Budget constraints require revision',
            ]);

        $revise->assertCreated();
        $this->assertEquals(2, (int) $revise->json('item.revision_no'));
    }

    public function test_quarterly_plan_detail_endpoint_returns_ranked_items(): void
    {
        $category = InitiativeCategory::where('key', 'operations')->firstOrFail();

        $initiative = Initiative::create([
            'title' => 'Standardise site onboarding checklist',
            'category_id' => $category->id,
            'stream' => 'operations',
            'status' => Initiative::STATUS_APPROVED,
            'cost_estimate_high' => 7500,
            'next_decision' => 'Approve rollout wave one',
            'decision_due_at' => now()->addDays(7),
            'impact_profile' => [
                'safety' => 3,
                'compliance' => 4,
                'reputation' => 3,
                'financial' => 2,
                'efficiency' => 4,
                'urgency' => 3,
                'complexity' => 2,
                'dependency' => 1,
                'multi_site' => 5,
            ],
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $generate = $this->actingAs($this->admin)
            ->postJson('/roadmap/quarterly-plans/generate', [
                'fiscal_year' => now()->year,
                'quarter' => now()->quarter,
                'preset' => 'board_ceo',
            ]);

        $generate->assertCreated();
        $planId = $generate->json('item.id');

        $detail = $this->actingAs($this->admin)
            ->getJson("/roadmap/quarterly-plans/{$planId}");

        $detail->assertOk();
        $detail->assertJsonPath('item.id', $planId);
        $detail->assertJsonPath('item.items.0.rank', 1);
        $detail->assertJsonPath('item.items.0.initiative.id', $initiative->id);
    }

    public function test_quarterly_plan_detail_endpoint_requires_roadmap_permission(): void
    {
        $generate = $this->actingAs($this->admin)
            ->postJson('/roadmap/quarterly-plans/generate', [
                'fiscal_year' => now()->year,
                'quarter' => now()->quarter,
                'preset' => 'board_ceo',
            ]);

        $generate->assertCreated();
        $planId = $generate->json('item.id');
        $unprivileged = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($unprivileged)
            ->getJson("/roadmap/quarterly-plans/{$planId}")
            ->assertForbidden();
    }

    public function test_governance_dashboard_data_includes_roadmap_widget(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/governance/dashboard/data?period=month');

        $response->assertOk();
        $this->assertArrayHasKey('roadmap', $response->json('widgets'));
    }
}
