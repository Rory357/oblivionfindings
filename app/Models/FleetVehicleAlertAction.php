<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The vehicle profile's ledger of what was done about a vehicle's Control
 * Room responses: recorded events sent, lifecycle actions, triage decisions,
 * the linked Maintenance work and follow-up reminders. Control Room keeps its
 * own record; rows here are only added.
 */
class FleetVehicleAlertAction extends Model
{
    protected $fillable = [
        'asset_id',
        'control_room_alert_id',
        'fleet_signal_id',
        'action',
        'decision',
        'outcome',
        'work_order_id',
        'reminder_id',
        'note',
        'evidence',
        'source_key',
        'actor_user_id',
        'request_key',
        'request_fingerprint',
    ];

    protected $casts = [
        'evidence' => 'array',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class, 'control_room_alert_id');
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(FleetSignal::class, 'fleet_signal_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(FleetWorkOrder::class, 'work_order_id');
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(FleetVehicleReminder::class, 'reminder_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
