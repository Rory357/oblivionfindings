<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoom\Communication;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Notifications\ControlRoomAlertNotification;
use App\Services\NotificationService;
use Illuminate\Support\Collection;

/**
 * Central notification service for Control Room alerts.
 *
 * All operational notifications related to CR alerts must flow through this service.
 * This is the ONLY place where escalation-driven notifications originate.
 *
 * Notifications are a CONSEQUENCE of alert state changes (creation, escalation,
 * SLA breach, queue movement) — they do NOT drive escalation.
 */
class ControlRoomNotificationService
{
    /**
     * Notify on initial alert creation.
     */
    public function notifyAlert(ControlRoomAlert $alert, ?SignalRule $rule, ?TriageQueue $queue): void
    {
        $users = $this->resolveUsers($rule, $queue);
        if ($users->isEmpty()) {
            return;
        }

        $this->sendToUsers($alert, $users, 'notification');
    }

    /**
     * Notify on SLA breach escalation.
     *
     * Sends notifications to the roles defined in the SlaDefinition's
     * breach_notify_roles, plus the alert's current queue assigned roles.
     */
    public function notifySlaBreachEscalation(
        ControlRoomAlert $alert,
        SlaDefinition $slaDefinition,
        array $breachTypes,
    ): void {
        $roles = collect($slaDefinition->breach_notify_roles ?? []);

        // Also notify the current queue's assigned roles
        if ($alert->queue_id) {
            $queue = TriageQueue::find($alert->queue_id);
            if ($queue?->assigned_roles) {
                $roles = $roles->merge($queue->assigned_roles);
            }
        }

        $users = $this->resolveUsersByRoles($roles->unique()->values()->toArray());
        if ($users->isEmpty()) {
            return;
        }

        $breachLabel = implode(', ', $breachTypes);

        $this->sendToUsers($alert, $users, 'escalation', [
            'escalation_reason' => "SLA breach: {$breachLabel}",
            'breach_types' => $breachTypes,
            'escalation_level' => $alert->escalation_level,
        ]);
    }

    /**
     * Notify on queue auto-escalation.
     *
     * Sends notifications to the target queue's assigned roles.
     */
    public function notifyQueueEscalation(
        ControlRoomAlert $alert,
        TriageQueue $fromQueue,
        TriageQueue $toQueue,
    ): void {
        $roles = collect($toQueue->assigned_roles ?? []);
        $users = $this->resolveUsersByRoles($roles->unique()->values()->toArray());
        if ($users->isEmpty()) {
            return;
        }

        $this->sendToUsers($alert, $users, 'escalation', [
            'escalation_reason' => "Auto-escalated from {$fromQueue->name} to {$toQueue->name}",
            'from_queue' => $fromQueue->name,
            'to_queue' => $toQueue->name,
            'escalation_level' => $alert->escalation_level,
        ]);
    }

    /**
     * Send notifications to a set of users and log as Communication records.
     */
    protected function sendToUsers(
        ControlRoomAlert $alert,
        Collection $users,
        string $purpose,
        array $extraContext = [],
    ): void {
        foreach ($users as $user) {
            $user->notify(new ControlRoomAlertNotification($alert, $extraContext));

            Communication::create([
                'alert_id' => $alert->id,
                'channel' => 'in_app',
                'direction' => 'outbound',
                'purpose' => $purpose,
                'target_user_id' => $user->id,
                'content' => $this->buildContent($alert, $purpose, $extraContext),
                'status' => 'sent',
                'sent_at' => now(),
                'initiated_by_user_id' => null,
            ]);
        }
    }

    /**
     * Build human-readable content for Communication log.
     */
    protected function buildContent(ControlRoomAlert $alert, string $purpose, array $context): string
    {
        $base = "Alert {$alert->alert_type} ({$alert->severity})";

        if ($purpose === 'escalation' && isset($context['escalation_reason'])) {
            return "{$base} — {$context['escalation_reason']}";
        }

        return $base;
    }

    /**
     * Resolve users from a SignalRule and/or TriageQueue.
     */
    protected function resolveUsers(?SignalRule $rule, ?TriageQueue $queue): Collection
    {
        $userIds = collect();

        if ($rule?->notify_users) {
            $userIds = $userIds->merge($rule->notify_users);
        }

        $roles = collect();
        if ($rule?->notify_roles) {
            $roles = $roles->merge($rule->notify_roles);
        }
        if ($queue?->assigned_roles) {
            $roles = $roles->merge($queue->assigned_roles);
        }

        $users = User::query()
            ->when($userIds->isNotEmpty(), function ($q) use ($userIds) {
                $q->whereIn('id', $userIds->unique()->values());
            })
            ->when($roles->isNotEmpty(), function ($q) use ($roles) {
                $q->orWhereHas('roles', fn ($rq) => $rq->whereIn('name', $roles->unique()->values()));
            })
            ->get(['id', 'name', 'email']);

        return $users->unique('id')->values();
    }

    /**
     * Resolve users by role names.
     */
    protected function resolveUsersByRoles(array $roleNames): Collection
    {
        if (empty($roleNames)) {
            return collect();
        }

        // Resolve role group names to actual role names
        $svc = app(NotificationService::class);
        $userIds = collect();
        foreach ($roleNames as $roleName) {
            $userIds = $userIds->merge($svc->resolveRoleGroupUserIds($roleName));
        }

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::whereIn('id', $userIds->unique()->values())
            ->get(['id', 'name', 'email']);
    }
}
