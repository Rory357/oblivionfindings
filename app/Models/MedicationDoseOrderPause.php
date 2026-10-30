<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A period an order was paused (P01 foundation C3): no dose is owed while
 * paused. Written by DoseScheduleHistory from the order's state changes.
 */
class MedicationDoseOrderPause extends Model
{
    protected $fillable = [
        'client_medication_id',
        'paused_at',
        'resumed_at',
    ];

    protected $casts = [
        'paused_at' => 'datetime',
        'resumed_at' => 'datetime',
    ];
}
