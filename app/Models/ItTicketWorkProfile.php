<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Internal ticket work; all access goes through the canonical ticket work boundary. */
class ItTicketWorkProfile extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'encrypted:array'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'ticket_id');
    }
}
