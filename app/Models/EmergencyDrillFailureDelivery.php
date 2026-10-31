<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Separate actual H&S review and Control Room signal receipts. No sensitive payload copy. */
class EmergencyDrillFailureDelivery extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['attempts' => 'integer', 'next_attempt_at' => 'datetime', 'event_delivered_at' => 'datetime', 'signal_delivered_at' => 'datetime'];
}
