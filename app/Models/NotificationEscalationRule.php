<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Notification escalation rule for WORKFLOW events only.
 *
 * ARCHITECTURAL NOTE (PR6):
 * This model configures reminder/escalation behaviour for WORKFLOW notifications
 * (timesheets, leave approvals, expenses, onboarding, etc.).
 *
 * OPERATIONAL alert escalation (incidents, medication, lone worker, safety events)
 * is handled exclusively by the Control Room escalation engine:
 *   - CheckControlRoomSlaBreaches (SLA-driven escalation)
 *   - AutoEscalateControlRoomQueues (queue-driven escalation)
 *   - ControlRoomNotificationService (escalation notifications)
 *
 * Rules with operational event keys (incidents.*, followups.*, controlroom.*,
 * medication.*, lone_worker.*, safeguarding.*, hazard.*) are SKIPPED by
 * EscalatePendingNotifications. Do NOT add operational event keys here.
 *
 * @see \App\Jobs\CheckControlRoomSlaBreaches — canonical SLA escalation
 * @see \App\Jobs\AutoEscalateControlRoomQueues — canonical queue escalation
 */
class NotificationEscalationRule extends Model
{
    protected $fillable = [
        'event_key',
        'enabled',
        'require_ack',
        'force_delivery',
        'must_ack_before_close',
        'remind_after_minutes',
        'repeat_every_minutes',
        'max_reminders',
        'escalate_to_role_groups',
        'tiers',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'require_ack' => 'boolean',
        'force_delivery' => 'boolean',
        'must_ack_before_close' => 'boolean',
        'remind_after_minutes' => 'integer',
        'repeat_every_minutes' => 'integer',
        'max_reminders' => 'integer',
        'escalate_to_role_groups' => 'array',
        'tiers' => 'array',
    ];
}
