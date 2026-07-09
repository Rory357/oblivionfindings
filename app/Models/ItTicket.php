<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Support\It\BusinessHours;
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

    public const SOURCES = ['portal', 'agent', 'system', 'email'];

    public const SLA_STATES = ['ok', 'at_risk', 'breached', 'met'];

    /** Statuses that count as "open" for queues, badges and saved views. */
    public const OPEN_STATUSES = ['open', 'in_progress', 'waiting'];

    protected $fillable = [
        'tenant_id',
        'reference',
        'title',
        'description',
        'requester_user_id',
        'assigned_to_user_id',
        'asset_id',
        'provisioning_request_id',
        'merged_into_ticket_id',
        'merged_at',
        'category',
        'subcategory',
        'source',
        'priority',
        'status',
        'requires_approval',
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
        'merged_at' => 'datetime',
        'csat_submitted_at' => 'datetime',
        'sla_paused_minutes' => 'integer',
        'reopened_count' => 'integer',
        'csat_score' => 'integer',
        'requires_approval' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Reference generation */
    /* ------------------------------------------------------------------ */

    protected static function booted(): void
    {
        // Every ticket gets a human-facing reference (IT-000123) — filled
        // here so factories and secondary write paths never miss it. The
        // tenant-unique index is the backstop; createWithReference() adds
        // the retry for genuinely concurrent creates.
        static::creating(function (self $ticket) {
            if (! $ticket->reference && $ticket->tenant_id) {
                $ticket->reference = static::nextReference((int) $ticket->tenant_id);
            }
        });
    }

    /** Next per-tenant sequence value, based on the highest stamped so far. */
    public static function nextReference(int $tenantId): string
    {
        $max = (int) static::query()
            ->forTenant($tenantId)
            ->whereNotNull('reference')
            ->selectRaw("MAX(CAST(SUBSTRING(reference, 4) AS UNSIGNED)) AS seq")
            ->value('seq');

        return sprintf('IT-%06d', $max + 1);
    }

    /**
     * Create with a race-safe reference: two requests computing the same
     * next sequence collide on the unique index — the loser recomputes and
     * retries instead of surfacing a 500.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createWithReference(array $attributes): self
    {
        unset($attributes['reference']); // always generated, never client-supplied

        $attempts = 0;
        do {
            try {
                return static::create($attributes);
            } catch (\Illuminate\Database\QueryException $exception) {
                $attempts++;
                $collidedOnReference = str_contains($exception->getMessage(), 'it_tickets_tenant_reference_uq');
                if (! $collidedOnReference || $attempts >= 5) {
                    throw $exception;
                }
            }
        } while (true);
    }

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

    /** The survivor this ticket was merged into (a duplicate points here). */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'merged_into_ticket_id');
    }

    /** Duplicate tickets folded into this one. */
    public function mergedTickets(): HasMany
    {
        return $this->hasMany(ItTicket::class, 'merged_into_ticket_id');
    }

    /** True once this ticket has been folded into a survivor. */
    public function isMerged(): bool
    {
        return $this->merged_into_ticket_id !== null;
    }

    /** Sign-off requests on this ticket (§P-S3), newest first. */
    public function approvals(): HasMany
    {
        return $this->hasMany(ItTicketApproval::class, 'it_ticket_id')->latest('id');
    }

    /** Whether a category is configured to need a manager's approval. */
    public static function categoryNeedsApproval(?string $category): bool
    {
        return $category !== null
            && in_array($category, (array) config('it.approval.categories', []), true);
    }

    /**
     * The current approval verdict for the gate: 'approved' clears it,
     * 'pending'/'rejected' blocks it, null when none has been requested.
     */
    public function approvalState(): ?string
    {
        return $this->approvals()->value('status');
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

    /** Files attached at raise time (thread replies carry their own). */
    public function attachments(): MorphMany
    {
        return $this->morphMany(ItAttachment::class, 'attachable');
    }

    /* ------------------------------------------------------------------ */
    /*  SLA stamping */
    /* ------------------------------------------------------------------ */

    /**
     * Stamp/restamp the SLA due dates from the tenant policy for the
     * ticket's CURRENT priority, anchored at creation — a priority change
     * re-targets the same clock, it doesn't restart it. Mutates without
     * saving; callers persist.
     */
    public function stampSlaDueDates(): void
    {
        [$firstResponseMinutes, $resolutionMinutes] = ItSlaPolicy::minutesFor(
            (int) $this->tenant_id,
            (string) $this->priority,
        );

        $calendar = ItSlaPolicy::calendarFor((int) $this->tenant_id, (string) $this->priority);
        $anchor = $this->created_at ?? now();

        // Working-time targets when the tenant set a business-hours calendar;
        // a null calendar keeps the continuous 24/7 clock (unchanged). ->utc()
        // so a worker-timezone result stores as the correct instant.
        $this->first_response_due_at = BusinessHours::addWorkingMinutes($anchor, $firstResponseMinutes, $calendar)->utc();
        $this->resolution_due_at = BusinessHours::addWorkingMinutes($anchor, $resolutionMinutes, $calendar)->utc();
    }

    /* ------------------------------------------------------------------ */
    /*  Waiting clock (SLA pause/resume) */
    /* ------------------------------------------------------------------ */

    /**
     * Enter "waiting on requester": the resolution clock pauses from now.
     * Mutates without saving — callers batch it into their own update().
     */
    public function startWaiting(): void
    {
        $this->status = 'waiting';
        $this->waiting_since = $this->waiting_since ?? now();
    }

    /**
     * Leave "waiting on requester" (requester replied, or an agent moved it
     * on): bank the paused minutes so SLA maths exclude them, clear the
     * marker. Mutates without saving.
     */
    public function stopWaiting(string $nextStatus = 'in_progress'): void
    {
        if ($this->waiting_since) {
            $this->sla_paused_minutes = (int) $this->sla_paused_minutes
                + (int) $this->waiting_since->diffInMinutes(now());
            $this->waiting_since = null;
        }
        $this->status = $nextStatus;
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
