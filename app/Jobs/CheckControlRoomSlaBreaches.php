<?php

namespace App\Jobs;

use App\Models\ControlRoom\AlertSla;
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
 * Canonical SLA breach detection and escalation job.
 *
 * This is ONE OF TWO canonical escalation mechanisms in the system:
 * 1. This job — SLA-driven escalation (breach → increment level → notify)
 * 2. AutoEscalateControlRoomQueues — time-in-queue escalation (queue → next queue → notify)
 *
 * Together they form the ONLY operational escalation engine.
 * No other job/service should escalate operational alerts.
 */
class CheckControlRoomSlaBreaches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ControlRoomNotificationService $notificationService, AlertAutomationService $automationService): void
    {
        $escalatedCount = 0;

        AlertSla::query()
            ->applicable()
            ->with(['alert', 'slaDefinition'])
            ->whereNull('resolved_at')
            ->chunkById(100, function ($slas) use ($notificationService, &$escalatedCount) {
                foreach ($slas as $sla) {
                    $breaches = $sla->checkForBreaches();
                    if (empty($breaches)) {
                        continue;
                    }

                    $alert = $sla->alert;
                    $definition = $sla->slaDefinition;
                    if (! $alert || ! $definition) {
                        continue;
                    }

                    $escalate = (
                        (in_array('acknowledge', $breaches, true) && $definition->escalate_on_acknowledge_breach) ||
                        (in_array('response', $breaches, true) && $definition->escalate_on_response_breach) ||
                        (in_array('resolution', $breaches, true) && $definition->escalate_on_resolution_breach)
                    );

                    if ($escalate) {
                        $newLevel = min(($alert->escalation_level ?? 0) + 1, 5);

                        $alert->update([
                            'escalation_level' => $newLevel,
                            'escalated_at' => $alert->escalated_at ?? now(),
                            'context' => array_merge($alert->context ?? [], [
                                'sla_breaches' => array_values(array_unique(array_merge(
                                    $alert->context['sla_breaches'] ?? [],
                                    $breaches
                                ))),
                                'last_escalation_reason' => 'sla_breach',
                                'last_escalation_at' => now()->toIso8601String(),
                            ]),
                        ]);

                        $previousLevel = $newLevel - 1;

                        // Notify appropriate roles about the escalation
                        $notificationService->notifySlaBreachEscalation($alert, $definition, $breaches);

                        // Run escalation-driven automation (watchers, etc.)
                        $automationService->onAlertEscalated($alert, $previousLevel);

                        AuditLogger::log('controlRoom.alert.slaBreached', $alert, [
                            'alert_id' => $alert->id,
                            'breaches' => $breaches,
                            'escalation_level' => $newLevel,
                            'sla_definition_id' => $definition->id,
                        ]);

                        $escalatedCount++;
                    }
                }
            });

        if ($escalatedCount > 0) {
            Log::info('CheckControlRoomSlaBreaches: escalated alerts', [
                'escalated_count' => $escalatedCount,
            ]);
        }
    }
}
