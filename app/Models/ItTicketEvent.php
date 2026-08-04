<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only activity trail for the IT module. Polymorphic: tickets AND
 * provisioning requests share this table (per the pre-approved schema), so
 * one timeline component can render either. Rows are never updated —
 * created_at only.
 */
class ItTicketEvent extends Model
{
    use WritesLegacyStorageContext;

    public const UPDATED_AT = null;

    protected $fillable = [
        'subject_type',
        'subject_id',
        'actor_user_id',
        'type',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Record an activity row for a ticket or provisioning request. The
     * single write-path every IT mutation funnels through, so the timeline
     * stays complete without each caller hand-rolling inserts.
     */
    public static function record(
        ItTicket|ItProvisioningRequest $subject,
        string $type,
        ?int $actorUserId = null,
        array $payload = [],
    ): self {
        return $subject->events()->create([
            'actor_user_id' => $actorUserId,
            'type' => $type,
            'payload' => $payload === [] ? null : $payload,
        ]);
    }
}
