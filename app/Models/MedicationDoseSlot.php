<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scheduled dose obligation: an order × NZ calendar day × ordered time
 * (P01 foundation C3). Written only by the dose-slot generator and the
 * outcome writer; nothing reads it yet (C6).
 */
class MedicationDoseSlot extends Model
{
    protected $fillable = [
        'client_id',
        'client_medication_id',
        'schedule_version_id',
        'nz_date',
        'ordered_time',
        'due_at',
        'controlled',
        'order_change_pending',
        'dst_adjustment',
        'self_managed',
        'last_day',
        'superseded_at',
        'outcome',
        'outcome_administration_id',
        'outcome_at',
        'generated_at',
    ];

    protected $casts = [
        'nz_date' => 'date:Y-m-d',
        'due_at' => 'datetime',
        'controlled' => 'boolean',
        'order_change_pending' => 'boolean',
        'self_managed' => 'boolean',
        'last_day' => 'boolean',
        'superseded_at' => 'datetime',
        'outcome_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id')->withTrashed();
    }

    public function scheduleVersion(): BelongsTo
    {
        return $this->belongsTo(MedicationDoseScheduleVersion::class, 'schedule_version_id');
    }

    public function outcomeAdministration(): BelongsTo
    {
        return $this->belongsTo(ClientMedicationAdministration::class, 'outcome_administration_id');
    }
}
