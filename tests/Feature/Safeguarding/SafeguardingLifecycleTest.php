<?php

namespace Tests\Feature\Safeguarding;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SafeguardingActionPlan;
use App\Models\SafeguardingConcern;
use App\Models\SafeguardingExternalReport;
use App\Models\SafeguardingInvestigation;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsEventClosureService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Safeguarding redesign — Step 2 (enforced lifecycle + triage).
 *
 * Covers the §4 state machine: triage paths (W4), the investigation gate (W3),
 * the external-report gate (W6), the closure soft-block (W7), and the rule that
 * a `reported` concern can only move via triage.
 */
class SafeguardingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->user = $this->makeSafeguardingUser(['safeguarding.viewAny', 'safeguarding.update']);
    }

    private function makeSafeguardingUser(array $permissionKeys): User
    {
        $user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);

        $adminRole = Role::query()->where('name', 'admin')->first();
        if ($adminRole) {
            $user->roles()->syncWithoutDetaching([$adminRole->id]);
        }

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                ['description' => str_replace('.', ' ', $permissionKey), 'group' => explode('.', $permissionKey)[0]],
            );
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }

        return $user;
    }

    private function openInvestigation(SafeguardingConcern $concern, string $status = 'in_progress'): SafeguardingInvestigation
    {
        return SafeguardingInvestigation::query()->create([
            'safeguarding_concern_id' => $concern->id,
            'investigation_type' => 'internal',
            'lead_investigator_id' => $this->user->id,
            'started_at' => now()->subDay(),
            'status' => $status,
            'created_by' => $this->user->id,
        ]);
    }

    private function makeTerminalJourneyReady(SafeguardingConcern $concern): void
    {
        $siteId = $concern->site_id ?: Site::factory()->create()->id;
        $concern->forceFill(['site_id' => $siteId])->save();
        $key = HsEvent::buildIdempotencyKey(
            SafeguardingConcern::class,
            $concern->id,
            HsEvent::CATEGORY_SAFEGUARDING,
        );
        $event = HsEvent::query()->where('idempotency_key', $key)->first()
            ?? HsEvent::factory()->create([
                'source_type' => SafeguardingConcern::class,
                'source_id' => $concern->id,
                'event_category' => HsEvent::CATEGORY_SAFEGUARDING,
                'idempotency_key' => $key,
                'site_id' => $siteId,
            ]);
        $alert = $event->control_room_alert_id
            ? ControlRoomAlert::query()->findOrFail($event->control_room_alert_id)
            : ControlRoomAlert::factory()->create([
                'site_id' => $siteId,
                'source' => 'safeguarding',
                'context' => ['concern_id' => $concern->id],
            ]);
        $context = $alert->context ?? [];
        $context['concern_id'] = $concern->id;
        $alert->forceFill([
            'site_id' => $siteId,
            'status' => ControlRoomAlert::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolved_by_user_id' => $this->user->id,
            'resolution_code' => 'safeguarding_response_complete',
            'context' => $context,
        ])->save();
        $actor = $this->grantHsClosureAuthority($this->user, $siteId);
        $event->forceFill([
            'site_id' => $siteId,
            'control_room_alert_id' => $alert->id,
            'status' => HsEvent::STATUS_OPEN,
            'owner_user_id' => $actor->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
            'investigation_required' => false,
            'worksafe_notifiable' => false,
            'worksafe_decided_at' => now(),
            'worksafe_decided_by_user_id' => $actor->id,
            'worksafe_decision_reason' => 'Assessed as not meeting the WorkSafe notification threshold.',
            'worksafe_decision_source' => 'manual',
            'worksafe_status' => null,
        ])->save();
        $this->actingAs($actor);
        app(HsEventClosureService::class)->closeEvent(
            $event->fresh(),
            'H&S safeguarding governance completed.',
            $actor,
        );
    }

    private function grantHsClosureAuthority(User $actor, ?int $siteId): User
    {
        $role = Role::query()->where('name', 'health_safety_officer')->firstOrFail();
        $actor->roles()->syncWithoutDetaching([$role->id]);
        if (! HrEmployeeProfile::query()->where('user_id', $actor->id)->exists()) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $actor->id,
                'primary_site_id' => $siteId,
                'secondary_site_ids' => [],
                'position_role' => 'health_safety_officer',
            ]);
        }

        return $actor->fresh();
    }

    /* ---------------------------------------------------------------- */
    /*  Transition guards */
    /* ---------------------------------------------------------------- */

    public function test_reported_concern_cannot_be_advanced_via_update_status(): void
    {
        $concern = SafeguardingConcern::factory()->create(['status' => 'reported']);

        $this->actingAs($this->user)
            ->patch("/safeguarding/{$concern->id}/status", ['status' => 'investigating'])
            ->assertSessionHasErrors('status');

        $this->assertSame('reported', $concern->fresh()->status);
    }

    public function test_entering_investigating_requires_an_investigation(): void
    {
        $concern = SafeguardingConcern::factory()->create(['status' => 'triaged']);

        // No investigation yet → blocked.
        $this->actingAs($this->user)
            ->patch("/safeguarding/{$concern->id}/status", ['status' => 'investigating'])
            ->assertSessionHasErrors('status');
        $this->assertSame('triaged', $concern->fresh()->status);

        // With an open investigation → allowed.
        $this->openInvestigation($concern);
        $this->actingAs($this->user)
            ->patch("/safeguarding/{$concern->id}/status", ['status' => 'investigating'])
            ->assertRedirect();
        $this->assertSame('investigating', $concern->fresh()->status);
    }

    public function test_entering_referred_external_requires_a_report(): void
    {
        $concern = SafeguardingConcern::factory()->create(['status' => 'triaged']);

        $this->actingAs($this->user)
            ->patch("/safeguarding/{$concern->id}/status", ['status' => 'referred_external'])
            ->assertSessionHasErrors('status');
        $this->assertSame('triaged', $concern->fresh()->status);

        SafeguardingExternalReport::query()->create([
            'safeguarding_concern_id' => $concern->id,
            'authority_type' => 'police',
            'authority_name' => 'NZ Police',
            'reported_at' => now(),
            'reported_by_user_id' => $this->user->id,
            'report_method' => 'phone',
            'report_summary' => 'Notified.',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->patch("/safeguarding/{$concern->id}/status", ['status' => 'referred_external'])
            ->assertRedirect();
        $this->assertSame('referred_external', $concern->fresh()->status);
    }

    public function test_update_status_rejects_illegal_jump(): void
    {
        // monitoring may only go back to action_plan (or close via @close).
        $concern = SafeguardingConcern::factory()->create(['status' => 'monitoring']);

        $this->actingAs($this->user)
            ->patch("/safeguarding/{$concern->id}/status", ['status' => 'investigating'])
            ->assertSessionHasErrors('status');

        $this->assertSame('monitoring', $concern->fresh()->status);
    }

    public function test_update_status_cannot_close(): void
    {
        $concern = SafeguardingConcern::factory()->create(['status' => 'monitoring']);

        $this->actingAs($this->user)
            ->patch("/safeguarding/{$concern->id}/status", ['status' => 'closed'])
            ->assertSessionHasErrors('status');

        $this->assertSame('monitoring', $concern->fresh()->status);
    }

    /* ---------------------------------------------------------------- */
    /*  Triage (W4) */
    /* ---------------------------------------------------------------- */

    public function test_triage_investigate_path_opens_investigation_and_advances(): void
    {
        $concern = SafeguardingConcern::factory()->create(['status' => 'reported']);
        $lead = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($this->user)
            ->post("/safeguarding/{$concern->id}/triage", [
                'substantiation' => 'needs_enquiry',
                'initial_risk' => 'high',
                'lead_user_id' => $lead->id,
                'path' => 'investigate',
            ])
            ->assertRedirect();

        $concern->refresh();
        $this->assertSame('investigating', $concern->status);
        $this->assertSame('high', $concern->current_risk_level);
        $this->assertSame($lead->id, $concern->assigned_to_user_id);
        $this->assertSame('needs_enquiry', $concern->triage_substantiation);
        $this->assertSame('investigate', $concern->triage_decision);
        $this->assertNotNull($concern->triaged_at);
        $this->assertSame(1, $concern->investigations()->count());
    }

    public function test_triage_refer_path_flags_referral_and_stays_triaged(): void
    {
        $concern = SafeguardingConcern::factory()->create(['status' => 'reported']);

        $this->actingAs($this->user)
            ->post("/safeguarding/{$concern->id}/triage", [
                'substantiation' => 'substantiated',
                'initial_risk' => 'critical',
                'path' => 'refer',
            ])
            ->assertRedirect();

        $concern->refresh();
        $this->assertSame('triaged', $concern->status);
        $this->assertTrue((bool) $concern->requires_external_referral);
    }

    public function test_triage_no_action_path_requires_rationale(): void
    {
        $concern = SafeguardingConcern::factory()->create(['status' => 'reported']);

        $this->actingAs($this->user)
            ->post("/safeguarding/{$concern->id}/triage", [
                'substantiation' => 'not_substantiated',
                'initial_risk' => 'low',
                'path' => 'no_action',
            ])
            ->assertSessionHasErrors('notes');
        $this->assertSame('reported', $concern->fresh()->status);

        $this->makeTerminalJourneyReady($concern);

        $this->actingAs($this->user)
            ->post("/safeguarding/{$concern->id}/triage", [
                'substantiation' => 'not_substantiated',
                'initial_risk' => 'low',
                'path' => 'no_action',
                'notes' => 'Managed through normal wellbeing support.',
            ])
            ->assertRedirect();

        $concern->refresh();
        $this->assertSame('no_action_required', $concern->status);
        $this->assertSame('Managed through normal wellbeing support.', $concern->triage_notes);
    }

    public function test_triage_only_allowed_from_reported(): void
    {
        $concern = SafeguardingConcern::factory()->create(['status' => 'triaged']);

        $this->actingAs($this->user)
            ->post("/safeguarding/{$concern->id}/triage", [
                'substantiation' => 'substantiated',
                'initial_risk' => 'medium',
                'path' => 'investigate',
            ])
            ->assertSessionHasErrors('triage');
    }

    /* ---------------------------------------------------------------- */
    /*  Closure gate (W7) */
    /* ---------------------------------------------------------------- */

    public function test_close_is_soft_blocked_with_open_work_without_override(): void
    {
        $concern = SafeguardingConcern::factory()->create(['status' => 'investigating']);
        SafeguardingActionPlan::query()->create([
            'safeguarding_concern_id' => $concern->id,
            'action_description' => 'Outstanding protective action.',
            'action_type' => 'protective_measure',
            'status' => 'in_progress',
            'priority' => 2,
            'created_by' => $this->user->id,
        ]);
        $this->makeTerminalJourneyReady($concern);

        $this->actingAs($this->user)
            ->post("/safeguarding/{$concern->id}/close", [
                'closure_summary' => 'Wrapping up.',
            ])
            ->assertSessionHasErrors('override_reason');
        $this->assertSame('investigating', $concern->fresh()->status);

        $this->actingAs($this->user)
            ->post("/safeguarding/{$concern->id}/close", [
                'closure_summary' => 'Wrapping up.',
                'override_reason' => 'Action transferred to the care plan; safe to close.',
            ])
            ->assertRedirect();

        $concern->refresh();
        $this->assertSame('closed', $concern->status);
        $this->assertSame('Wrapping up.', $concern->closure_summary);
        $this->assertSame(
            'Action transferred to the care plan; safe to close.',
            $concern->terminalTransition()->firstOrFail()->override_reason,
        );
    }

    public function test_close_from_reported_is_blocked(): void
    {
        $concern = SafeguardingConcern::factory()->create(['status' => 'reported']);
        $this->makeTerminalJourneyReady($concern);

        $this->actingAs($this->user)
            ->post("/safeguarding/{$concern->id}/close", [
                'closure_summary' => 'Nothing to do.',
            ])
            ->assertSessionHasErrors('close');

        $this->assertSame('reported', $concern->fresh()->status);
    }

    public function test_close_succeeds_when_no_open_work(): void
    {
        $concern = SafeguardingConcern::factory()->create(['status' => 'monitoring']);
        $this->makeTerminalJourneyReady($concern);

        $this->actingAs($this->user)
            ->post("/safeguarding/{$concern->id}/close", [
                'closure_summary' => 'Resolved and monitored; safe to close.',
                'lessons_learned' => 'Refresh staff training.',
            ])
            ->assertRedirect();

        $concern->refresh();
        $this->assertSame('closed', $concern->status);
        $this->assertNotNull($concern->closed_at);
        $this->assertSame($this->user->id, $concern->closed_by_user_id);
    }

    public function test_close_is_soft_blocked_when_referral_indicated_but_unlogged(): void
    {
        // No open investigations/actions, but a referral was indicated and never logged.
        $concern = SafeguardingConcern::factory()->create([
            'status' => 'monitoring',
            'requires_external_referral' => true,
        ]);
        $this->makeTerminalJourneyReady($concern);

        $this->actingAs($this->user)
            ->post("/safeguarding/{$concern->id}/close", ['closure_summary' => 'Closing.'])
            ->assertSessionHasErrors('override_reason');
        $this->assertSame('monitoring', $concern->fresh()->status);

        $this->actingAs($this->user)
            ->post("/safeguarding/{$concern->id}/close", [
                'closure_summary' => 'Closing.',
                'override_reason' => 'Referral handled verbally with the duty officer; logging not required.',
            ])
            ->assertRedirect();
        $this->assertSame('closed', $concern->fresh()->status);
    }
}
