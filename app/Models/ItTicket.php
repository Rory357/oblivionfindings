<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A helpdesk ticket, raised self-service by any staff member (source
 * `portal`) or logged by an agent (`agent`). Carries the conversation
 * thread, watcher list, SLA clock and CSAT outcome; `waiting` status pauses
 * the resolution clock while the ball is in the requester's court.
 */
class ItTicket extends Model
{
    use AuditableChanges, HasFactory;

    public const CATEGORIES = ['hardware', 'account', 'network', 'other'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUSES = ['open', 'in_progress', 'waiting', 'resolved', 'closed'];

    public const SOURCES = ['portal', 'agent', 'system'];

    public const SLA_STATES = ['ok', 'at_risk', 'breached', 'met'];

    protected $fillable = [
        'tenant_id',
        'reference',
        'title',
        'description',
        'requester_user_id',
        'assigned_to_user_id',
        'asset_id',
        'provisioning_request_id',
        'category',
        'subcategory',
        'source',
        'priority',
        'status',
        'first_response_due_at',
        'resolution_due_at',
        'first_responded_at',
        'sla_state',
        'sla_paused_minutes',
        'waiting_since',
        'resolved_at',
        'closed_at',
        'reopened_count',
        'csat_score',
        'csat_comment',
        'csat_submitted_at',
    ];

    protected $casts = [
        'first_response_due_at' => 'datetime',
        'resolution_due_at' => 'datetime',
        'first_responded_at' => 'datetime',
        'waiting_since' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'csat_submitted_at' => 'datetime',
        'sla_paused_minutes' => 'integer',
        'reopened_count' => 'integer',
        'csat_score' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** Linked entry in the canonical (fleet-)assets register. */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    /** The provisioning request this ticket was raised from, if any. */
    public function provisioningRequest(): BelongsTo
    {
        return $this->belongsTo(ItProvisioningRequest::class, 'provisioning_request_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ItTicketComment::class, 'ticket_id');
    }

    public function events(): MorphMany
    {
        return $this->morphMany(ItTicketEvent::class, 'subject');
    }

    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'it_ticket_watchers', 'ticket_id', 'user_id')
            ->withTimestamps();
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
