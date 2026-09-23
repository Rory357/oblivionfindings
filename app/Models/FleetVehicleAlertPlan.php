<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A version of a vehicle's draft response plan: who responds and the
 * thresholds to review. A draft is not a live device setting; activation
 * stays pending until an approved operating policy exists.
 */
class FleetVehicleAlertPlan extends Model
{
    protected $fillable = [
        'asset_id',
        'version',
        'owner_user_id',
        'backup_user_id',
        'speed_threshold_kph',
        'speed_tolerance_kph',
        'speed_duration_s',
        'speed_cooldown_s',
        'offline_minutes',
        'low_voltage_v',
        'low_voltage_minutes',
        'notes',
        'reason',
        'created_by_user_id',
        'request_key',
        'request_fingerprint',
    ];

    protected $casts = [
        'version' => 'integer',
        'speed_threshold_kph' => 'integer',
        'speed_tolerance_kph' => 'integer',
        'speed_duration_s' => 'integer',
        'speed_cooldown_s' => 'integer',
        'offline_minutes' => 'integer',
        'low_voltage_v' => 'float',
        'low_voltage_minutes' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(User::class, 'backup_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
