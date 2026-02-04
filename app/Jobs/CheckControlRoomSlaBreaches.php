<?php

namespace App\Jobs;

use App\Models\ControlRoom\AlertSla;
use App\Services\AuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckControlRoomSlaBreaches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        AlertSla::query()
            ->with(['alert', 'slaDefinition'])
            ->whereNull('resolved_at')
            ->chunkById(100, function ($slas) {
                foreach ($slas as $sla) {
                    $breaches = $sla->checkForBreaches();
                    if (empty($breaches)) {
                        continue;
                    }

                    $alert = $sla->alert;
                    $definition = $sla->slaDefinition;
                    if (!$alert || !$definition) {
                        continue;
                    }

                    $escalate = (
                        (in_array('acknowledge', $breaches, true) && $definition->escalate_on_acknowledge_breach) ||
                        (in_array('response', $breaches, true) && $definition->escalate_on_response_breach) ||
                        (in_array('resolution', $breaches, true) && $definition->escalate_on_resolution_breach)
                    );

                    if ($escalate) {
                        $alert->update([
                            'escalation_level' => min(($alert->escalation_level ?? 0) + 1, 5),
                            'escalated_at' => $alert->escalated_at ?? now(),
                            'context' => array_merge($alert->context ?? [], [
                                'sla_breaches' => array_values(array_unique(array_merge(
                                    $alert->context['sla_breaches'] ?? [],
                                    $breaches
                                ))),
                            ]),
                        ]);

                        AuditLogger::log('controlRoom.alert.slaBreached', $alert, [
                            'alert_id' => $alert->id,
                            'breaches' => $breaches,
                            'sla_definition_id' => $definition->id,
                        ]);
                    }
                }
            });

        Log::info('Control Room SLA breach scan complete');
    }
}
