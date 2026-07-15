<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\HsCorrectiveAction;
use App\Models\HsInvestigation;
use App\Models\HsRecommendationDisposition;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\HealthSafety\HsCorrectiveActionService;
use App\Services\HealthSafety\HsInvestigationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HsRecommendationDispositionTest extends TestCase
{
    use RefreshDatabase;

    private HsInvestigationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->service = app(HsInvestigationService::class);
    }

    public function test_completed_recommendations_start_undispositioned_and_non_action_requires_a_reason(): void
    {
        $actor = User::factory()->create();
        $investigation = HsInvestigation::factory()->completed()->create();

        $this->assertSame([0, 1], $this->service->undispositionedRecommendationIndexes($investigation));

        try {
            $this->service->dispositionRecommendation(
                $investigation,
                0,
                HsRecommendationDisposition::DISPOSITION_ACCEPTED_RISK,
                $actor,
            );
            $this->fail('A non-action disposition without a reason must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('reason', $exception->getMessage());
        }

        $disposition = $this->service->dispositionRecommendation(
            $investigation,
            0,
            HsRecommendationDisposition::DISPOSITION_ACCEPTED_RISK,
            $actor,
            'The remaining risk is within the approved tolerance after controls were verified.',
        );

        $this->assertSame(HsRecommendationDisposition::DISPOSITION_ACCEPTED_RISK, $disposition->disposition);
        $this->assertSame($actor->id, $disposition->decided_by_user_id);
        $this->assertNotNull($disposition->decided_at);
        $this->assertSame([1], $this->service->undispositionedRecommendationIndexes($investigation->fresh()));

        $audit = AuditLog::query()
            ->where('action', 'healthSafety.investigation.recommendationDispositioned')
            ->where('auditable_type', $investigation->getMorphClass())
            ->where('auditable_id', $investigation->id)
            ->firstOrFail();
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame(0, $audit->meta['recommendation_index']);
        $this->assertSame(HsRecommendationDisposition::DISPOSITION_ACCEPTED_RISK, $audit->meta['disposition']);
    }

    public function test_corrective_action_disposition_creates_links_and_reuses_exactly_one_action(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $investigation = HsInvestigation::factory()->completed()->create();

        $first = $this->service->dispositionRecommendation(
            $investigation,
            0,
            HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION,
            $actor,
        );
        $second = $this->service->dispositionRecommendation(
            $investigation->fresh(),
            0,
            HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION,
            $actor,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->hs_corrective_action_id, $second->hs_corrective_action_id);
        $this->assertNotNull($first->hs_corrective_action_id);
        $this->assertDatabaseCount('hs_recommendation_dispositions', 1);
        $this->assertDatabaseCount('hs_corrective_actions', 1);
        $this->assertDatabaseHas('hs_corrective_actions', [
            'id' => $first->hs_corrective_action_id,
            'hs_event_id' => $investigation->hs_event_id,
            'hs_investigation_id' => $investigation->id,
            'recommendation_index' => 0,
            'status' => HsCorrectiveAction::STATUS_OPEN,
        ]);
    }

    public function test_existing_corrective_action_must_be_linked_not_contradicted_by_a_non_action_outcome(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $investigation = HsInvestigation::factory()->completed()->create();
        app(HsCorrectiveActionService::class)->createFromRecommendation($investigation, 0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('corrective action');

        $this->service->dispositionRecommendation(
            $investigation,
            0,
            HsRecommendationDisposition::DISPOSITION_NO_ACTION,
            $actor,
            'No action proposed.',
        );
    }

    public function test_disposition_rejects_invalid_index_status_and_value(): void
    {
        $actor = User::factory()->create();
        $incomplete = HsInvestigation::factory()->withFindings()->create();

        foreach (
            [
                [$incomplete, 0, HsRecommendationDisposition::DISPOSITION_DUPLICATE, 'Complete the investigation first.'],
                [HsInvestigation::factory()->completed()->create(), 99, HsRecommendationDisposition::DISPOSITION_DUPLICATE, 'Missing recommendation.'],
                [HsInvestigation::factory()->completed()->create(), 0, 'ignored', 'Unsupported disposition.'],
            ] as [$investigation, $index, $disposition, $reason]
        ) {
            try {
                $this->service->dispositionRecommendation($investigation, $index, $disposition, $actor, $reason);
                $this->fail('Invalid recommendation disposition input must be rejected.');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $this->assertDatabaseCount('hs_recommendation_dispositions', 0);
    }

    public function test_authorised_endpoint_records_a_reasoned_recommendation_outcome(): void
    {
        $officer = $this->hsOfficer();
        $investigation = HsInvestigation::factory()->completed()->create();

        $this->actingAs($officer)
            ->from("/health-safety/events/{$investigation->hs_event_id}")
            ->post("/health-safety/events/{$investigation->hs_event_id}/investigations/{$investigation->id}/recommendations/1/disposition", [
                'disposition' => HsRecommendationDisposition::DISPOSITION_DUPLICATE,
                'reason' => 'This is already addressed by recommendation one.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('hs_recommendation_dispositions', [
            'hs_investigation_id' => $investigation->id,
            'recommendation_index' => 1,
            'disposition' => HsRecommendationDisposition::DISPOSITION_DUPLICATE,
            'decided_by_user_id' => $officer->id,
        ]);
    }

    public function test_endpoint_requires_manage_permission_and_a_reason_for_non_action_outcomes(): void
    {
        $investigation = HsInvestigation::factory()->completed()->create();
        $path = "/health-safety/events/{$investigation->hs_event_id}/investigations/{$investigation->id}/recommendations/0/disposition";

        $this->actingAs(User::factory()->create(['approved_at' => now()]))
            ->post($path, [
                'disposition' => HsRecommendationDisposition::DISPOSITION_ACCEPTED_RISK,
                'reason' => 'Unauthorised decision.',
            ])
            ->assertForbidden();

        $this->actingAs($this->hsOfficer())
            ->post($path, [
                'disposition' => HsRecommendationDisposition::DISPOSITION_ACCEPTED_RISK,
            ])
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('hs_recommendation_dispositions', 0);
    }

    public function test_event_detail_exposes_each_recommendation_decision_and_closure_override_capability(): void
    {
        $actor = $this->overrideOfficer();
        $investigation = HsInvestigation::factory()->completed()->create();

        $acceptedRisk = $this->service->dispositionRecommendation(
            $investigation,
            0,
            HsRecommendationDisposition::DISPOSITION_ACCEPTED_RISK,
            $actor,
            'The residual risk is within the approved tolerance.',
        );
        $correctiveAction = $this->service->dispositionRecommendation(
            $investigation->fresh(),
            1,
            HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION,
            $actor,
        );

        $this->actingAs($actor)
            ->get("/health-safety/events/{$investigation->hs_event_id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.can.override_closure', true)
                ->where(
                    'detail.investigations.0.recommendations.0.disposition.disposition',
                    HsRecommendationDisposition::DISPOSITION_ACCEPTED_RISK,
                )
                ->where(
                    'detail.investigations.0.recommendations.0.disposition.reason',
                    'The residual risk is within the approved tolerance.',
                )
                ->where(
                    'detail.investigations.0.recommendations.0.disposition.decided_by_name',
                    $actor->name,
                )
                ->where(
                    'detail.investigations.0.recommendations.1.disposition.disposition',
                    HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION,
                )
                ->where(
                    'detail.investigations.0.recommendations.1.disposition.corrective_action.id',
                    $correctiveAction->hs_corrective_action_id,
                )
            );

        $this->assertNotNull($acceptedRisk->decided_at);
    }

    private function hsOfficer(): User
    {
        $user = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        $role = Role::query()->where('name', 'health_safety_officer')->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }

    private function overrideOfficer(): User
    {
        $user = $this->hsOfficer();
        $permission = Permission::query()->where('key', 'healthSafety.overrideClosure')->firstOrFail();
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);

        return $user;
    }
}
