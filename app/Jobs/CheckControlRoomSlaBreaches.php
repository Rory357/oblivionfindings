<?php

namespace App\Jobs;

use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoomAlert;
use App\Services\AuditLogger;
use App\Services\ControlRoom\AlertAutomationService;
use App\Services\ControlRoom\ControlRoomNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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

    /**
     * O2 (Stephan, 6 Nov 2026): how far ahead the pre-filter looks. A deadline due within this margin is still locked
     * and checked, so a run that takes a while can never skip an SLA the old full scan would have escalated.
     */
    public const PREFILTER_MARGIN_MINUTES = 10;

    /** O2: two runs never overlap; a run that finds one in progress is dropped (the next one is 5 minutes away). */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('control-room-sla-breaches'))->dontRelease()->expireAfter(600)];
    }

    /**
     * O2: only SLAs with a not-yet-breached milestone that is due (or due within the margin) are worth locking. The
     * exact breach check (AlertSla::checkForBreaches) still runs on the locked row and decides, as before.
     */
    public static function dueCandidates(Builder $query): Builder
    {
        $cutoff = now()->addMinutes(self::PREFILTER_MARGIN_MINUTES);

        return $query->where(function (Builder $due) use ($cutoff): void {
            foreach ([
                ['acknowledged_at', 'acknowledge_deadline', 'acknowledge_breached'],
                ['responded_at', 'response_deadline', 'response_breached'],
                ['resolved_at', 'resolution_deadline', 'resolution_breached'],
            ] as [$done, $deadline, $breached]) {
                $due->orWhere(fn (Builder $milestone) => $milestone
                    ->whereNull($done)
                    ->whereNotNull($deadline)
                    ->where($deadline, '<=', $cutoff)
                    ->where(fn (Builder $flag) => $flag->where($breached, false)->orWhereNull($breached)));
            }
        });
    }

    public function handle(ControlRoomNotificationService $notificationService, AlertAutomationService $automationService): void
    {
        $escalatedCount = 0;

        self::dueCandidates(AlertSla::query()
            ->applicable()
            ->whereHas('alert', fn ($query) => $query->actionable())
            ->whereNull('resolved_at'))
            ->chunkById(100, function ($slas) use ($notificationService, $automationService, &$escalatedCount) {
                foreach ($slas as $candidate) {
                    $escalated = DB::transaction(function () use ($candidate, $notificationService, $automationService): bool {
                        // Lock the lifecycle owner first, then its SLA row. This
                        // prevents a terminal transition racing an escalation.
                        $alert = ControlRoomAlert::query()
                            ->whereKey($candidate->alert_id)
                            ->lockForUpdate()
                            ->first();
                        if (! $alert || ! $alert->isActionable()) {
                            return false;
                        }

                        $sla = AlertSla::query()
                            ->whereKey($candidate->id)
                            ->where('alert_id', $alert->id)
                            ->lockForUpdate()
                            ->first();
                        if (! $sla || ! $sla->isApplicable() || $sla->resolved_at !== null) {
                            return false;
                        }

                        $definition = $sla->slaDefinition()->first();
                        if (! $definition) {
                            return false;
                        }

                        $breaches = $sla->checkForBreaches();
                        if ($breaches === []) {
                            return false;
                        }

                        $shouldEscalate = (
                            (in_array('acknowledge', $breaches, true) && $definition->escalate_on_acknowledge_breach) ||
                            (in_array('response', $breaches, true) && $definition->escalate_on_response_breach) ||
                            (in_array('resolution', $breaches, true) && $definition->escalate_on_resolution_breach)
                        );
                        if (! $shouldEscalate) {
                            return false;
                        }

                        $at = now();
                        $previousLevel = (int) ($alert->escalation_level ?? 0);
                        $newLevel = min($previousLevel + 1, 5);
                        $alert->update([
                            'escalation_level' => $newLevel,
                            'escalated_at' => $alert->escalated_at ?? $at,
                            'context' => array_merge($alert->context ?? [], [
                                'sla_breaches' => array_values(array_unique(array_merge(
                                    $alert->context['sla_breaches'] ?? [],
                                    $breaches,
                                ))),
                                'last_escalation_reason' => 'sla_breach',
                                'last_escalation_at' => $at->toIso8601String(),
                            ]),
                        ]);

                        // Required notification, automation, audit, alert and
                        // SLA flags share the transaction. A failure rolls the
                        // whole escalation back so a retry sees truthful state.
                        // O2: the notification is recorded here and sent once
                        // this transaction commits (after the row lock).
                        $notificationService->notifySlaBreachEscalation($alert, $definition, $breaches);
                        $automationService->onAlertEscalated($alert, $previousLevel);
                        AuditLogger::logOrFail('controlRoom.alert.slaBreached', $alert, [
                            'alert_id' => $alert->id,
                            'breaches' => $breaches,
                            'escalation_level' => $newLevel,
                            'sla_definition_id' => $definition->id,
                        ]);

                        return true;
                    }, 3);

                    if ($escalated) {
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
