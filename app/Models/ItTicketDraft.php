<?php

namespace App\Models;

use App\Domain\It\Enums\ItTicketDraftPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** One actor-owned recovery slot; this is never a ticket or command receipt. */
class ItTicketDraft extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['encrypted_payload', 'payload_hash', 'bound_scope'];

    protected function casts(): array
    {
        return [
            'purpose' => ItTicketDraftPurpose::class,
            'encrypted_payload' => 'encrypted:array',
            'bound_scope' => 'array',
            'revision' => 'integer',
            'last_base_revision' => 'integer',
            'base_ticket_version' => 'integer',
            'saved_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'discarded_at' => 'immutable_datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'it_ticket_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(ItAttachment::class, 'attachable');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->lessThanOrEqualTo(now());
    }
}
