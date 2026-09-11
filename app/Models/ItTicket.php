<?php

namespace App\Models;

use App\Domain\It\Services\ItSlaClockService;
use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Services\References\ReferenceNumberGenerator;
use App\Support\It\BusinessHours;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * A helpdesk ticket, raised self-service by any staff member (source
 * `portal`) or logged by an agent (`agent`). Carries the conversation
 * thread, watcher list, SLA clock and CSAT outcome; `waiting` status pauses
 * the resolution clock while the ball is in the requester's court.
 */
class ItTicket extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    public const CATEGORIES = ['hardware', 'account', 'network', 'other'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUSES = ['open', 'in_progress', 'waiting', 'resolved', 'closed'];

    public const SOURCES = ['portal', 'agent', 'system', 'email'];

    public const WORK_TYPES = [
        'incident',
        'service_request',
        'problem',
        'change',
        'task',
        'security_request',
        'major_incident',
    ];

    /** Work types that may enter through the ordinary helpdesk intake/triage journey. */
    public const INTAKE_WORK_TYPES = [
        'incident',
        'service_request',
        'security_request',
    ];

    public const IMPACTS = ['individual', 'team', 'site', 'organization'];

    public const URGENCIES = ['low', 'normal', 'high', 'critical'];

    public const SLA_STATES = ['ok', 'at_risk', 'breached', 'met', 'paused', 'unmeasured'];

    /** Statuses that count as "open" for queues, badges and saved views. */
    public const OPEN_STATUSES = ['open', 'in_progress', 'waiting'];

    protected $attributes = ['lock_version' => 1];

    protected $fillable = [
        'reference',
        'title',
        'description',
        'requester_user_id',
        'requested_for_user_id',
        'assigned_to_user_id',
        'owner_user_id',
        'asset_id',
        'site_id',
        'is_organisation_wide',
        'team_id',
        'queue_id',
        'it_service_id',
        'provisioning_request_id',
        'merged_into_ticket_id',
        'merged_at',
        'category',
        'subcategory',
        'source',
        'work_type',
        'workflow_state',
        'priority',
        'impact',
        'urgency',
        'priority_decision',
        'routing_decision',
        'routing_override',
        'is_sensitive',
        'status',
        'status_reason',
        'waiting_reason',
        'waiting_party',
        'next_action',
        'requires_approval',
        'first_response_due_at',
        'resolution_due_at',
        'due_at',
        'first_responded_at',
        'next_response_party',
        'sla_original_policy_snapshot',
        'sla_policy_snapshot',
        'first_response_breached_at',
        'resolution_breached_at',
        'sla_checked_at',
        'sla_state',
        'sla_paused_minutes',
        'waiting_since',
        'resolved_at',
        'resolution_code',
        'resolution_summary',
        'resolution_verification',
        'monitoring_recovered_at',
        'closed_at',
        'reopened_count',
        'csat_score',
        'csat_comment',
        'csat_submitted_at',
    ];

    protected $casts = [
        'lock_version' => 'integer',
        'priority_decision' => 'array',
        'routing_decision' => 'array',
        'routing_override' => 'array',
        'first_response_due_at' => 'datetime',
        'resolution_due_at' => 'datetime',
        'first_responded_at' => 'datetime',
        'last_public_comment_id' => 'integer',
        'last_public_commented_at' => 'datetime',
        'sla_original_policy_snapshot' => 'array',
        'sla_policy_snapshot' => 'array',
        'first_response_breached_at' => 'datetime',
        'resolution_breached_at' => 'datetime',
        'sla_checked_at' => 'datetime',
        'waiting_since' => 'datetime',
        'resolved_at' => 'datetime',
        'monitoring_recovered_at' => 'datetime',
        'closed_at' => 'datetime',
        'merged_at' => 'datetime',
        'csat_submitted_at' => 'datetime',
        'sla_paused_minutes' => 'integer',
        'reopened_count' => 'integer',
        'csat_score' => 'integer',
        'requires_approval' => 'boolean',
        'is_sensitive' => 'boolean',
        'is_organisation_wide' => 'boolean',
        'due_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Reference generation */
    /* ------------------------------------------------------------------ */

    protected static function booted(): void
    {
        // Every ticket gets a human-facing reference (IT-000123) — filled
        // here so factories and secondary write paths never miss it. The
        // application-global index is the final backstop.
        static::creating(function (self $ticket) {
            if (! $ticket->reference) {
                $ticket->reference = static::nextReference();
            }
        });
    }

    /**
     * Allocate versions from the locked persisted row, never an older model
     * instance. Canonical services own intent checks and lifecycle decisions;
     * this narrow wrapper keeps every Eloquent ticket write monotonic while
     * retaining Laravel's update events, dirty fields and audit behaviour.
     * Ticket mutations must use the model, not mass query-builder updates.
     */
    protected function performUpdate(Builder $query)
    {
        return $this->getConnection()->transaction(function () use ($query): bool {
            $current = $this->setKeysForSaveQuery(clone $query)
                ->lockForUpdate()->firstOrFail(['lock_version']);
            $previousVersion = $this->lock_version;
            $this->lock_version = (int) $current->lock_version + 1;
            $saved = parent::performUpdate($query);
            if (! $saved) {
                $this->lock_version = $previousVersion;
            }

            return $saved;
        });
    }

    /** Conversation responsibility is independent of the first-response SLA clock. */
    public function scopeAwaitingIt(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), self::OPEN_STATUSES)
            ->whereNull($this->qualifyColumn('merged_into_ticket_id'))
            ->where($this->qualifyColumn('next_response_party'), 'it');
    }

    /** Preserve existing pages while an additive schema change is pending. */
    public static function hasConversationEvidence(): bool
    {
        return Schema::hasColumn((new static)->getTable(), 'next_response_party');
    }

    /** Allocate the next globally serialized application reference. */
    public static function nextReference(): string
    {
        $max = (int) static::query()
            ->whereNotNull('reference')
            ->selectRaw('MAX(CAST(SUBSTRING(reference, 4) AS UNSIGNED)) AS seq')
            ->value('seq');

        $generator = app(ReferenceNumberGenerator::class);
        $generator->ensureAtLeast('IT', $max + 1);

        return $generator->nextGlobal('IT', 6);
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
            } catch (QueryException $exception) {
                $attempts++;
                $collidedOnReference = str_contains($exception->getMessage(), 'it_tickets_reference_uq');
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

    public function requestedFor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_for_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** Linked entry in the canonical (fleet-)assets register. */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ItTeam::class, 'team_id');
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(ItQueue::class, 'queue_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ItService::class, 'it_service_id');
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
        return $this->approvals()->first()?->effectiveStatus();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ItTicketComment::class, 'ticket_id');
    }

    public function events(): MorphMany
    {
        return $this->morphMany(ItTicketEvent::class, 'subject');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ItTicketLink::class, 'ticket_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ItWorkTask::class, 'ticket_id')->orderBy('sort_order')->orderBy('id');
    }

    public function problemProfile(): HasOne
    {
        return $this->hasOne(ItProblem::class, 'ticket_id');
    }

    public function changeProfile(): HasOne
    {
        return $this->hasOne(ItChange::class, 'ticket_id');
    }

    public function majorIncidentProfile(): HasOne
    {
        return $this->hasOne(ItMajorIncident::class, 'ticket_id');
    }

    public function linked(string $relationship): HasMany
    {
        return $this->links()->where('relationship', $relationship);
    }

    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'it_ticket_watchers', 'ticket_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Resolved without reopening and with no more than one requester-visible
     * reply. Internal technician notes do not change first-contact resolution.
     */
    public function scopeFirstContactResolved(Builder $query): Builder
    {
        return $query
            ->whereNotNull('resolved_at')
            ->where('reopened_count', 0)
            ->whereHas('comments', fn (Builder $comments) => $comments->where('is_internal', false), '<=', 1);
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
     * Stamp/restamp the SLA due dates from the application policy for the
     * ticket's CURRENT priority, anchored at creation — a priority change
     * re-targets the same clock, it doesn't restart it. Mutates without
     * saving; callers persist.
     */
    public function stampSlaDueDates(): void
    {
        $clock = app(ItSlaClockService::class);
        $at = now();
        $previous = $this->sla_policy_snapshot;
        $hadClocks = $this->first_response_due_at !== null || $this->resolution_due_at !== null;
        $initialStamp = ! $this->exists || ($this->wasRecentlyCreated && ! $hadClocks);
        $clock->synchronize($this, $at);
        if (! $initialStamp && $this->waiting_since !== null) {
            $this->checkpointSlaPause($at);
        }
        $policy = ItSlaPolicy::query()->where('priority', (string) $this->priority)->first();
        $snapshot = $clock->policySnapshot((string) $this->priority, $policy, $at);
        if (($this->sla_policy_snapshot['pause_unit'] ?? null) === 'legacy_unknown'
            || (! $initialStamp && $previous === null && ((int) $this->sla_paused_minutes > 0 || $this->waiting_since !== null))) {
            $snapshot['pause_unit'] = 'legacy_unknown';
        }
        if ($initialStamp && $this->sla_original_policy_snapshot === null) {
            $this->sla_original_policy_snapshot = $snapshot;
        }
        $this->sla_policy_snapshot = $snapshot;
        $anchor = $this->created_at ?? $at;
        if ($initialStamp || $this->first_response_due_at !== null) {
            $this->first_response_due_at = BusinessHours::addWorkingMinutes($anchor, $snapshot['first_response_minutes'], $snapshot['calendar'])->utc();
        }
        if ($initialStamp || $this->resolution_due_at !== null) {
            $this->resolution_due_at = BusinessHours::addWorkingMinutes($anchor, $snapshot['resolution_minutes'], $snapshot['calendar'])->utc();
        }
        $clock->synchronize($this, $at);
        if (! $initialStamp && $this->exists) {
            ItTicketEvent::record($this, 'sla_policy_changed', auth()->id(), [
                'previous_policy' => $previous,
                'policy' => $snapshot,
                'original_policy_recorded' => $this->sla_original_policy_snapshot !== null,
                'first_response_due_at' => $this->first_response_due_at?->toIso8601String(),
                'resolution_due_at' => $this->resolution_due_at?->toIso8601String(),
                'banked_pause_minutes' => (int) $this->sla_paused_minutes,
                'pause_checkpoint_at' => $this->waiting_since?->toIso8601String(),
            ]);
        }
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
        app(ItSlaClockService::class)->synchronize($this, now());
        $this->status = 'waiting';
        $this->waiting_since = $this->waiting_since ?? now();
        app(ItSlaClockService::class)->synchronize($this, now());
    }

    /**
     * Leave "waiting on requester" (requester replied, or an agent moved it
     * on): bank the paused minutes so SLA maths exclude them, clear the
     * marker. Mutates without saving.
     */
    public function stopWaiting(string $nextStatus = 'in_progress'): void
    {
        if ($this->waiting_since) {
            app(ItSlaClockService::class)->synchronize($this, now());
            $this->checkpointSlaPause(now());
            $this->waiting_since = null;
        }
        $this->status = $nextStatus;
        app(ItSlaClockService::class)->synchronize($this, now());
    }

    /** Preserve elapsed working time before a resume or an explicit policy change. */
    private function checkpointSlaPause(CarbonInterface $at): void
    {
        $snapshot = $this->sla_policy_snapshot;
        try {
            if (($snapshot['pause_unit'] ?? null) !== 'business_minutes') {
                throw new DomainException('The previous pause calendar is not recorded.');
            }
            $this->sla_paused_minutes = (int) $this->sla_paused_minutes
                + BusinessHours::workingMinutesBetween($this->waiting_since, $at, $snapshot['calendar'] ?? null);
        } catch (DomainException) {
            // An unmeasured pause must not trap work in waiting or invent a conversion.
            $this->sla_policy_snapshot = [...($snapshot ?? []), 'pause_unit' => 'legacy_unknown'];
        }
        $this->waiting_since = $at;
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

}
