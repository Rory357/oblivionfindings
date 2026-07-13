<?php

namespace App\Jobs;

use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\TriageQueue;
use App\Services\AuditLogger;
use App\Services\ControlRoom\AlertAutomationService;
use App\Services\ControlRoom\ControlRoomNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Canonical queue-based auto-escalation job.
 *
 * This is ONE OF TWO canonical escalation mechanisms in the system:
 * 1. CheckControlRoomSlaBreaches — SLA-driven escalation
 * 2. This job — time-in-queue escalation (queue → next queue → notify)
 *
 * Together they form the ONLY operational escalation engine.
 * No other job/service should escalate operational alerts.
 */
class AutoEscalateControlRoomQueues implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ControlRoomNotificationService $notificationService, AlertAutomationService $automationService): void
    {
        $escalatedCount = 0;

        TriageQueue::query()
            ->active()
            ->whereNotNull('escalate_to_queue_id')
            ->whereNotNull('auto_escalate_after_minutes')
            ->with('escalateToQueue')
            ->chunkById(50, function ($queues) use ($notificationService, $automationService, &$escalatedCount) {
                foreach ($queues as $queue) {
                    $queue->alerts()
                        ->unresolved()
                        ->whereNotNull('queue_id')
                        ->chunkById(100, function ($alerts) use ($queue, $notificationService, $automationService, &$escalatedCount) {
                            foreach ($alerts as $alert) {
                                if (! $queue->shouldAutoEscalate($alert)) {
                                    continue;
                                }

                                $nextQueue = $queue->escalateToQueue;
                                if (! $nextQueue) {
                                    continue;
                                }

                                // Close current queue assignment
                                AlertQueue::query()
                                    ->where('alert_id', $alert->id)
                                    ->where('queue_id', $queue->id)
                                    ->whereNull('exited_at')
                                    ->update([
                                        'exited_at' => now(),
                                        'exit_reason' => 'auto_escalated',
                                    ]);

                                // Create new queue assignment
                                AlertQueue::create([
                                    'alert_id' => $alert->id,
                                    'queue_id' => $nextQueue->id,
                                    'entered_at' => now(),
                                ]);

                                // Update alert
                                $newLevel = min(($alert->escalation_level ?? 0) + 1, 5);
                                $alert->update([
                                    'queue_id' => $nextQueue->id,
                                    'escalation_level' => $newLevel,
                                    'escalated_at' => now(),
                                    'context' => array_merge($alert->context ?? [], [
                                        'auto_escalated_from' => $queue->code ?? $queue->name,
                                        'auto_escalated_to' => $nextQueue->code ?? $nextQueue->name,
                                        'last_escalation_reason' => 'queue_time_exceeded',
                                        'last_escalation_at' => now()->toIso8601String(),
                                    ]),
                                ]);

                                // Notify target queue's assigned roles
                                $notificationService->notifyQueueEscalation($alert, $queue, $nextQueue);

                                // Run escalation-driven automation (watchers, etc.)
                                $automationService->onAlertEscalated($alert, $newLevel - 1);

                                AuditLogger::log('controlRoom.alert.autoEscalate', $alert, [
                                    'alert_id' => $alert->id,
                                    'from_queue' => $queue->code ?? $queue->name,
                                    'to_queue' => $nextQueue->code ?? $nextQueue->name,
                                    'escalation_level' => $newLevel,
                                ]);

                                $escalatedCount++;
                            }
                        });
                }
            });

        if ($escalatedCount > 0) {
            Log::info('AutoEscalateControlRoomQueues: escalated alerts', [
                'escalated_count' => $escalatedCount,
            ]);
        }
    }
}
