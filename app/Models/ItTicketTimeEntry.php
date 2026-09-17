<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Internal ticket work; all access goes through the canonical ticket work boundary. */
class ItTicketTimeEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'after_hours' => 'boolean', 'minutes' => 'integer', 'break_minutes' => 'integer', 'hourly_rate_cents' => 'integer'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'ticket_id');
    }
}
