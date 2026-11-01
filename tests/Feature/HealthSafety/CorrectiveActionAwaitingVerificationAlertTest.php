<?php

namespace Tests\Feature\HealthSafety;

use App\Jobs\CheckOverdueCorrectiveActionsJob;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomNotificationService;
use App\Services\HealthSafety\HsSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A corrective action the owner has marked completed, but nobody has verified yet, said "Corrective action overdue"
 * (MASTER for Stephan, 30 Oct). Its alert now says what is waiting, "Waiting for verification", and counts from when
 * it was completed. Nothing else changes: the same trigger (due date passed, not verified or closed), the same alert
 * type and key, and severity still counts from the due date.
 */
class CorrectiveActionAwaitingVerificationAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-20 18:00:00', 'UTC'));
        $notifications = $this->mock(ControlRoomNotificationService::class);
        $notifications->shouldReceive('notifyAlert')->andReturnNull();
        $notifications->shouldReceive('stageAlertNotifications')->andReturn(collect());
    }

    public function test_the_open_alert_switches_to_waiting_for_verification_once_the_action_is_completed(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $event = HsEvent::factory()->create(['site_id' => $site->id]);
        $owner = User::factory()->create();
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'status' => HsCorrectiveAction::STATUS_OPEN,
            'priority' => 'medium', 'due_date' => now()->subDays(3)->toDateString(), 'created_by' => $owner->id]);

        app(CheckOverdueCorrectiveActionsJob::class)->handle(app(HsSignalService::class));
        $alert = ControlRoomAlert::query()->where('alert_type', 'Hs Corrective Action Overdue')->sole();
        $this->assertStringStartsWith("Corrective action overdue: {$action->reference_number} (", data_get($alert->context, 'normalized_data.title'));
        $severityBefore = $alert->severity;

        // The owner marks it done; nobody has verified it yet.
        $action->forceFill(['status' => HsCorrectiveAction::STATUS_COMPLETED, 'completed_at' => now()])->save();
        $this->travel(2)->days();
        app(CheckOverdueCorrectiveActionsJob::class)->handle(app(HsSignalService::class));

        $this->assertSame(1, ControlRoomAlert::query()->where('alert_type', 'Hs Corrective Action Overdue')->count(), 'Same alert, not a new one.');
        $refreshed = $alert->fresh();
        $this->assertSame('open', $refreshed->status);
        $this->assertSame("Waiting for verification: {$action->reference_number} (completed 2 days ago, medium priority)",
            data_get($refreshed->context, 'normalized_data.title'));
        $this->assertSame(2, data_get($refreshed->context, 'normalized_data.days_since_completed'));
        $this->assertSame(5, data_get($refreshed->context, 'normalized_data.days_overdue'), 'Severity still counts from the due date.');
        $order = ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3];
        $this->assertGreaterThanOrEqual($order[$severityBefore] ?? 0, $order[$refreshed->severity] ?? 0, 'Nothing becomes less urgent.');

        // Verified: it is no longer waiting, and the check raises nothing for it.
        $action->forceFill(['status' => HsCorrectiveAction::STATUS_VERIFIED, 'verified_at' => now()])->save();
        $this->assertFalse(HsCorrectiveAction::query()->overdue()->whereKey($action->id)->exists());
    }

    public function test_the_title_counts_today_and_one_day_in_plain_words(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $signals = app(HsSignalService::class);
        $signals->emitCorrectiveActionOverdue(901, 'CA-901', 4, 'high', $site->id, daysSinceCompleted: 0);
        $signals->emitCorrectiveActionOverdue(902, 'CA-902', 4, 'high', $site->id, daysSinceCompleted: 1);
        $signals->emitCorrectiveActionOverdue(903, 'CA-903', 4, 'high', $site->id);

        $titles = ControlRoomAlert::query()->where('alert_type', 'Hs Corrective Action Overdue')->get()
            ->map(fn (ControlRoomAlert $alert) => data_get($alert->context, 'normalized_data.title'))->sort()->values()->all();
        $this->assertSame([
            'Corrective action overdue: CA-903 (4 days, high priority)',
            'Waiting for verification: CA-901 (completed today, high priority)',
            'Waiting for verification: CA-902 (completed 1 day ago, high priority)',
        ], $titles);
    }
}
