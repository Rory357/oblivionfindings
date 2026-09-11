<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetSignalOutbox extends Model
{
    protected $table = 'fleet_signal_outbox';

    protected $fillable = [
        'fleet_signal_id',
        'status',
        'attempts',
        'last_attempt_at',
        'last_error',
        'it_status',
        'it_attempts',
        'it_attempt_limit',
        'it_signal_id',
        'it_scope',
        'it_outcome_code',
        'it_ticket_ids',
        'it_last_attempt_at',
        'it_completed_at',
    ];

    protected $casts = [
        'last_attempt_at' => 'datetime',
        'it_attempts' => 'integer',
        'it_attempt_limit' => 'integer',
        'it_signal_id' => 'integer',
        'it_scope' => 'array',
        'it_ticket_ids' => 'array',
        'it_last_attempt_at' => 'datetime',
        'it_completed_at' => 'datetime',
    ];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(FleetSignal::class, 'fleet_signal_id');
    }
}
