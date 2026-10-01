<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of an order's schedule as the dose-slot rules see it
 * (P01 foundation C3). Written by DoseScheduleHistory from order changes.
 */
class MedicationDoseScheduleVersion extends Model
{
    protected $fillable = [
        'client_medication_id',
        'dose_times',
        'start_date',
        'end_date',
        'is_prn',
        'self_managed',
        'changed_at',
        'verified_at',
        'rejected_at',
    ];

    protected $casts = [
        'dose_times' => 'array',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'is_prn' => 'boolean',
        'self_managed' => 'boolean',
        'changed_at' => 'datetime',
        'verified_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id')->withTrashed();
    }
}
