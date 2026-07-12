<?php

use App\Jobs\AutoEscalateControlRoomQueues;
use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Services\ControlRoom\AlertAutomationService;
use App\Services\ControlRoom\ControlRoomNotificationService;

test('an eligible queued alert is fully escalated and invokes both services', function () {
    $target = TriageQueue::create([
        'name' => 'Escalation Target',
        'code' => 'test_escalation_target',
        'tier' => 2,
        'is_active' => true,
    ]);
    $source = TriageQueue::create([
        'name' => 'Escalation Source',
        'code' => 'test_escalation_source',
        'tier' => 1,
        'escalate_to_queue_id' => $target->id,
        'auto_escalate_after_minutes' => 15,
        'is_active' => true,
    ]);
    $alert = ControlRoomAlert::factory()->open()->create([
        'queue_id' => $source->id,
        'triggered_at' => now()->subHour(),
        'escalation_level' => 1,
        'context' => ['fixture' => 'eligible'],
    ]);
    $oldAssignment = AlertQueue::create([
        'alert_id' => $alert->id,
        'queue_id' => $source->id,
        'entered_at' => now()->subHour(),
    ]);

    $notifications = Mockery::mock(ControlRoomNotificationService::class);
    $notifications->shouldReceive('notifyQueueEscalation')
        ->once()
        ->withArgs(fn (ControlRoomAlert $calledAlert, TriageQueue $from, TriageQueue $to) => $calledAlert->is($alert) && $from->is($source) && $to->is($target));

    $automation = Mockery::mock(AlertAutomationService::class);
    $automation->shouldReceive('onAlertEscalated')
        ->once()
        ->withArgs(fn (ControlRoomAlert $calledAlert, int $previousLevel) => $calledAlert->is($alert) && $previousLevel === 1);

    (new AutoEscalateControlRoomQueues)->handle($notifications, $automation);

    expect($oldAssignment->fresh()->exited_at)->not->toBeNull()
        ->and($oldAssignment->fresh()->exit_reason)->toBe('auto_escalated')
        ->and(AlertQueue::query()
            ->where('alert_id', $alert->id)
            ->where('queue_id', $target->id)
            ->whereNull('exited_at')
            ->exists())->toBeTrue()
        ->and($alert->fresh()->queue_id)->toBe($target->id)
        ->and($alert->fresh()->escalation_level)->toBe(2)
        ->and($alert->fresh()->context['auto_escalated_from'])->toBe('test_escalation_source')
        ->and($alert->fresh()->context['auto_escalated_to'])->toBe('test_escalation_target');
});
