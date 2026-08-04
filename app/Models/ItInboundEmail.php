<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An inbound email the helpdesk ingested (§P-S4). `processed` created or
 * threaded a ticket; `quarantined` was retained as bounded evidence without
 * message content; `rejected` failed transport signature/validation.
 */
class ItInboundEmail extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    public const STATUSES = ['processed', 'quarantined', 'unmatched', 'rejected'];

    protected $fillable = [
        'it_ticket_id',
        'from_email',
        'subject',
        'message_id',
        'in_reply_to',
        'body_preview',
        'status',
        'quarantine_reason',
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
