<?php

namespace App\Jobs;

use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Services\AuditLogger;
use App\Services\ControlRoom\AlertAutomationService;
use App\Services\ControlRoom\ControlRoomNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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
        $runStartedAt = now();

        TriageQueue::query()
            ->active()
            ->whereNotNull('escalate_to_queue_id')
            ->whereNotNull('auto_escalate_after_minutes')
            ->with('escalateToQueue')
            ->chunkById(50, function ($queues) use ($notificationService, $automationService, $runStartedAt, &$escalatedCount) {
                foreach ($queues as $queue) {
                    $queue->alerts()
                        ->actionable()
                        ->whereNotNull('queue_id')
                        ->chunkById(100, function ($alerts) use ($queue, $notificationService, $automationService, $runStartedAt, &$escalatedCount) {
                            foreach ($alerts as $candidate) {
                                $escalated = DB::transaction(function () use ($candidate, $queue, $notificationService, $automationService, $runStartedAt): bool {
                                    // Alert-first locking is shared with lifecycle
                                    // and SLA transitions, preventing a queue move
                                    // after an operator has terminalised the alert.
                                    $alert = ControlRoomAlert::query()
                                        ->whereKey($candidate->id)
                                        ->lockForUpdate()
                                        ->first();
                                    if (! $alert
                                        || ! $alert->isActionable()
                                        || (int) $alert->queue_id !== (int) $queue->id) {
                                        return false;
                                    }

                                    $lockedQueue = TriageQueue::query()
                                        ->whereKey($queue->id)
                                        ->where('is_active', true)
                                        ->lockForUpdate()
                                        ->first();
                                    if (! $lockedQueue) {
                                        return false;
                                    }

                                    $nextQueue = TriageQueue::query()
                                        ->whereKey($lockedQueue->escalate_to_queue_id)
                                        ->where('is_active', true)
                                        ->lockForUpdate()
                                        ->first();
                                    if (! $nextQueue) {
                                        return false;
                                    }

                                    $currentAssignment = AlertQueue::query()
                                        ->where('alert_id', $alert->id)
                                        ->where('queue_id', $lockedQueue->id)
                                        ->whereNull('exited_at')
                                        ->orderByDesc('entered_at')
                                        ->orderByDesc('id')
                                        ->lockForUpdate()
                                        ->first();
                                    if (! $currentAssignment
                                        || ! $lockedQueue->shouldAutoEscalate($currentAssignment, $runStartedAt)) {
                                        return false;
                                    }

                                    $at = now();
                                    $currentAssignment->update([
                                        'exited_at' => $at,
                                        'exit_reason' => 'auto_escalated',
                                    ]);
                                    AlertQueue::create([
                                        'alert_id' => $alert->id,
                                        'queue_id' => $nextQueue->id,
                                        'entered_at' => $at,
                                    ]);

                                    $previousLevel = (int) ($alert->escalation_level ?? 0);
                                    $newLevel = min($previousLevel + 1, 5);
                                    $alert->update([
                                        'queue_id' => $nextQueue->id,
                                        'escalation_level' => $newLevel,
                                        'escalated_at' => $at,
                                        'context' => array_merge($alert->context ?? [], [
                                            'auto_escalated_from' => $lockedQueue->code ?? $lockedQueue->name,
                                            'auto_escalated_to' => $nextQueue->code ?? $nextQueue->name,
                                            'last_escalation_reason' => 'queue_time_exceeded',
                                            'last_escalation_at' => $at->toIso8601String(),
                                        ]),
                                    ]);

                                    $notificationService->notifyQueueEscalation($alert, $lockedQueue, $nextQueue);
                                    $automationService->onAlertEscalated($alert, $previousLevel);
                                    AuditLogger::logOrFail('controlRoom.alert.autoEscalate', $alert, [
                                        'alert_id' => $alert->id,
                                        'from_queue' => $lockedQueue->code ?? $lockedQueue->name,
                                        'to_queue' => $nextQueue->code ?? $nextQueue->name,
                                        'escalation_level' => $newLevel,
                                    ]);

                                    return true;
                                }, 3);

                                if ($escalated) {
                                    $escalatedCount++;
                                }
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
