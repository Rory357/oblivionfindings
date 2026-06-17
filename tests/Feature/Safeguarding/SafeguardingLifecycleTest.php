<?php

namespace Tests\Feature\Safeguarding;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SafeguardingActionPlan;
use App\Models\SafeguardingConcern;
use App\Models\SafeguardingExternalReport;
use App\Models\SafeguardingInvestigation;
use App\Models\User;
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
        $this->assertStringContainsString('Override reason:', $concern->closure_summary);
    }

    public function test_close_from_reported_is_blocked(): void
    {
        $concern = SafeguardingConcern::factory()->create(['status' => 'reported']);

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
