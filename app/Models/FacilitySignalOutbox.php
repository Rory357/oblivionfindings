<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacilitySignalOutbox extends Model
{
    protected $table = 'facility_signal_outbox';

    protected $fillable = [
        'facility_signal_id',
        'status',
        'attempts',
        'last_attempt_at',
        'last_error',
    ];

    protected $casts = [
        'last_attempt_at' => 'datetime',
    ];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(FacilitySignal::class, 'facility_signal_id');
    }
}
