<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One message on a helpdesk ticket's conversation thread. Public replies are
 * visible to the requester; internal notes (is_internal) are agent-only and
 * MUST be stripped from requester payloads server-side — never UI-hidden.
 */
class ItTicketComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'ticket_id',
        'author_user_id',
        'body',
        'is_internal',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(ItAttachment::class, 'attachable');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    /** Only what a requester may see. */
    public function scopePublicOnly($query)
    {
        return $query->where('is_internal', false);
    }
}
