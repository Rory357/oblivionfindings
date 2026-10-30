<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationReconciliationItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'controlled' => 'boolean', 'source_order' => 'array', 'last_dose_evidence' => 'array',
        'next_dose_at' => 'datetime', 'applied_at' => 'datetime', 'prescriber_query_resolved_at' => 'datetime',
    ];

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(MedicationReconciliation::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id');
    }
}
