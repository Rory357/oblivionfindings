<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoom\PlaybookRunStep;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;

/**
 * Safe automation for Control Room alerts.
 *
 * Handles automatic actions that ASSIST operators without replacing them:
 * - Auto-assignment based on queue roles or site primary contact
 * - Auto-starting playbook runs so operators see actionable steps immediately
 * - Escalation-driven watcher addition for management visibility
 *
 * SAFETY RULES:
 * - NEVER auto-resolves or auto-closes alerts
 * - NEVER hides problems
 * - Every automated action is logged to audit trail
 * - Operators can always override automated decisions
 */
class AlertAutomationService
{
    /**
     * Run post-creation automation on a new alert.
     *
     * Called after alert creation + queue + SLA + playbook attachment.
     * Order: auto-assign → auto-start playbook.
     */
    public function onAlertCreated(ControlRoomAlert $alert): void
    {
        $this->autoAssign($alert);
        $this->autoStartPlaybook($alert);
    }

    /**
     * Run post-escalation automation.
     *
     * Called after escalation level is incremented.
     */
    public function onAlertEscalated(ControlRoomAlert $alert, int $previousLevel): void
    {
        // At escalation level 2+, add relevant managers as watchers for visibility
        if ($alert->escalation_level >= 2) {
            $this->addEscalationWatchers($alert);
        }
    }

