<?php

namespace App\Domain\SecurityDevices\Alerts\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on an alert's timeline: opened, who was told, a reminder, acknowledged, assigned,
 * snoozed, back from snooze, recovered, an incident reported. Written once, never changed.
 *
 * @property int $alert_id
 * @property string $kind
 * @property string $text
 */
final class DeviceAlertEvent extends Model
{
    protected $table = 'security_device_alert_events';

    protected $fillable = ['alert_id', 'at', 'kind', 'actor_user_id', 'text', 'detail'];

    protected $casts = [
        'at' => 'immutable_datetime',
    ];

    public function deviceAlert(): BelongsTo
    {
        return $this->belongsTo(DeviceAlert::class, 'alert_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
