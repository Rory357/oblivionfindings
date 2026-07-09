<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An inbound email the helpdesk ingested (§P-S4). `processed` created or
 * threaded a ticket; `unmatched` couldn't be tied to a requester/ticket;
 * `rejected` failed signature/validation. Body is a short preview only.
 */
class ItInboundEmail extends Model
{
    use HasFactory;

    public const STATUSES = ['processed', 'unmatched', 'rejected'];

    protected $fillable = [
        'tenant_id',
        'it_ticket_id',
        'from_email',
        'subject',
        'message_id',
        'in_reply_to',
        'body_preview',
        'status',
        'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'it_ticket_id');
    }
}
