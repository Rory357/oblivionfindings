<?php

namespace Tests\Feature\HealthSafety;

use App\Jobs\CheckOverdueCorrectiveActionsJob;
use App\Jobs\CheckOverdueInvestigationsJob;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Overdue checks skip work under a soft-deleted H&S event (MASTER for Stephan, 30 Oct): a deleted test event kept a
 * daily "Corrective action overdue" alert going. Work under a live event still alerts.
 */
class OverdueChecksSkipRetiredEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_overdue_actions_under_a_soft_deleted_event_raise_no_alert_but_live_ones_still_do(): void
    {
        [$live, $retired] = $this->events();
        $owner = User::factory()->create();
        $liveAction = HsCorrectiveAction::factory()->create(['hs_event_id' => $live->id, 'status' => HsCorrectiveAction::STATUS_OPEN,
            'due_date' => now()->subDays(5), 'created_by' => $owner->id]);
        $retiredAction = HsCorrectiveAction::factory()->create(['hs_event_id' => $retired->id, 'status' => HsCorrectiveAction::STATUS_OPEN,
            'due_date' => now()->subDays(5), 'created_by' => $owner->id]);
        $retired->delete();

        $alerted = [];
        $signals = Mockery::mock(HsSignalService::class);
        $signals->shouldReceive('emitCorrectiveActionOverdue')->andReturnUsing(function (int $id) use (&$alerted) { $alerted[] = $id; });
        (new CheckOverdueCorrectiveActionsJob)->handle($signals);

        $this->assertContains($liveAction->id, $alerted);
        $this->assertNotContains($retiredAction->id, $alerted);
    }

    public function test_overdue_investigations_under_a_soft_deleted_event_raise_no_alert_but_live_ones_still_do(): void
    {
        [$live, $retired] = $this->events();
        $lead = User::factory()->create();
        $liveInvestigation = HsInvestigation::factory()->create(['hs_event_id' => $live->id, 'status' => HsInvestigation::STATUS_IN_PROGRESS,
            'target_completion_date' => now()->subDays(5), 'lead_investigator_id' => $lead->id, 'created_by' => $lead->id]);
        $retiredInvestigation = HsInvestigation::factory()->create(['hs_event_id' => $retired->id, 'status' => HsInvestigation::STATUS_IN_PROGRESS,
            'target_completion_date' => now()->subDays(5), 'lead_investigator_id' => $lead->id, 'created_by' => $lead->id]);
        $retired->delete();

        $alerted = [];
        $signals = Mockery::mock(HsSignalService::class);
        $signals->shouldReceive('emitInvestigationOverdue')->andReturnUsing(function (int $id) use (&$alerted) { $alerted[] = $id; });
        (new CheckOverdueInvestigationsJob)->handle($signals);

        $this->assertContains($liveInvestigation->id, $alerted);
        $this->assertNotContains($retiredInvestigation->id, $alerted);
    }

    /** @return array{0: HsEvent, 1: HsEvent} */
    private function events(): array
    {
        $site = Site::factory()->create(['is_active' => true]);

        return [
            HsEvent::factory()->create(['site_id' => $site->id]),
            HsEvent::factory()->create(['site_id' => $site->id]),
        ];
    }
}
