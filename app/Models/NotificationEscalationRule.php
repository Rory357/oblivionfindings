<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
