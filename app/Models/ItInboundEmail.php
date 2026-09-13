<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * An inbound email the helpdesk ingested (§P-S4). `processed` created or
 * threaded a ticket; `quarantined` was retained as bounded evidence without
 * message content; `rejected` failed transport signature/validation.
 */
class ItInboundEmail extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    public const STATUSES = ['pending', 'processed', 'quarantined', 'unmatched', 'rejected', 'duplicate'];

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
        'remote_message_id' => 'encrypted',
        'acknowledged_at' => 'datetime',
        'transport_retry_at' => 'datetime',
        'processing_attempts' => 'integer',
        'acknowledgement_attempts' => 'integer',
        'attachment_expected_count' => 'integer',
        'quarantine_review_version' => 'integer',
        'quarantine_retry_requested_at' => 'datetime',
        'normalized_message_id' => 'encrypted',
        'parent_message_ids' => 'encrypted:array',
        'reference_message_ids' => 'encrypted:array',
    ];

    protected $hidden = ['remote_message_id', 'transport_key', 'mailbox_scope_hash',
        'message_identity_hash', 'identity_claim_key', 'message_content_hash',
        'normalized_message_id', 'parent_message_ids', 'reference_message_ids', 'attachment_manifest_hash', 'attachment_expected_count'];

    public function attachments(): MorphMany
    {
        return $this->morphMany(ItAttachment::class, 'attachable');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'it_ticket_id');
    }
}