    /**
     * Auto-assign an alert to the most appropriate available user.
     *
     * Assignment priority (first match wins):
     * 1. Queue has assigned_users → pick by explicit list order (queue config order)
     * 2. Queue has assigned_roles → pick user with matching role, prefer same site
     * 3. Alert has site_id → pick site's primary contact
     * 4. Leave unassigned (operator will manually assign)
     *
     * Does NOT reassign if already assigned.
     */
    public function autoAssign(ControlRoomAlert $alert): void
    {
        // Don't override existing assignment
        if ($alert->assigned_to_user_id) {
            return;
        }

        // Don't assign terminal alerts
        if ($alert->isTerminal()) {
            return;
        }

        $queue = $alert->queue_id ? TriageQueue::find($alert->queue_id) : null;
        $assignee = null;
        $reason = null;

        // 1. Queue-specific users — respect the configured order (first ID = primary)
        if ($queue && !empty($queue->assigned_users)) {
            // Use FIELD ordering to respect the order IDs appear in the config
            $ids = $queue->assigned_users;
            $assignee = User::whereIn('id', $ids)
                ->orderByRaw('FIELD(id, ' . implode(',', array_map('intval', $ids)) . ')')
                ->first();

            if ($assignee) {
                $reason = "queue_user:{$queue->name}";
            }
        }

        // 2. Queue-specific roles — prefer users at the alert's site if available
        if (!$assignee && $queue && !empty($queue->assigned_roles)) {
            $roleQuery = User::whereHas('roles', fn ($q) =>
                $q->whereIn('name', $queue->assigned_roles)
            );

            if ($alert->site_id) {
                // Try site-specific first: user is primary contact for this site
                $assignee = (clone $roleQuery)
                    ->where('id', function ($sub) use ($alert) {
                        $sub->select('primary_contact_user_id')
                            ->from('sites')
                            ->where('id', $alert->site_id)
                            ->whereNotNull('primary_contact_user_id');
                    })
                    ->first();

                if ($assignee) {
                    $reason = "queue_role_site:{$queue->name}";
                }
            }

            // Fallback: any user with the role
            if (!$assignee) {
                $assignee = $roleQuery->orderBy('name')->first();

                if ($assignee) {
                    $reason = "queue_role:{$queue->name}";
                }
            }
        }

        // 3. Site primary contact (if alert has site_id, no queue match)
        if (!$assignee && $alert->site_id) {
            $site = Site::find($alert->site_id);

            if ($site && $site->primary_contact_user_id) {
                $assignee = User::find($site->primary_contact_user_id);

                if ($assignee) {
                    $reason = 'site_primary_contact';
                }
            }
        }

        if (!$assignee) {
            return; // No match — leave unassigned for manual triage
        }

        $alert->update([
            'assigned_to_user_id' => $assignee->id,
            'assigned_at' => now(),
            'context' => array_merge($alert->context ?? [], [
                'auto_assigned' => true,
                'auto_assign_reason' => $reason,
                'auto_assign_at' => now()->toIso8601String(),
            ]),
        ]);

        AuditLogger::log('controlRoom.alert.autoAssigned', $alert, [
            'alert_id' => $alert->id,
            'assigned_to' => $assignee->id,
            'assigned_to_name' => $assignee->name,
            'reason' => $reason,
        ]);

        Log::info('AlertAutomation: auto-assigned', [
            'alert_id' => $alert->id,
            'assigned_to' => $assignee->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Auto-start a playbook run if one is attached and pending.
     *
     * Initialises run steps and sets the first step to in_progress.
     * This saves operators from having to manually click "Start Playbook"
     * before they can begin following the SOP steps.
     *
     * Uses PlaybookRun::initialiseSteps() to avoid duplicating step creation
     * logic between here and the manual start path.
     */
    public function autoStartPlaybook(ControlRoomAlert $alert): void
    {
        if (!$alert->playbook_run_id) {
            return;
        }

        $run = PlaybookRun::with('playbook.steps')->find($alert->playbook_run_id);

        if (!$run || $run->status !== 'pending') {
            return; // Already started, completed, or cancelled
        }

        // Auto-start without a user (system-initiated)
        $run->update([
            'status' => 'in_progress',
            'started_at' => now(),
            // started_by_user_id intentionally null — system-initiated
        ]);

        $run->initialiseSteps();

        AuditLogger::log('controlRoom.playbook.autoStarted', $alert, [
            'alert_id' => $alert->id,
            'playbook_run_id' => $run->id,
            'playbook_name' => $run->playbook->name,
            'total_steps' => $run->total_steps,
        ]);

        Log::info('AlertAutomation: playbook auto-started', [
            'alert_id' => $alert->id,
            'playbook_run_id' => $run->id,
            'playbook' => $run->playbook->name,
        ]);
    }

    /**
     * Add escalation watchers to increase visibility at higher escalation levels.
     *
     * At level 2+, adds managers as watchers. If the alert has a site_id,
     * prefers the site's primary contact. Always includes admin/provider_manager
     * roles as a baseline.
     */
    protected function addEscalationWatchers(ControlRoomAlert $alert): void
    {
        $watcherUserIds = collect();

        // Site primary contact gets visibility on their site's escalated alerts
        if ($alert->site_id) {
            $site = Site::find($alert->site_id);
            if ($site?->primary_contact_user_id) {
                $watcherUserIds->push($site->primary_contact_user_id);
            }
        }

        // Admin/provider_manager always get visibility on escalated alerts
        $managerIds = User::whereHas('roles', fn ($q) =>
                $q->whereIn('name', ['admin', 'provider_manager'])
            )
            ->pluck('id');

        $watcherUserIds = $watcherUserIds->merge($managerIds)->unique();

        if ($watcherUserIds->isEmpty()) {
            return;
        }

        $existingWatcherIds = $alert->watchers()->pluck('user_id');
        $newWatcherIds = $watcherUserIds->diff($existingWatcherIds);

        foreach ($newWatcherIds as $userId) {
            $alert->watchers()->firstOrCreate(
                ['user_id' => $userId],
                ['added_by_user_id' => null] // system-added
            );
        }

        if ($newWatcherIds->isNotEmpty()) {
            AuditLogger::log('controlRoom.alert.autoWatchers', $alert, [
                'alert_id' => $alert->id,
                'escalation_level' => $alert->escalation_level,
                'watchers_added' => $newWatcherIds->count(),
                'includes_site_contact' => $alert->site_id && $watcherUserIds->contains(
                    Site::find($alert->site_id)?->primary_contact_user_id
                ),
            ]);
        }
    }
}
