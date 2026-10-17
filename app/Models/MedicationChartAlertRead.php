<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** eMAR audit Lane A5 (EA-005): a worker read a "Prompt staff" chart alert on an NZ day. */
class MedicationChartAlertRead extends Model
{
    protected $fillable = [
        'client_medication_alert_id',
        'user_id',
        'read_on',
        'read_at',
    ];

    protected $casts = [
        'read_on' => 'date:Y-m-d',
        'read_at' => 'datetime',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(ClientMedicationAlert::class, 'client_medication_alert_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
