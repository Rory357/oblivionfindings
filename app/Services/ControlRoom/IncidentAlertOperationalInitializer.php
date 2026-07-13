<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use Illuminate\Support\Facades\DB;

/**
 * Completes the operational setup for a newly-created incident journey alert.
 *
 * Durable queue, SLA and automation writes belong to the owning journey
 * transaction. Human notifications are deferred until its outermost commit so
 * a rolled-back incident can never leak an alert notification.
 */
class IncidentAlertOperationalInitializer
{
    public function __construct(
        private readonly ControlRoomNotificationService $notifications,
        private readonly AlertAutomationService $automation,
    ) {}

    public function initialiseNewAlert(ControlRoomAlert $alert): void
    {
        $queue = TriageQueue::findForAlert(
            $alert->severity,
            $alert->source,
            $alert->alert_type,
        );

        if ($queue !== null) {
            if ($alert->queue_id === null) {
                $alert->forceFill(['queue_id' => $queue->id])->saveQuietly();
            }

            AlertQueue::query()->firstOrCreate(
                [
                    'alert_id' => $alert->id,
                    'queue_id' => $queue->id,
                    'exited_at' => null,
                ],
                ['entered_at' => now()],
            );
        }

        if (! $alert->sla()->exists()) {
            $slaDefinition = SlaDefinition::findForAlert(
                $alert->alert_type,
                $alert->severity,
                $alert->source,
            );

            if ($slaDefinition !== null) {
                AlertSla::createFromDefinition($alert, $slaDefinition);
            }
        }

        $this->automation->onAlertCreated($alert->refresh());

        $alertId = (int) $alert->id;
        $queueId = $queue?->id;
        DB::afterCommit(function () use ($alertId, $queueId): void {
            $committedAlert = ControlRoomAlert::query()->find($alertId);
            if ($committedAlert === null) {
                return;
            }

            $committedQueue = $queueId === null
                ? null
                : TriageQueue::query()->find($queueId);
            $this->notifications->notifyAlert($committedAlert, null, $committedQueue);
        });
    }
}
