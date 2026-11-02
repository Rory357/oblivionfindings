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
 * general audit prune (2 years by default) leaves safety review evidence alone until it is older than 10 years, or
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
