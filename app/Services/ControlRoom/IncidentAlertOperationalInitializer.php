<?php

namespace App\Services\ControlRoom;

use App\Jobs\Notifications\DeliverControlRoomAlertNotificationJob;
use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\Communication;
use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
            if ((int) $alert->queue_id !== (int) $queue->id) {
                AlertQueue::query()
                    ->where('alert_id', $alert->id)
                    ->whereNull('exited_at')
                    ->update([
                        'exited_at' => now(),
                        'exit_reason' => 'severity_reconciled',
                    ]);

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
        } else {
            AlertQueue::query()
                ->where('alert_id', $alert->id)
                ->whereNull('exited_at')
                ->update([
                    'exited_at' => now(),
                    'exit_reason' => AlertSla::ENDED_RECONCILED_NO_MATCH,
                ]);

            if ($alert->queue_id !== null) {
                $alert->forceFill(['queue_id' => null])->saveQuietly();
            }
        }

        $this->reconcileSla($alert);
        $this->attachPlaybook($alert);

        $this->automation->onAlertCreated($alert->refresh());

        $communications = $this->notifications
            ->stageAlertNotifications($alert->refresh(), null, $queue)
            ->reject(fn (Communication $communication): bool => $communication->superseded_at !== null
                || (int) $communication->retry_count >= 3
                || ! in_array($communication->status, ['pending', 'failed'], true));

        if ($communications->isEmpty()) {
            return;
        }

        $communicationIds = $communications
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
        DB::afterCommit(function () use ($communicationIds): void {
            foreach ($communicationIds as $communicationId) {
                try {
                    DeliverControlRoomAlertNotificationJob::dispatch($communicationId);
                } catch (Throwable $exception) {
                    $this->recordDispatchFailure($communicationId, $exception);

                    Log::error('Control Room alert notification dispatch failed', [
                        'communication_id' => $communicationId,
                        'exception' => $exception::class,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        });
    }

    private function reconcileSla(ControlRoomAlert $alert): void
    {
        $slaDefinition = SlaDefinition::findForAlert(
            $alert->alert_type,
            $alert->severity,
            $alert->source,
        );
        $alertSla = $alert->sla()->lockForUpdate()->first();
        if ($slaDefinition === null) {
            $alertSla?->terminaliseForNoMatchingDefinition($alert->severity);

            return;
        }

        if ($alertSla === null) {
            AlertSla::createFromDefinition($alert, $slaDefinition);

            return;
        }

        if ($alertSla->ended_as === AlertSla::ENDED_RECONCILED_NO_MATCH) {
            $alertSla->reactivateFromDefinition($slaDefinition, now());

            return;
        }

        if ((int) $alertSla->sla_definition_id === (int) $slaDefinition->id) {
            return;
        }

        $deadlines = $slaDefinition->calculateDeadlines(
            $alert->triggered_at ?? $alert->created_at ?? now(),
        );
        $performance = $this->recalculateCompletedSlaMilestones($alertSla, $deadlines);
        $alertSla->forceFill([
            'sla_definition_id' => $slaDefinition->id,
            'acknowledge_target_minutes' => $slaDefinition->acknowledge_target_minutes,
            'response_target_minutes' => $slaDefinition->response_target_minutes,
            'resolution_target_minutes' => $slaDefinition->resolution_target_minutes,
            'acknowledge_deadline' => $deadlines['acknowledge'] ?? null,
            'response_deadline' => $deadlines['response'] ?? null,
            'resolution_deadline' => $deadlines['resolution'] ?? null,
            ...$performance,
        ])->save();
    }

    private function attachPlaybook(ControlRoomAlert $alert): void
    {
        $currentRun = $alert->playbook_run_id === null
            ? null
            : PlaybookRun::query()
                ->whereKey($alert->playbook_run_id)
                ->lockForUpdate()
                ->first();
        $targetPlaybook = Playbook::findForAlert($alert->alert_type, $alert->severity);
        if ($targetPlaybook === null) {
            if ($currentRun !== null
                && in_array(
                    $currentRun->status,
                    [PlaybookRun::STATUS_PENDING, PlaybookRun::STATUS_IN_PROGRESS],
                    true,
                )
            ) {
                $reconciledAt = now();
                $currentRun->steps()
                    ->whereIn('status', ['pending', 'in_progress'])
                    ->update([
                        'status' => 'skipped',
                        'completed_at' => $reconciledAt,
                    ]);
                $currentRun->forceFill([
                    'status' => PlaybookRun::STATUS_CANCELLED,
                    'completed_at' => $reconciledAt,
                    'context' => array_merge($currentRun->context ?? [], [
                        'reconciled_for_severity' => $alert->severity,
                        'reconciled_at' => $reconciledAt->toIso8601String(),
                        'reconciliation_reason' => AlertSla::ENDED_RECONCILED_NO_MATCH,
                    ]),
                ])->save();
            }

            if ($alert->playbook_run_id !== null) {
                $alert->forceFill(['playbook_run_id' => null])->saveQuietly();
            }

            return;
        }

        if ((int) $currentRun?->playbook_id === (int) $targetPlaybook->id) {
            return;
        }

        $run = PlaybookRun::query()
            ->where('alert_id', $alert->id)
            ->where('playbook_id', $targetPlaybook->id)
            ->whereIn('status', [PlaybookRun::STATUS_PENDING, PlaybookRun::STATUS_IN_PROGRESS])
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        if ($run === null) {
            $run = PlaybookRun::query()->create([
                'playbook_id' => $targetPlaybook->id,
                'alert_id' => $alert->id,
                'status' => PlaybookRun::STATUS_PENDING,
                'total_steps' => $targetPlaybook->steps()->count(),
            ]);
        }

        if ($currentRun !== null
            && in_array(
                $currentRun->status,
                [PlaybookRun::STATUS_PENDING, PlaybookRun::STATUS_IN_PROGRESS],
                true,
            )
        ) {
            $currentRun->steps()
                ->whereIn('status', ['pending', 'in_progress'])
                ->update(['status' => 'skipped']);
            $currentRun->forceFill([
                'status' => PlaybookRun::STATUS_CANCELLED,
                'context' => array_merge($currentRun->context ?? [], [
                    'superseded_by_playbook_run_id' => $run->id,
                    'superseded_for_severity' => $alert->severity,
                    'superseded_at' => now()->toIso8601String(),
                    'supersession_reason' => 'severity_reconciled',
                ]),
            ])->save();
        }

        $alert->forceFill(['playbook_run_id' => $run->id])->saveQuietly();
    }

    private function recalculateCompletedSlaMilestones(AlertSla $alertSla, array $deadlines): array
    {
        $updates = [];
        $firstBreachCandidates = collect([$alertSla->first_breach_at])->filter();
        $stages = [
            'acknowledge' => ['actual' => 'acknowledged_at', 'variance' => 'acknowledge_variance_minutes', 'breached' => 'acknowledge_breached'],
            'response' => ['actual' => 'responded_at', 'variance' => 'response_variance_minutes', 'breached' => 'response_breached'],
            'resolution' => ['actual' => 'resolved_at', 'variance' => 'resolution_variance_minutes', 'breached' => 'resolution_breached'],
        ];

        foreach ($stages as $deadlineKey => $fields) {
            $actual = $alertSla->{$fields['actual']};
            $deadline = $deadlines[$deadlineKey] ?? null;
            if ($actual === null) {
                $updates[$fields['variance']] = null;
                $updates[$fields['breached']] = (bool) $alertSla->{$fields['breached']};

                continue;
            }

            if ($deadline === null) {
                $updates[$fields['variance']] = null;
                $updates[$fields['breached']] = (bool) $alertSla->{$fields['breached']};
            } else {
                $updates[$fields['variance']] = (int) $deadline->diffInMinutes($actual, false);
                $updates[$fields['breached']] = (bool) $alertSla->{$fields['breached']}
                    || $actual->gt($deadline);
            }

            if ($updates[$fields['breached']]) {
                $firstBreachCandidates->push($actual);
            }
        }

        $updates['first_breach_at'] = $firstBreachCandidates
            ->sortBy(fn ($timestamp): int => $timestamp->getTimestamp())
            ->first();

        return $updates;
    }

    private function recordDispatchFailure(int $communicationId, Throwable $exception): void
    {
        try {
            DB::transaction(function () use ($communicationId, $exception): void {
                $communication = Communication::query()
                    ->whereKey($communicationId)
                    ->lockForUpdate()
                    ->first();

                if ($communication === null || $communication->status !== 'pending') {
                    return;
                }

                $communication->forceFill([
                    'status' => 'failed',
                    'status_detail' => mb_substr($exception->getMessage(), 0, 1000),
                    'retry_count' => (int) $communication->retry_count + 1,
                ])->save();
            });
        } catch (Throwable $persistenceException) {
            Log::critical('Control Room alert notification failure could not be persisted', [
                'communication_id' => $communicationId,
                'delivery_exception' => $exception->getMessage(),
                'persistence_exception' => $persistenceException->getMessage(),
            ]);
        }
    }
}
