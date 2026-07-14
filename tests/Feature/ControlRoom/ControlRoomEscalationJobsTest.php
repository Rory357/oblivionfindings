<?php

namespace Tests\Feature\ControlRoom;

use App\Jobs\AutoEscalateControlRoomQueues;
use App\Jobs\CheckControlRoomSlaBreaches;
use App\Models\AuditLog;
use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Services\ControlRoom\AlertAutomationService;
use App\Services\ControlRoom\ControlRoomNotificationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ControlRoomEscalationJobsTest extends TestCase
{
    use RefreshDatabase;

    private int $queueFixtureSequence = 0;

    public function test_dismissed_alerts_are_ignored_by_breach_and_queue_escalation_jobs(): void
    {
        [$alert, $sla, $fromQueue] = $this->makeOverdueAlert(ControlRoomAlert::STATUS_DISMISSED);

        $notifications = Mockery::mock(ControlRoomNotificationService::class);
        $notifications->shouldNotReceive('notifySlaBreachEscalation');
        $notifications->shouldNotReceive('notifyQueueEscalation');
        $automation = Mockery::mock(AlertAutomationService::class);
        $automation->shouldNotReceive('onAlertEscalated');

        app(CheckControlRoomSlaBreaches::class)->handle($notifications, $automation);
        app(AutoEscalateControlRoomQueues::class)->handle($notifications, $automation);

        $alert->refresh();
        $sla->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_DISMISSED, $alert->status);
        $this->assertSame($fromQueue->id, $alert->queue_id);
        $this->assertSame(0, (int) $alert->escalation_level);
        $this->assertFalse($sla->acknowledge_breached);
        $this->assertFalse($sla->response_breached);
        $this->assertFalse($sla->resolution_breached);
        $this->assertNull(AlertQueue::query()->where('alert_id', $alert->id)->value('exited_at'));
    }

    public function test_sla_breach_escalation_rolls_back_when_its_required_notification_fails(): void
    {
        [$alert, $sla] = $this->makeOverdueAlert();
        $notifications = Mockery::mock(ControlRoomNotificationService::class);
        $notifications->shouldReceive('notifySlaBreachEscalation')
            ->once()
            ->andThrow(new RuntimeException('notification unavailable'));
        $automation = Mockery::mock(AlertAutomationService::class);
        $automation->shouldNotReceive('onAlertEscalated');

        try {
            app(CheckControlRoomSlaBreaches::class)->handle($notifications, $automation);
            $this->fail('The failed notification must fail the atomic escalation attempt.');
        } catch (RuntimeException $exception) {
            $this->assertSame('notification unavailable', $exception->getMessage());
        }

        $alert->refresh();
        $sla->refresh();
        $this->assertSame(0, (int) $alert->escalation_level);
        $this->assertNull($alert->escalated_at);
        $this->assertFalse($sla->acknowledge_breached);
        $this->assertFalse($sla->response_breached);
        $this->assertFalse($sla->resolution_breached);
    }

    public function test_queue_escalation_rolls_back_when_its_required_notification_fails(): void
    {
        [$alert, , $fromQueue, $toQueue] = $this->makeOverdueAlert();
        $history = AlertQueue::query()
            ->where('alert_id', $alert->id)
            ->where('queue_id', $fromQueue->id)
            ->firstOrFail();
        $notifications = Mockery::mock(ControlRoomNotificationService::class);
        $notifications->shouldReceive('notifyQueueEscalation')
            ->once()
            ->andThrow(new RuntimeException('queue notification unavailable'));
        $automation = Mockery::mock(AlertAutomationService::class);
        $automation->shouldNotReceive('onAlertEscalated');

        try {
            app(AutoEscalateControlRoomQueues::class)->handle($notifications, $automation);
            $this->fail('The failed notification must fail the atomic queue move.');
        } catch (RuntimeException $exception) {
            $this->assertSame('queue notification unavailable', $exception->getMessage());
        }

        $alert->refresh();
        $this->assertSame($fromQueue->id, $alert->queue_id);
        $this->assertSame(0, (int) $alert->escalation_level);
        $this->assertNull($history->fresh()->exited_at);
        $this->assertSame(
            0,
            AlertQueue::query()
                ->where('alert_id', $alert->id)
                ->where('queue_id', $toQueue->id)
                ->count(),
        );
    }

    public function test_sla_breach_escalation_rolls_back_when_its_required_audit_write_fails(): void
    {
        [$alert, $sla] = $this->makeOverdueAlert();
        $notifications = Mockery::mock(ControlRoomNotificationService::class);
        $notifications->shouldReceive('notifySlaBreachEscalation')->once();
        $automation = Mockery::mock(AlertAutomationService::class);
        $automation->shouldReceive('onAlertEscalated')->once();
        $eventName = 'eloquent.creating: '.AuditLog::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('Simulated SLA escalation audit failure.');
        });
        $caught = null;

        try {
            app(CheckControlRoomSlaBreaches::class)->handle($notifications, $automation);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            Event::forget($eventName);
        }

        $alert->refresh();
        $sla->refresh();

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame('Simulated SLA escalation audit failure.', $caught?->getMessage());
        $this->assertSame(0, (int) $alert->escalation_level);
        $this->assertNull($alert->escalated_at);
        $this->assertFalse($sla->acknowledge_breached);
        $this->assertFalse($sla->response_breached);
        $this->assertFalse($sla->resolution_breached);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'controlRoom.alert.slaBreached',
            'auditable_id' => $alert->id,
        ]);
    }

    public function test_queue_escalation_rolls_back_when_its_required_audit_write_fails(): void
    {
        [$alert, , $fromQueue, $toQueue] = $this->makeOverdueAlert();
        $history = AlertQueue::query()
            ->where('alert_id', $alert->id)
            ->where('queue_id', $fromQueue->id)
            ->firstOrFail();
        $notifications = Mockery::mock(ControlRoomNotificationService::class);
        $notifications->shouldReceive('notifyQueueEscalation')->once();
        $automation = Mockery::mock(AlertAutomationService::class);
        $automation->shouldReceive('onAlertEscalated')->once();
        $eventName = 'eloquent.creating: '.AuditLog::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('Simulated queue escalation audit failure.');
        });
        $caught = null;

        try {
            app(AutoEscalateControlRoomQueues::class)->handle($notifications, $automation);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            Event::forget($eventName);
        }

        $alert->refresh();

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame('Simulated queue escalation audit failure.', $caught?->getMessage());
        $this->assertSame($fromQueue->id, $alert->queue_id);
        $this->assertSame(0, (int) $alert->escalation_level);
        $this->assertNull($alert->escalated_at);
        $this->assertNull($history->fresh()->exited_at);
        $this->assertSame(0, AlertQueue::query()
            ->where('alert_id', $alert->id)
            ->where('queue_id', $toQueue->id)
            ->count());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'controlRoom.alert.autoEscalate',
            'auditable_id' => $alert->id,
        ]);
    }

    public function test_queue_escalation_uses_active_assignment_dwell_instead_of_original_alert_age(): void
    {
        [$alert, , $fromQueue, $toQueue] = $this->makeOverdueAlert();
        AlertQueue::query()
            ->where('alert_id', $alert->id)
            ->where('queue_id', $fromQueue->id)
            ->whereNull('exited_at')
            ->update(['entered_at' => now()->subMinute()]);
        $notifications = Mockery::mock(ControlRoomNotificationService::class);
        $notifications->shouldNotReceive('notifyQueueEscalation');
        $automation = Mockery::mock(AlertAutomationService::class);
        $automation->shouldNotReceive('onAlertEscalated');

        app(AutoEscalateControlRoomQueues::class)->handle($notifications, $automation);

        $alert->refresh();
        $this->assertSame($fromQueue->id, $alert->queue_id);
        $this->assertSame(0, (int) $alert->escalation_level);
        $this->assertSame(0, AlertQueue::query()
            ->where('alert_id', $alert->id)
            ->where('queue_id', $toQueue->id)
            ->count());
    }

    public function test_one_job_run_cannot_cascade_an_alert_through_multiple_queues(): void
    {
        $firstQueue = TriageQueue::query()->create([
            'name' => 'Cascade tier one',
            'code' => 'cascade-tier-one',
            'tier' => 1,
            'auto_escalate_after_minutes' => 5,
            'is_active' => true,
        ]);
        $secondQueue = TriageQueue::query()->create([
            'name' => 'Cascade tier two',
            'code' => 'cascade-tier-two',
            'tier' => 2,
            'auto_escalate_after_minutes' => 5,
            'is_active' => true,
        ]);
        $thirdQueue = TriageQueue::query()->create([
            'name' => 'Cascade tier three',
            'code' => 'cascade-tier-three',
            'tier' => 3,
            'is_active' => true,
        ]);
        $firstQueue->update(['escalate_to_queue_id' => $secondQueue->id]);
        $secondQueue->update(['escalate_to_queue_id' => $thirdQueue->id]);
        $alert = ControlRoomAlert::factory()->open()->create([
            'queue_id' => $firstQueue->id,
            'triggered_at' => now()->subHour(),
            'escalation_level' => 0,
        ]);
        AlertQueue::query()->create([
            'alert_id' => $alert->id,
            'queue_id' => $firstQueue->id,
            'entered_at' => now()->subHour(),
        ]);
        $notifications = Mockery::mock(ControlRoomNotificationService::class);
        $notifications->shouldReceive('notifyQueueEscalation')->once();
        $automation = Mockery::mock(AlertAutomationService::class);
        $automation->shouldReceive('onAlertEscalated')->once();

        app(AutoEscalateControlRoomQueues::class)->handle($notifications, $automation);

        $alert->refresh();
        $this->assertSame($secondQueue->id, $alert->queue_id);
        $this->assertSame(1, (int) $alert->escalation_level);
        $this->assertSame(0, AlertQueue::query()
            ->where('alert_id', $alert->id)
            ->where('queue_id', $thirdQueue->id)
            ->count());
    }

    public function test_queue_escalation_skips_inactive_current_and_destination_queues(): void
    {
        [$inactiveCurrentAlert, , $inactiveCurrentQueue] = $this->makeOverdueAlert();
        $inactiveCurrentQueue->update(['is_active' => false]);

        [$inactiveDestinationAlert, , $activeCurrentQueue, $inactiveDestinationQueue] = $this->makeOverdueAlert();
        $inactiveDestinationQueue->update(['is_active' => false]);

        $notifications = Mockery::mock(ControlRoomNotificationService::class);
        $notifications->shouldNotReceive('notifyQueueEscalation');
        $automation = Mockery::mock(AlertAutomationService::class);
        $automation->shouldNotReceive('onAlertEscalated');

        app(AutoEscalateControlRoomQueues::class)->handle($notifications, $automation);

        $this->assertSame($inactiveCurrentQueue->id, $inactiveCurrentAlert->fresh()->queue_id);
        $this->assertSame($activeCurrentQueue->id, $inactiveDestinationAlert->fresh()->queue_id);
    }

    public function test_successful_sla_and_queue_escalations_are_retry_safe(): void
    {
        [$alert, , , $toQueue] = $this->makeOverdueAlert();
        $notifications = Mockery::mock(ControlRoomNotificationService::class);
        $notifications->shouldReceive('notifySlaBreachEscalation')->once();
        $notifications->shouldReceive('notifyQueueEscalation')->once();
        $automation = Mockery::mock(AlertAutomationService::class);
        $automation->shouldReceive('onAlertEscalated')->twice();

        $slaJob = app(CheckControlRoomSlaBreaches::class);
        $queueJob = app(AutoEscalateControlRoomQueues::class);
        $slaJob->handle($notifications, $automation);
        $slaJob->handle($notifications, $automation);
        $queueJob->handle($notifications, $automation);
        $queueJob->handle($notifications, $automation);

        $alert->refresh();
        $this->assertSame($toQueue->id, $alert->queue_id);
        $this->assertSame(2, (int) $alert->escalation_level);
        $this->assertSame(1, AlertQueue::query()
            ->where('alert_id', $alert->id)
            ->where('queue_id', $toQueue->id)
            ->count());
    }

    public function test_breach_and_queue_jobs_lock_the_alert_before_dependent_rows(): void
    {
        $this->makeOverdueAlert();
        $notifications = Mockery::mock(ControlRoomNotificationService::class);
        $notifications->shouldReceive('notifySlaBreachEscalation')->once();
        $notifications->shouldReceive('notifyQueueEscalation')->once();
        $automation = Mockery::mock(AlertAutomationService::class);
        $automation->shouldReceive('onAlertEscalated')->twice();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower(str_replace(['`', '"'], '', $query->sql));
        });

        app(CheckControlRoomSlaBreaches::class)->handle($notifications, $automation);
        $slaQueries = $queries;
        $queries = [];

        app(AutoEscalateControlRoomQueues::class)->handle($notifications, $automation);
        $queueQueries = $queries;

        $this->assertLockOrder(
            $slaQueries,
            'control_room_alert_sla',
            'SLA breach job',
        );
        $this->assertLockOrder(
            $queueQueries,
            'control_room_alert_queue',
            'queue escalation job',
        );
    }

    /**
     * @return array{ControlRoomAlert, AlertSla, TriageQueue, TriageQueue}
     */
    private function makeOverdueAlert(string $status = ControlRoomAlert::STATUS_OPEN): array
    {
        $suffix = ++$this->queueFixtureSequence;
        $toQueue = TriageQueue::query()->create([
            'name' => 'Escalation tier two',
            'code' => "escalation-tier-two-{$suffix}",
            'tier' => 2,
            'is_active' => true,
        ]);
        $fromQueue = TriageQueue::query()->create([
            'name' => 'Escalation tier one',
            'code' => "escalation-tier-one-{$suffix}",
            'tier' => 1,
            'escalate_to_queue_id' => $toQueue->id,
            'auto_escalate_after_minutes' => 5,
            'is_active' => true,
        ]);
        $alert = ControlRoomAlert::factory()->create([
            'status' => $status,
            'queue_id' => $fromQueue->id,
            'triggered_at' => now()->subHour(),
            'escalation_level' => 0,
            'escalated_at' => null,
        ]);
        AlertQueue::query()->create([
            'alert_id' => $alert->id,
            'queue_id' => $fromQueue->id,
            'entered_at' => now()->subHour(),
        ]);
        $definition = SlaDefinition::query()->create([
            'name' => 'Escalation job SLA',
            'code' => 'escalation-job-sla-'.$alert->id,
            'acknowledge_target_minutes' => 5,
            'response_target_minutes' => 10,
            'resolution_target_minutes' => 15,
            'escalate_on_acknowledge_breach' => true,
            'escalate_on_response_breach' => true,
            'escalate_on_resolution_breach' => true,
            'is_active' => true,
        ]);
        $sla = AlertSla::createFromDefinition($alert, $definition);

        return [$alert, $sla, $fromQueue, $toQueue];
    }

    /** @param array<int, string> $queries */
    private function assertLockOrder(array $queries, string $dependentTable, string $operation): void
    {
        $alertLock = collect($queries)->search(
            fn (string $query): bool => str_contains($query, 'from control_room_alerts')
                && str_contains($query, 'for update'),
        );
        $dependentLock = collect($queries)->search(
            fn (string $query): bool => str_contains($query, "from {$dependentTable}")
                && str_contains($query, 'for update'),
        );

        $this->assertNotFalse($alertLock, "The {$operation} must lock its alert.");
        $this->assertNotFalse($dependentLock, "The {$operation} must lock dependent state.");
        $this->assertLessThan(
            $dependentLock,
            $alertLock,
            "The {$operation} must follow alert-before-dependent lock order.",
        );
    }
}
