<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRecommendationDisposition;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\HealthSafety\HsEventService;
use App\Services\HealthSafety\HsInvestigationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gated event closure (E-Gap 1) — HsEventService::closeEvent() + the gate:
 * H&S acceptance, WorkSafe, investigation, recommendation and corrective-action
 * work must be complete. Only a separately authorised override can bypass blockers.
 */
class HsEventClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    protected function hsOfficer(): User
    {
        $user = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        if ($role = Role::where('name', 'health_safety_officer')->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    public function test_clean_event_closes_with_summary(): void
    {
        $actor = $this->hsOfficer();
        $event = HsEvent::factory()->worksafeNotNotifiable($actor)->create();

        $this->actingAs($actor)
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Resolved — controls verified, no further action.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $event->refresh();
        $this->assertEquals(HsEvent::STATUS_CLOSED, $event->status);
        $this->assertNotNull($event->closed_at);
        $this->assertNotNull($event->closed_by);
        $this->assertNotEmpty($event->closure_summary);
    }

    public function test_worksafe_closure_truth_matrix_and_direct_action_requirement(): void
    {
        $actor = $this->hsOfficer();
        $service = app(HsEventService::class);
        $events = [
            'unknown blocks' => [
                HsEvent::factory()->worksafeUndecided()->create(),
                false,
                'Record the WorkSafe notifiability decision before closing this event.',
                'worksafe-decision',
            ],
            'explicit false closes regulatory gate' => [
                HsEvent::factory()->worksafeNotNotifiable($actor)->create(),
                true,
                null,
                'worksafe-decision',
            ],
            'true pending blocks' => [
                HsEvent::factory()->worksafeNotifiable($actor)->create(),
                false,
                'Record the WorkSafe notification before closing this event.',
                'worksafe-notify',
            ],
            'true notified closes regulatory gate' => [
                HsEvent::factory()->worksafeNotifiable($actor)->create([
                    'worksafe_status' => HsEvent::WORKSAFE_NOTIFIED,
                    'worksafe_notified_at' => now(),
                    'worksafe_method' => 'online',
                ]),
                true,
                null,
                'worksafe-decision',
            ],
            'true acknowledged closes regulatory gate' => [
                HsEvent::factory()->worksafeNotifiable($actor)->create([
                    'worksafe_status' => HsEvent::WORKSAFE_ACKNOWLEDGED,
                    'worksafe_notified_at' => now()->subHour(),
                    'worksafe_method' => 'online',
                    'worksafe_acknowledged_at' => now(),
                ]),
                true,
                null,
                'worksafe-decision',
            ],
        ];

        foreach ($events as $label => [$event, $expected, $blocker, $action]) {
            $gate = $service->closureGate($event);
            $requirement = collect($gate['requirements'])->firstWhere('key', 'worksafe_decision');

            $this->assertSame($expected, $gate['worksafe_ok'], $label);
            $this->assertNotNull($requirement, $label);
            $this->assertSame($expected, $requirement['complete'], $label);
            $this->assertSame(
                "/health-safety/events/{$event->id}?action={$action}",
                $requirement['href'],
                $label,
            );

            if ($blocker !== null) {
                $this->assertContains($blocker, $gate['blockers'], $label);
            }
        }
    }

    public function test_close_blocked_when_required_investigation_incomplete(): void
    {
        $event = HsEvent::factory()->high()->create(); // investigation_required, none completed

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Trying to close.',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotEquals(HsEvent::STATUS_CLOSED, $event->fresh()->status);
    }

    public function test_incident_backed_event_cannot_close_before_handover_acceptance(): void
    {
        $sourceId = 991_001;
        $event = HsEvent::factory()->create([
            'source_type' => ClientIncident::class,
            'source_id' => $sourceId,
            'idempotency_key' => HsEvent::buildIdempotencyKey(ClientIncident::class, $sourceId, HsEvent::CATEGORY_INCIDENT),
            'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
        ]);

        $this->actingAs($this->hsOfficer())
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Trying to close before H&S accepts ownership.',
            ])
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Accept the H&S handover'));

        $this->assertNotSame(HsEvent::STATUS_CLOSED, $event->fresh()->status);
    }

    public function test_worksafe_pending_blocks_closure(): void
    {
        $event = HsEvent::factory()->worksafeNotifiable()->create([
            'investigation_required' => false,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);

        $this->actingAs($this->hsOfficer())
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Trying to close before WorkSafe notification.',
            ])
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'WorkSafe'));

        $this->assertNotSame(HsEvent::STATUS_CLOSED, $event->fresh()->status);
    }

    public function test_completed_recommendations_with_zero_actions_are_not_treated_as_resolved(): void
    {
        $event = HsEvent::factory()->high()->create([
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);
        HsInvestigation::factory()->completed()->create(['hs_event_id' => $event->id]);

        $this->assertFalse($event->fresh()->allCorrectiveActionsResolved());

        $this->actingAs($this->hsOfficer())
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Recommendations were never dispositioned.',
            ])
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'recommendation'));

        $this->assertNotSame(HsEvent::STATUS_CLOSED, $event->fresh()->status);
    }

    public function test_close_blocked_with_unverified_corrective_action(): void
    {
        $event = HsEvent::factory()->create();
        HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'status' => 'open']);

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Trying to close.',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotEquals(HsEvent::STATUS_CLOSED, $event->fresh()->status);
    }

    public function test_event_closes_after_every_recommendation_is_decided_and_linked_action_is_verified(): void
    {
        $actor = $this->hsOfficer();
        $event = HsEvent::factory()->high()->worksafeNotNotifiable($actor)->create([
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);
        $investigation = HsInvestigation::factory()->completed()->create([
            'hs_event_id' => $event->id,
        ]);
        $service = app(HsInvestigationService::class);

        $correctiveOutcome = $service->dispositionRecommendation(
            $investigation,
            0,
            HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION,
            $actor,
        );
        $service->dispositionRecommendation(
            $investigation->fresh(),
            1,
            HsRecommendationDisposition::DISPOSITION_ACCEPTED_RISK,
            $actor,
            'The remaining risk is within the approved tolerance.',
        );

        HsCorrectiveAction::query()
            ->findOrFail($correctiveOutcome->hs_corrective_action_id)
            ->update([
                'status' => HsCorrectiveAction::STATUS_VERIFIED,
                'verified_by_user_id' => $actor->id,
                'verified_at' => now(),
                'effectiveness_confirmed' => true,
            ]);

        $this->actingAs($actor)
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Investigation decisions and corrective controls have been verified.',
            ])
            ->assertSessionHas('success');

        $this->assertSame(HsEvent::STATUS_CLOSED, $event->fresh()->status);
    }

    public function test_new_active_investigation_blocks_closure_after_an_earlier_investigation_was_completed(): void
    {
        $event = HsEvent::factory()->high()->create([
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);
        $completed = HsInvestigation::factory()->completed()->create([
            'hs_event_id' => $event->id,
        ]);
        $actor = $this->hsOfficer();
        $service = app(HsInvestigationService::class);

        foreach (array_keys($completed->recommendations ?? []) as $index) {
            $service->dispositionRecommendation(
                $completed,
                (int) $index,
                HsRecommendationDisposition::DISPOSITION_NO_ACTION,
                $actor,
                'No additional action is required after the verified control review.',
            );
        }

        HsInvestigation::factory()->create(['hs_event_id' => $event->id]);

        $this->actingAs($actor)
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'An active re-investigation still exists.',
            ])
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'active H&S investigation'));

        $this->assertNotSame(HsEvent::STATUS_CLOSED, $event->fresh()->status);
    }

    public function test_override_reason_alone_never_authorises_closure(): void
    {
        $event = HsEvent::factory()->high()->create();

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Closing with override.',
                'override_reason' => 'Investigation handled at board level; minuted outside the system.',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotEquals(HsEvent::STATUS_CLOSED, $event->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'healthSafety.event.closureOverridden',
            'auditable_id' => $event->id,
        ]);
    }

    public function test_override_permission_requires_a_reason(): void
    {
        $event = HsEvent::factory()->high()->create();

        $this->actingAs($this->overrideOfficer())
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Trying to override without explaining why.',
            ])
            ->assertSessionHas('error');

        $this->assertNotEquals(HsEvent::STATUS_CLOSED, $event->fresh()->status);
    }

    public function test_authorised_override_records_actor_reason_and_exact_blockers(): void
    {
        $event = HsEvent::factory()->high()->create();
        $actor = $this->overrideOfficer();

        $this->actingAs($actor)
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'Closing under the documented executive exception process.',
                'override_reason' => 'Immediate statutory direction requires closure before the internal investigation is recorded.',
            ])
            ->assertSessionHas('success');

        $this->assertEquals(HsEvent::STATUS_CLOSED, $event->fresh()->status);
        $audit = AuditLog::query()
            ->where('action', 'healthSafety.event.closureOverridden')
            ->where('auditable_type', $event->getMorphClass())
            ->where('auditable_id', $event->id)
            ->firstOrFail();

        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame(
            'Immediate statutory direction requires closure before the internal investigation is recorded.',
            $audit->meta['override_reason'],
        );
        $this->assertNotEmpty($audit->meta['blockers']);
        $this->assertStringContainsString('investigation', implode(' ', $audit->meta['blockers']));
    }

    public function test_close_requires_a_summary(): void
    {
        $event = HsEvent::factory()->create();

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/close", [])
            ->assertSessionHasErrors('closure_summary');

        $this->assertNotEquals(HsEvent::STATUS_CLOSED, $event->fresh()->status);
    }

    public function test_close_requires_hazards_manage(): void
    {
        $event = HsEvent::factory()->create();
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->post("/health-safety/events/{$event->id}/close", [
                'closure_summary' => 'No permission.',
            ])
            ->assertForbidden();
    }

    public function test_rbac_defines_override_permission_without_granting_it_to_the_standard_hs_officer(): void
    {
        $permission = Permission::query()->where('key', 'healthSafety.overrideClosure')->first();
        $role = Role::query()->where('name', 'health_safety_officer')->firstOrFail();

        $this->assertNotNull($permission);
        $this->assertFalse($role->permissions()->whereKey($permission->id)->exists());
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
