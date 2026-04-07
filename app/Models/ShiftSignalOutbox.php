<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftSignalOutbox extends Model
{
    protected $table = 'shift_signal_outbox';

    protected $fillable = [
        'shift_signal_id',
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
        return $this->belongsTo(ShiftSignal::class, 'shift_signal_id');
    }
}
