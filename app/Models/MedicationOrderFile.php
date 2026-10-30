<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationOrderFile extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['file_path'];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(MedicationOrderRevision::class, 'medication_order_revision_id');
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(MedicationReconciliation::class, 'medication_reconciliation_id');
    }
}
