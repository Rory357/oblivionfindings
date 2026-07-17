<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsInvestigationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HsInvestigationTest extends TestCase
{
    use RefreshDatabase;

    private HsInvestigationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(HsInvestigationService::class);
    }

    // ──────────────────────────────────────────────────────
    // Creation
    // ──────────────────────────────────────────────────────

    public function test_can_create_investigation_for_open_event(): void
    {
        $event = HsEvent::factory()->high()->create();

        $investigation = $this->service->create($event);

        $this->assertDatabaseHas('hs_investigations', [
            'hs_event_id' => $event->id,
            'status' => HsInvestigation::STATUS_DRAFT,
        ]);

        $this->assertStringStartsWith('INV-', $investigation->reference_number);
    }

    public function test_cannot_create_investigation_for_closed_event(): void
    {
        $event = HsEvent::factory()->closed()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('closed');

        $this->service->create($event);
    }

    public function test_cannot_create_duplicate_active_investigation(): void
    {
        $event = HsEvent::factory()->high()->create();

        $this->service->create($event);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already has an active investigation');

        $this->service->create($event);
    }

    public function test_create_locks_and_rechecks_the_parent_before_querying_or_inserting_work(): void
    {
        $event = HsEvent::factory()->high()->create();
        DB::connection()->enableQueryLog();

        $this->service->create($event);

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn (string $query): string => strtolower(str_replace(['`', '"'], '', $query)))
            ->values();
        DB::connection()->disableQueryLog();
        $eventLock = $queries->search(fn (string $query): bool => str_contains($query, 'from hs_events')
            && str_contains($query, 'for update'));
        $activeCheck = $queries->search(fn (string $query): bool => str_contains($query, 'from hs_investigations'));
        $insert = $queries->search(fn (string $query): bool => str_starts_with($query, 'insert into hs_investigations'));

        $this->assertNotFalse($eventLock);
        $this->assertNotFalse($activeCheck);
        $this->assertNotFalse($insert);
        $this->assertLessThan($activeCheck, $eventLock);
        $this->assertLessThan($insert, $eventLock);
    }

    public function test_worksafe_event_gets_worksafe_directed_type(): void
    {
        $event = HsEvent::factory()->worksafeNotifiable()->create();

        $investigation = $this->service->create($event);

        $this->assertEquals(HsInvestigation::TYPE_WORKSAFE_DIRECTED, $investigation->investigation_type);
    }

    public function test_critical_event_gets_full_investigation_type(): void
    {
        $event = HsEvent::factory()->critical()->create();

        $investigation = $this->service->create($event);

        $this->assertEquals(HsInvestigation::TYPE_FULL, $investigation->investigation_type);
    }

    public function test_high_event_gets_standard_investigation_type(): void
    {
        $event = HsEvent::factory()->high()->create([
            'worksafe_notifiable' => false,
        ]);

        $investigation = $this->service->create($event);

        $this->assertEquals(HsInvestigation::TYPE_STANDARD, $investigation->investigation_type);
    }

    public function test_target_date_varies_by_severity(): void
    {
        $criticalEvent = HsEvent::factory()->critical()->create();
        $highEvent = HsEvent::factory()->high()->create(['worksafe_notifiable' => false]);

        $critInv = $this->service->create($criticalEvent);
        $highInv = $this->service->create($highEvent);

        // Critical: 7 days, High: 14 days
        $this->assertEquals(
            now()->addDays(7)->toDateString(),
            $critInv->target_completion_date->toDateString()
        );

        $this->assertEquals(
            now()->addDays(14)->toDateString(),
            $highInv->target_completion_date->toDateString()
        );
    }

    public function test_target_completion_date_is_stored_and_presented_as_the_exact_calendar_date(): void
    {
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create(['tenant_id' => 1]);
        $viewer = User::factory()->create([
            'organization_id' => 1,
            'approved_at' => now(),
        ]);
        $hazardsView = Permission::query()->where('key', 'hazards.view')->firstOrFail();
        $viewer->permissionOverrides()->sync([
            $hazardsView->id => ['allowed' => true],
        ]);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 1,
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);
        $event = HsEvent::factory()->high()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
        ]);
        $investigation = HsInvestigation::factory()->create([
            'hs_event_id' => $event->id,
            'target_completion_date' => '2026-07-21',
        ]);

        $this->assertDatabaseHas('hs_investigations', [
            'id' => $investigation->id,
            'target_completion_date' => '2026-07-21',
        ]);
        $this->actingAs($viewer)
            ->get("/health-safety/events/{$event->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where(
                    'detail.investigations.0.target_completion_date',
                    '2026-07-21',
                )
            );
    }

    // ──────────────────────────────────────────────────────
    // Lifecycle transitions
    // ──────────────────────────────────────────────────────

    public function test_can_start_investigation_with_investigator(): void
    {
        $event = HsEvent::factory()->high()->create();
        $investigation = $this->service->create($event);
        $investigator = User::factory()->create();

        $result = $this->service->start($investigation, $investigator->id);

        $this->assertEquals(HsInvestigation::STATUS_IN_PROGRESS, $result->status);
        $this->assertEquals($investigator->id, $result->lead_investigator_id);
        $this->assertNotNull($result->started_at);

        // HsEvent should now be 'investigating'
        $event->refresh();
        $this->assertEquals(HsEvent::STATUS_INVESTIGATING, $event->status);
    }

    public function test_cannot_start_without_investigator(): void
    {
        $event = HsEvent::factory()->high()->create();
        $investigation = $this->service->create($event);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lead investigator');

        $this->service->start($investigation);
    }

    public function test_cannot_start_completed_investigation(): void
    {
        $investigation = HsInvestigation::factory()->completed()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot transition');

        $this->service->start($investigation);
    }

    public function test_can_record_findings(): void
    {
        $investigation = HsInvestigation::factory()->inProgress()->create();

        $result = $this->service->recordFindings($investigation, [
            'immediate_causes' => [
                ['description' => 'Wet floor', 'category' => 'environmental'],
            ],
            'root_causes' => [
                ['description' => 'No signage protocol', 'category' => 'procedural'],
            ],
            'findings_summary' => 'Staff slipped due to unmarked wet floor.',
            'recommendations' => [
                ['description' => 'Implement signage procedure', 'priority' => 'high', 'target_area' => 'procedure'],
            ],
        ]);

        $this->assertEquals(HsInvestigation::STATUS_FINDINGS_RECORDED, $result->status);
        $this->assertCount(1, $result->immediate_causes);
        $this->assertCount(1, $result->root_causes);
        $this->assertCount(1, $result->recommendations);
    }

    public function test_cannot_record_empty_findings(): void
    {
        $investigation = HsInvestigation::factory()->inProgress()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one cause');

        $this->service->recordFindings($investigation, []);
    }

    public function test_can_submit_for_review(): void
    {
        $investigation = HsInvestigation::factory()->withFindings()->create();

        $result = $this->service->submitForReview($investigation);

        $this->assertEquals(HsInvestigation::STATUS_UNDER_REVIEW, $result->status);
    }

    public function test_can_return_for_rework(): void
    {
        $investigation = HsInvestigation::factory()->withFindings()->create([
            'status' => HsInvestigation::STATUS_UNDER_REVIEW,
        ]);

        $result = $this->service->returnForRework($investigation, 'Needs more detail on root causes.');

        $this->assertEquals(HsInvestigation::STATUS_IN_PROGRESS, $result->status);
        $this->assertEquals('Needs more detail on root causes.', $result->review_notes);
    }

    public function test_can_complete_investigation(): void
    {
        $investigation = HsInvestigation::factory()->withFindings()->create([
            'status' => HsInvestigation::STATUS_UNDER_REVIEW,
        ]);
        $reviewer = User::factory()->create();

        $result = $this->service->complete($investigation, [
            'reviewed_by_id' => $reviewer->id,
        ]);

        $this->assertEquals(HsInvestigation::STATUS_COMPLETED, $result->status);
        $this->assertNotNull($result->completed_at);
        $this->assertNotNull($result->approved_at);

        // HsEvent should now be 'corrective_action'
        $event = $investigation->hsEvent->fresh();
        $this->assertEquals(HsEvent::STATUS_CORRECTIVE_ACTION, $event->status);
    }

    public function test_cannot_complete_without_recommendations(): void
    {
        $investigation = HsInvestigation::factory()->create([
            'status' => HsInvestigation::STATUS_UNDER_REVIEW,
            'lead_investigator_id' => User::factory(),
            'started_at' => now(),
            'findings_summary' => 'Some findings.',
            'recommendations' => null,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('recommendations');

        $this->service->complete($investigation, []);
    }

    // ──────────────────────────────────────────────────────
    // Invalid transitions
    // ──────────────────────────────────────────────────────

    public function test_cannot_skip_lifecycle_steps(): void
    {
        $investigation = HsInvestigation::factory()->create([
            'status' => HsInvestigation::STATUS_DRAFT,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        // Draft cannot jump to findings_recorded
        $this->service->recordFindings($investigation, [
            'findings_summary' => 'Test',
        ]);
    }

    public function test_cannot_submit_draft_for_review(): void
    {
        $investigation = HsInvestigation::factory()->create([
            'status' => HsInvestigation::STATUS_DRAFT,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->submitForReview($investigation);
    }

    // ──────────────────────────────────────────────────────
    // Working data updates
    // ──────────────────────────────────────────────────────

    public function test_can_update_working_data_in_draft(): void
    {
        $investigation = HsInvestigation::factory()->create();

        $result = $this->service->updateWorkingData($investigation, [
            'methodology' => HsInvestigation::METHODOLOGY_FISHBONE,
            'immediate_causes' => [['description' => 'Initial observation']],
        ]);

        $this->assertEquals(HsInvestigation::METHODOLOGY_FISHBONE, $result->methodology);
        $this->assertCount(1, $result->immediate_causes);
    }

    public function test_cannot_update_working_data_after_findings_recorded(): void
    {
        $investigation = HsInvestigation::factory()->withFindings()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('draft or in_progress');

        $this->service->updateWorkingData($investigation, [
            'methodology' => HsInvestigation::METHODOLOGY_FISHBONE,
        ]);
    }

    // ──────────────────────────────────────────────────────
    // HsEvent relationship
    // ──────────────────────────────────────────────────────

    public function test_event_relationship_bidirectional(): void
    {
        $event = HsEvent::factory()->high()->create();
        $investigation = $this->service->create($event);

        // From HsEvent
        $this->assertNotNull($event->latestInvestigation);
        $this->assertEquals($investigation->id, $event->latestInvestigation->id);

        // From HsInvestigation
        $this->assertEquals($event->id, $investigation->hsEvent->id);
    }

    public function test_event_can_create_investigation_flag(): void
    {
        $event = HsEvent::factory()->high()->create();

        $this->assertTrue($event->canCreateInvestigation());

        $this->service->create($event);

        $event->refresh();
        $this->assertFalse($event->canCreateInvestigation());
    }

    // ──────────────────────────────────────────────────────
    // HsEvent status sync — forward only
    // ──────────────────────────────────────────────────────

    public function test_event_status_does_not_regress(): void
    {
        $event = HsEvent::factory()->high()->create([
            'status' => HsEvent::STATUS_CORRECTIVE_ACTION,
        ]);

        $investigation = HsInvestigation::factory()->create([
            'hs_event_id' => $event->id,
        ]);

        $investigator = User::factory()->create();
        $this->service->start($investigation, $investigator->id);

        // Event was already at corrective_action — should NOT regress to investigating
        $event->refresh();
        $this->assertEquals(HsEvent::STATUS_CORRECTIVE_ACTION, $event->status);
    }

    // ──────────────────────────────────────────────────────
    // Model helpers
    // ──────────────────────────────────────────────────────

    public function test_overdue_detection(): void
    {
        $investigation = HsInvestigation::factory()->inProgress()->create([
            'target_completion_date' => now()->subDays(1),
        ]);

        $this->assertTrue($investigation->isOverdue());
    }

    public function test_completed_investigation_is_not_overdue(): void
    {
        $investigation = HsInvestigation::factory()->completed()->create([
            'target_completion_date' => now()->subDays(1),
        ]);

        $this->assertFalse($investigation->isOverdue());
    }

    public function test_overdue_scope(): void
    {
        HsInvestigation::factory()->inProgress()->create([
            'target_completion_date' => now()->subDays(1),
        ]);

        HsInvestigation::factory()->inProgress()->create([
            'target_completion_date' => now()->addDays(5),
        ]);

        $this->assertCount(1, HsInvestigation::overdue()->get());
    }

    public function test_reference_number_is_unique_and_sequential(): void
    {
        $ref1 = HsInvestigation::generateReferenceNumber();
        HsInvestigation::factory()->create(['reference_number' => $ref1]);

        $ref2 = HsInvestigation::generateReferenceNumber();

        $this->assertNotEquals($ref1, $ref2);
        $this->assertStringStartsWith('INV-'.now()->year.'-', $ref2);
    }
}
