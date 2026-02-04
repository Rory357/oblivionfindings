<?php

namespace App\Jobs;

use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\TriageQueue;
use App\Services\AuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AutoEscalateControlRoomQueues implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        TriageQueue::query()
            ->active()
            ->whereNotNull('escalate_to_queue_id')
            ->whereNotNull('auto_escalate_after_minutes')
            ->with('escalateToQueue')
            ->chunkById(50, function ($queues) {
                foreach ($queues as $queue) {
                    $queue->alerts()
                        ->unresolved()
                        ->whereNotNull('queue_id')
                        ->chunkById(100, function ($alerts) use ($queue) {
                            foreach ($alerts as $alert) {
                                if (!$queue->shouldAutoEscalate($alert)) {
                                    continue;
                                }

                                $nextQueue = $queue->escalateToQueue;
                                if (!$nextQueue) {
                                    continue;
                                }

                                AlertQueue::query()
                                    ->where('alert_id', $alert->id)
                                    ->where('queue_id', $queue->id)
                                    ->whereNull('exited_at')
                                    ->update([
                                        'exited_at' => now(),
                                        'exit_reason' => 'auto_escalated',
                                    ]);

                                AlertQueue::create([
                                    'alert_id' => $alert->id,
                                    'queue_id' => $nextQueue->id,
                                    'entered_at' => now(),
                                ]);

                                $alert->update([
                                    'queue_id' => $nextQueue->id,
                                    'escalation_level' => min(($alert->escalation_level ?? 0) + 1, 5),
                                    'escalated_at' => now(),
                                    'context' => array_merge($alert->context ?? [], [
                                        'auto_escalated_from' => $queue->code,
                                        'auto_escalated_to' => $nextQueue->code,
                                    ]),
                                ]);

                                AuditLogger::log('controlRoom.alert.autoEscalate', $alert, [
                                    'alert_id' => $alert->id,
                                    'from_queue' => $queue->code,
                                    'to_queue' => $nextQueue->code,
                                ]);
                            }
                        });
                }
            });
    }
}
