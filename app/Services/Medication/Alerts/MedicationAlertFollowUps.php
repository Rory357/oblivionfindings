<?php

namespace App\Services\Medication\Alerts;

use App\Models\MedicationAlert;
use App\Models\MedicationAlertEvent;
use App\Notifications\MedicationAlertNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Follow-up for medication alerts with Follow up on (eMAR P11 B2 chunk 3),
 * run every 15 minutes (`emar:alert-follow-ups`).
 *
 * Until someone attends (MedicationAlertAttendance) and while the alert is
 * open, Settings › Delivery decides what happens:
 *  - re-alert: everyone told so far is told again every N minutes after it
 *    was raised, up to M times;
 *  - escalate: after N minutes the chosen groups at the house are told too,
 *    once.
 * A re-alert and an escalation due at the same moment are one step; the
 * people it escalates to aren't also re-alerted in it. Every step goes
 * through the same gate as the first message (medication access at the
 * house, controlled-medicine access for a controlled alert) and the same
 * channels, and is kept in the alert's history.
 *
 * Each alert's step runs under its row lock and re-checks there, so an
 * attend that lands first stops it — nothing re-alerts after an attend.
 */
class MedicationAlertFollowUps
{
    public function __construct(
        private readonly MedicationAlerts $alerts,
        private readonly MedicationAlertSettings $settings,
        private readonly MedicationAlertRecipients $recipients,
    ) {}

    /**
     * When this alert's next re-alert or escalation is due, or null.
     *
     * @param  array{realert_every: int|null, realert_max: int|null, attended: string, escalate_after: int|null, escalate_to: list<string>}  $followUp
     */
    public static function nextDue(MedicationAlert $alert, array $followUp): ?Carbon
    {
        if (! $alert->follow_up || $alert->open_key === null || $alert->attended_at !== null || $alert->raised_at === null) {
            return null;
        }
        $raised = Carbon::instance($alert->raised_at);
        $due = [];
        if ($followUp['realert_every'] !== null && (int) $alert->realert_count < (int) $followUp['realert_max']) {
            $due[] = $raised->copy()->addMinutes($followUp['realert_every'] * ((int) $alert->realert_count + 1));
        }
        if ($followUp['escalate_after'] !== null && $followUp['escalate_to'] !== [] && $alert->escalated_at === null) {
            $due[] = $raised->copy()->addMinutes($followUp['escalate_after']);
        }

        return $due === [] ? null : min($due);
    }

    /** Every alert whose follow-up is due now. Returns how many steps were taken. */
    public function tick(CarbonInterface $now): int
    {
        $due = MedicationAlert::query()
            ->whereNotNull('open_key')
            ->whereNull('attended_at')
            ->where('follow_up', true)
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<=', $now)
            ->orderBy('next_follow_up_at')
            ->pluck('id');
        $steps = 0;
        foreach ($due as $id) {
            try {
                $steps += $this->step((int) $id, $now) ? 1 : 0;
            } catch (Throwable $exception) {
                Log::error('Medication alert follow-up failed', ['medication_alert_id' => $id, 'exception' => $exception->getMessage()]);
                report($exception);
            }
        }

        return $steps;
    }

    /** One alert's follow-up step, decided under its row lock. True when someone was re-alerted or escalated to. */
    public function step(int $alertId, CarbonInterface $now): bool
    {
        return DB::transaction(function () use ($alertId, $now): bool {
            $alert = MedicationAlert::query()->whereKey($alertId)->lockForUpdate()->first();
            if (! $alert instanceof MedicationAlert
                || $alert->open_key === null
                || $alert->attended_at !== null
                || ! $alert->follow_up
                || $alert->next_follow_up_at === null
                || $alert->next_follow_up_at->gt($now)) {
                return false;
            }
            $followUp = $this->settings->followUp();
            $channels = $this->settings->channels($alert->type);
            $raised = Carbon::instance($alert->raised_at);
            $escalate = $followUp['escalate_after'] !== null
                && $followUp['escalate_to'] !== []
                && $alert->escalated_at === null
                && $now->gte($raised->copy()->addMinutes($followUp['escalate_after']));
            $realert = $followUp['realert_every'] !== null
                && (int) $alert->realert_count < (int) $followUp['realert_max']
                && $now->gte($raised->copy()->addMinutes($followUp['realert_every'] * ((int) $alert->realert_count + 1)));

            $step = (int) $alert->recipients()->max('step') + 1;
            $subject = MedicationAlertSubject::of($alert);
            // Everyone told so far, with why they were first told.
            $toldSoFar = $alert->recipients()
                ->whereNotNull('told_at')
                ->orderBy('step')
                ->get(['user_id', 'reason'])
                ->unique('user_id')
                ->mapWithKeys(fn ($row): array => [(int) $row->user_id => (string) $row->reason])
                ->all();

            $escalatedTo = [];
            if ($escalate) {
                $people = $this->recipients->escalationTargets($followUp['escalate_to'], $subject, $now, array_keys($toldSoFar));
                $sent = $this->alerts->deliver($alert, $people, $channels, $step, MedicationAlertNotification::ESCALATION, $now, $followUp);
                $escalatedTo = array_column($sent['told'], 'user_id');
                $this->alerts->event($alert, MedicationAlertEvent::ESCALATED, [
                    'groups' => $followUp['escalate_to'],
                    'told' => $sent['told'],
                    'unreachable' => $sent['unreachable'],
                ]);
                $alert->escalated_at = $now;
            }
            if ($realert) {
                $again = array_diff_key($toldSoFar, array_flip($escalatedTo));
                $people = $this->recipients->stillAllowed($again, $subject);
                $sent = $this->alerts->deliver($alert, $people, $channels, $step, MedicationAlertNotification::REALERT, $now, $followUp);
                $alert->realert_count = (int) $alert->realert_count + 1;
                $this->alerts->event($alert, MedicationAlertEvent::RE_ALERTED, [
                    'count' => $alert->realert_count,
                    'told' => $sent['told'],
                    'unreachable' => $sent['unreachable'],
                ]);
            }
            $alert->next_follow_up_at = self::nextDue($alert, $followUp);
            $alert->save();

            return $escalate || $realert;
        });
    }
}
