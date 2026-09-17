<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Internal ticket work; all access goes through the canonical ticket work boundary. */
class ItTicketBooking extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'encrypted:array', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'ticket_id');
    }
}
