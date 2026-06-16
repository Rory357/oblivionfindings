<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Models\Client;
use App\Models\EmergencyDrill;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G5/G6 — worklist row builders + unified expiring feed on HsDashboardService.
 */
class HsDashboardWorklistTest extends TestCase
{
    use RefreshDatabase;

    private HsDashboardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(HsDashboardService::class);
    }

    public function test_overdue_corrective_actions_carry_event_client_and_staff_ids(): void
    {
        $client = Client::factory()->create();
        $staff = User::factory()->create();
        $owner = User::factory()->create();
        $site = Site::factory()->create();

        $event = HsEvent::factory()->create([
            'client_id' => $client->id,
            'staff_id' => $staff->id,
            'site_id' => $site->id,
        ]);
        HsCorrectiveAction::factory()->overdue()->create([
            'hs_event_id' => $event->id,
            'assigned_to_user_id' => $owner->id,
        ]);

        $rows = $this->svc->overdueCorrectiveActions();

        $this->assertCount(1, $rows);
        $this->assertEquals($client->id, $rows[0]['client_id']);
        $this->assertEquals($staff->id, $rows[0]['staff_id']);
        $this->assertEquals($owner->name, $rows[0]['owner']);
        $this->assertGreaterThanOrEqual(1, $rows[0]['days_overdue']);
    }

    public function test_overdue_corrective_actions_are_site_scoped(): void
    {
        $maple = Site::factory()->create();
        $rata = Site::factory()->create();

        $mapleEvent = HsEvent::factory()->create(['site_id' => $maple->id]);
        $rataEvent = HsEvent::factory()->create(['site_id' => $rata->id]);
        HsCorrectiveAction::factory()->overdue()->create(['hs_event_id' => $mapleEvent->id]);
        HsCorrectiveAction::factory()->overdue()->create(['hs_event_id' => $rataEvent->id]);

        $this->assertCount(2, $this->svc->overdueCorrectiveActions());
        $this->assertCount(1, $this->svc->overdueCorrectiveActions($maple->id));
    }

    public function test_open_investigations_flag_overdue_and_exclude_completed(): void
    {
        $event = HsEvent::factory()->create();

        HsInvestigation::factory()->inProgress()->create([
            'hs_event_id' => $event->id,
            'target_completion_date' => now()->subDays(3),
        ]);
        HsInvestigation::factory()->inProgress()->create([
            'hs_event_id' => $event->id,
            'target_completion_date' => now()->addDays(10),
        ]);
        HsInvestigation::factory()->completed()->create(['hs_event_id' => $event->id]);

        $rows = $this->svc->openInvestigations();

        $this->assertCount(2, $rows); // completed excluded
        // Overdue (null target sorts last; earliest target first) → first row is the overdue one.
        $this->assertTrue($rows[0]['is_overdue']);
        $this->assertFalse($rows[1]['is_overdue']);
    }

    public function test_notifiable_events_list_pending_first_and_exclude_closed(): void
    {
        $submitter = User::factory()->create();

        $this->makeNotifiable($submitter, 'notified', now()->subDays(2));
        $this->makeNotifiable($submitter, 'pending', now()->subDay());
        $closed = $this->makeNotifiable($submitter, 'closed', now()->subDays(5));
        $closed->update(['closed_at' => now()]);

        $rows = $this->svc->notifiableEvents();

        $this->assertCount(2, $rows); // closed excluded
        $this->assertEquals('pending', $rows[0]['status']); // awaiting notification first
    }

    public function test_expiring_feed_unifies_sources_and_sorts_by_due_date(): void
    {
        $site = Site::factory()->create();

        HsRiskAssessment::factory()->active()->create([
            'review_due_at' => now()->addDays(10),
        ]);
        EmergencyDrill::factory()->create([
            'site_id' => $site->id,
            'drill_type' => 'fire',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDays(5),
        ]);

        $feed = $this->svc->expiringFeed();

        $this->assertCount(2, $feed);
        // Sorted ascending by due date → drill (+5) before risk review (+10).
        $this->assertEquals('drill', $feed[0]['type']);
        $this->assertEquals('risk_assessment', $feed[1]['type']);
        $this->assertGreaterThan(0, $feed[0]['days_until']);
    }

    public function test_expiring_feed_marks_overdue_items_negative(): void
    {
        HsRiskAssessment::factory()->active()->create([
            'review_due_at' => now()->subDays(4),
        ]);

        $feed = $this->svc->expiringFeed();

        $this->assertCount(1, $feed);
        $this->assertLessThan(0, $feed[0]['days_until']);
    }

    private function makeNotifiable(User $submitter, string $status, \Carbon\CarbonInterface $occurredAt): NotifiableIncident
    {
        return NotifiableIncident::create([
            'incident_type' => 'serious_harm',
            'notification_authority' => 'worksafe',
            'title' => 'Notifiable '.$status,
            'description' => 'Test notifiable event.',
            'severity' => 'high',
            'status' => $status,
            'occurred_at' => $occurredAt,
            'notified_at' => $status === 'notified' ? now() : null,
            'submitted_by' => $submitter->id,
        ]);
    }
}
