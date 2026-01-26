<?php

namespace App\Console\Commands;

use App\Models\ClientIncident;
use App\Models\IncidentFollowup;
use App\Models\NotificationEscalationRule;
use App\Models\Timesheet;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

class EscalatePendingNotifications extends Command
{
    protected $signature = 'notifications:escalate';
    protected $description = 'Re-notify or escalate pending notifications based on admin-configured escalation rules.';

    public function handle(): int
    {
        $rules = NotificationEscalationRule::query()->where('enabled', true)->get();
        if ($rules->isEmpty()) {
            $this->info('No escalation rules enabled.');
            return self::SUCCESS;
        }

        $now = now();
        $sent = 0;

        foreach ($rules as $rule) {
            $sent += $this->processRule($rule, $now);
        }

        $this->info("Escalation run complete. Sent {$sent} reminder notifications.");
        return self::SUCCESS;
    }

    protected function processRule(NotificationEscalationRule $rule, Carbon $now): int
    {
        $q = DatabaseNotification::query()
            ->where('type', AppEventNotification::class)
            ->where('data->event_key', $rule->event_key)
            ->whereNull('data->is_reminder')
            ->where('escalation_count', '<', $rule->max_reminders);

        if ($rule->require_ack) {
            $q->whereNull('acknowledged_at');
        } else {
            $q->whereNull('read_at');
        }

        // Respect initial delay
        $q->where('created_at', '<=', $now->copy()->subMinutes($rule->remind_after_minutes));

        // Respect repeat interval
        $q->where(function ($qq) use ($rule, $now) {
            $qq->whereNull('last_escalated_at')
                ->orWhere('last_escalated_at', '<=', $now->copy()->subMinutes($rule->repeat_every_minutes));
        });

        $pending = $q->limit(200)->get();
        if ($pending->isEmpty()) return 0;

        $sent = 0;
        foreach ($pending as $n) {
            if ($this->shouldStopBecauseEntityResolved($n)) {
                // Treat as resolved; do not continue reminders.
                $n->forceFill([
                    'last_escalated_at' => $now,
                    'escalation_count' => (int) $n->escalation_count,
                ])->save();
                continue;
            }

            $payload = is_array($n->data) ? $n->data : (array) $n->data;
            $payload['is_reminder'] = true;
            $payload['reminder_number'] = ((int) $n->escalation_count) + 1;
            $payload['title'] = 'Reminder: ' . (string) ($payload['title'] ?? 'Notification');

            // Add a little more context for auditability / clarity
            $payload['context'] = array_merge((array) ($payload['context'] ?? []), [
                'Reminder' => '#' . $payload['reminder_number'],
            ]);

            $reminderNumber = ((int) $payload['reminder_number']);

            $recipients = $this->resolveEscalationRecipients($n, $rule, $reminderNumber);

            // If force delivery is on, bypass user notification preferences.
            if (!$rule->force_delivery) {
                $svc = app(NotificationService::class);
                $recipients = $svc->applyPreferences($recipients, (string) $rule->event_key);
            }

            foreach ($recipients as $u) {
                $u->notify(new AppEventNotification($payload));
                $sent++;
            }

            $n->forceFill([
                'escalation_count' => ((int) $n->escalation_count) + 1,
                'last_escalated_at' => $now,
            ])->save();
        }

        return $sent;
    }

    /**
     * If the underlying entity is now in a terminal state, stop escalating.
     */
    protected function shouldStopBecauseEntityResolved(DatabaseNotification $n): bool
    {
        $data = is_array($n->data) ? $n->data : (array) $n->data;
        $eventKey = (string) ($data['event_key'] ?? '');
        $entityId = $data['entity_id'] ?? null;

        if (!$entityId) return false;

        // Incidents: stop if reviewed
        if (str_starts_with($eventKey, 'incidents.')) {
            $incident = ClientIncident::query()->find($entityId);
            return $incident && $incident->status === 'reviewed';
        }

        // Follow-ups: stop if completed
        if (str_starts_with($eventKey, 'followups.')) {
            $fu = IncidentFollowup::query()->find($entityId);
            return $fu && (string) $fu->status === 'completed';
        }

        // Timesheets: stop if approved/rejected
        if (str_starts_with($eventKey, 'timesheets.')) {
            $t = Timesheet::query()->find($entityId);
            return $t && in_array($t->status, ['approved', 'rejected'], true);
        }

        return false;
    }

    protected function resolveEscalationRecipients(DatabaseNotification $n, NotificationEscalationRule $rule, int $reminderNumber)
    {
        $ids = collect();

        // Always re-notify the original recipient
        if (!empty($n->notifiable_id) && $n->notifiable_type === User::class) {
            $ids->push((int) $n->notifiable_id);
        }

        // Optionally escalate to additional role groups
        $groups = (array) ($rule->escalate_to_role_groups ?? []);
        $svc = app(NotificationService::class);
        foreach ($groups as $g) {
            $ids = $ids->merge($svc->resolveRoleGroupUserIds((string) $g));
        }

        // Tiered escalation: add more recipients as reminder count increases.
        $tiers = (array) ($rule->tiers ?? []);
        if (!empty($tiers)) {
            // Normalise + sort by from_reminder asc
            usort($tiers, fn($a, $b) => ((int)($a['from_reminder'] ?? 0)) <=> ((int)($b['from_reminder'] ?? 0)));
            foreach ($tiers as $t) {
                $from = (int)($t['from_reminder'] ?? 0);
                if ($from <= 0) continue;
                if ($reminderNumber >= $from) {
                    foreach ((array)($t['role_groups'] ?? []) as $g) {
                        $ids = $ids->merge($svc->resolveRoleGroupUserIds((string) $g));
                    }
                }
            }
        }

        $ids = $ids->unique()->values();
        if ($ids->isEmpty()) return collect();

        return User::query()->whereIn('id', $ids)->get();
    }
}
