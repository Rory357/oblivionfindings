<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Answer 20 (Stephan, 1 Nov 2026): safety review corrections and the activity log are kept at least 10 years. The
 * general audit prune (5 years by default) leaves safety review evidence alone until it is older than 10 years, or
 * older than the configured audit retention when that is longer.
 */
class HealthSafetyAuditRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_two_year_prune_keeps_safety_review_evidence_for_ten_years(): void
    {
        $event = HsEvent::factory()->create(['site_id' => Site::factory()->create()->id]);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id]);

        $generic = $this->row('settings.updated', null, null, 3);
        $eventRow = $this->row('event.updated', $event->getMorphClass(), $event->id, 3);
        $actionRow = $this->row('something.changed', $action->getMorphClass(), $action->id, 3);
        $hsAction = $this->row('healthSafety.correctiveAction.created', null, null, 3);
        $ancient = $this->row('healthSafety.event.closed', $event->getMorphClass(), $event->id, 11);

        $this->artisan('oblivion:prune-retention', ['--audit-years' => 2])->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $generic]);
        $this->assertDatabaseHas('audit_logs', ['id' => $eventRow]);
        $this->assertDatabaseHas('audit_logs', ['id' => $actionRow]);
        $this->assertDatabaseHas('audit_logs', ['id' => $hsAction]);
        // Older than 10 years: pruned.
        $this->assertDatabaseMissing('audit_logs', ['id' => $ancient]);
    }

    public function test_a_longer_configured_retention_still_wins(): void
    {
        $eleven = $this->row('healthSafety.event.closed', null, null, 11);

        $this->artisan('oblivion:prune-retention', ['--audit-years' => 12])->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', ['id' => $eleven]);
    }

    public function test_incident_restraint_safeguarding_and_hazard_history_is_kept_ten_years(): void
    {
        // Readiness audit B6 (Stephan, 6 Nov 2026: "10 years").
        $kept = [
            $this->row('clientincident.update', \App\Models\ClientIncident::class, 1, 3),
            $this->row('incidents.owner.assigned', null, null, 3),
            $this->row('restraintevent.update', (new \App\Models\RestraintEvent)->getMorphClass(), 1, 3),
            $this->row('restraints.plan.approved', null, null, 3),
            $this->row('safeguarding.triaged', \App\Models\SafeguardingConcern::class, 1, 3),
            $this->row('sitehazard.update', \App\Models\SiteHazard::class, 1, 3),
            $this->row('hazard.created', null, null, 3),
            $this->row('first_aid.recorded', null, null, 3),
        ];
        $generic = $this->row('settings.updated', null, null, 3);
        $lookalike = $this->row('firstXaid.recorded', null, null, 3);
        $ancient = $this->row('incidents.owner.assigned', null, null, 11);

        $this->artisan('oblivion:prune-retention', ['--audit-years' => 2])->assertSuccessful();

        foreach ($kept as $id) {
            $this->assertDatabaseHas('audit_logs', ['id' => $id]);
        }
        $this->assertDatabaseMissing('audit_logs', ['id' => $generic]);
        // LIKE wildcards are escaped: "firstXaid." is not "first_aid.".
        $this->assertDatabaseMissing('audit_logs', ['id' => $lookalike]);
        $this->assertDatabaseMissing('audit_logs', ['id' => $ancient]);
    }

    public function test_with_no_setting_the_audit_log_default_is_five_years(): void
    {
        $four = $this->row('settings.updated', null, null, 4);
        $six = $this->row('settings.updated', null, null, 6);

        $this->artisan('oblivion:prune-retention')->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', ['id' => $four]);
        $this->assertDatabaseMissing('audit_logs', ['id' => $six]);
    }

    public function test_a_forever_setting_prunes_nothing(): void
    {
        \App\Models\DataRetentionPolicy::query()->create(['model_type' => 'audit_logs', 'policy_name' => 'Audit logs',
            'retention_period_years' => null, 'retention_conditions' => ['setting_value' => 'forever'], 'active' => true]);
        $old = $this->row('settings.updated', null, null, 30);
        $oldHs = $this->row('incidents.owner.assigned', null, null, 30);

        $this->artisan('oblivion:prune-retention')->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', ['id' => $old]);
        $this->assertDatabaseHas('audit_logs', ['id' => $oldHs]);
    }

    private function row(string $action, ?string $type, ?int $id, int $yearsAgo): int
    {
        $at = now()->subYears($yearsAgo)->subDay();

        return (int) DB::table('audit_logs')->insertGetId([
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'meta' => json_encode([]),
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
