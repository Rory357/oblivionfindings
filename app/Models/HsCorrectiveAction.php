<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use App\Models\ControlRoom\AlertTask;
use App\Services\References\ReferenceNumberGenerator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HsCorrectiveAction extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes, WritesLegacyOrganizationStorageContext;

    protected $table = 'hs_corrective_actions';

    /* ------------------------------------------------------------------ */
    /*  Constants */
    /* ------------------------------------------------------------------ */

    // Action types
    public const TYPE_CORRECTIVE = 'corrective';

    public const TYPE_PREVENTIVE = 'preventive';

    public const TYPE_IMPROVEMENT = 'improvement';

    // Priority levels (aligned with HsEvent severity)
    public const PRIORITY_LOW = 'low';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_CRITICAL = 'critical';

    // Lifecycle statuses
    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_CLOSED = 'closed';

    public const ALLOWED_TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_IN_PROGRESS],
        self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED => [self::STATUS_VERIFIED, self::STATUS_IN_PROGRESS],
        self::STATUS_VERIFIED => [self::STATUS_CLOSED],
        self::STATUS_CLOSED => [],
    ];

    /* ------------------------------------------------------------------ */
    /*  Fillable / Casts */
    /* ------------------------------------------------------------------ */

    protected $fillable = [
        'hs_event_id',
        'hs_investigation_id',
        'replaces_action_id',
        'source_control_room_task_id',
        'reference_number',
        'recommendation_index',
        'action_type',
        'priority',
        'title',
        'description',
        'root_cause_link',
        'status',
        'assigned_to_user_id',
        'assigned_by_user_id',
        'assigned_at',
        'due_date',
        'completed_at',
        'completed_by_user_id',
        'completion_notes',
        'completion_evidence_paths',
        'verified_at',
        'verified_by_user_id',
        'verification_notes',
        'effectiveness_confirmed',
        'closed_at',
        'closed_by_user_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'completion_evidence_paths' => 'array',
        'verified_at' => 'datetime',
        'effectiveness_confirmed' => 'boolean',
        'closed_at' => 'datetime',
        'recommendation_index' => 'integer',
        'replaces_action_id' => 'integer',
        'owner_revision' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function hsEvent(): BelongsTo
    {
        return $this->belongsTo(HsEvent::class, 'hs_event_id');
    }

    public function hsInvestigation(): BelongsTo
    {
        return $this->belongsTo(HsInvestigation::class, 'hs_investigation_id');
    }

    /** Q11: the action whose check said it didn't work, which this revised action replaces. */
    public function replacedAction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_action_id');
    }

    /** Q11: revised actions added because this action's check said it didn't work. */
    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'replaces_action_id');
    }

    public function sourceControlRoomTask(): BelongsTo
    {
        return $this->belongsTo(AlertTask::class, 'source_control_room_task_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(HsAttachment::class, 'attachable');
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function ownerChanges(): HasMany
    {
        return $this->hasMany(HsCorrectiveActionOwnerChange::class, 'action_id')->orderByDesc('revision');
    }

    /** Reassigning an action does not make a previous owner independent of it. */
    public function hasBeenOwnedBy(int $userId): bool
    {
        if ((int) $this->assigned_to_user_id === $userId) {
            return true;
        }
        if (! $this->owner_revision) {
            return false;
        }
        if ($this->relationLoaded('ownerChanges')) {
            return $this->ownerChanges->contains(fn ($change) => (int) $change->owner_user_id === $userId || (int) $change->previous_owner_user_id === $userId);
        }

        return $this->ownerChanges()->where(fn ($query) => $query->where('owner_user_id', $userId)->orWhere('previous_owner_user_id', $userId))->exists();
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', [self::STATUS_VERIFIED, self::STATUS_CLOSED]);
    }

    /**
     * Whole worker-calendar days since the owner marked the action completed (0 = today), while it waits for someone
     * else to verify it; null for any other status.
     */
    public function daysSinceCompleted(): ?int
    {
        if ($this->status !== self::STATUS_COMPLETED || $this->completed_at === null) {
            return null;
        }
        $timezone = self::workerTimezone();

        return (int) abs($this->completed_at->copy()->setTimezone($timezone)->startOfDay()->diffInDays(now($timezone)->startOfDay()));
    }

    /** The worker calendar; an empty setting falls back to the application timezone. */
    private static function workerTimezone(): string
    {
        return (string) (config('app.worker_timezone', 'Pacific/Auckland') ?: config('app.timezone') ?: 'UTC');
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('due_date')
            ->where('due_date', '<', now(self::workerTimezone())->toDateString())
            ->whereNotIn('status', [self::STATUS_VERIFIED, self::STATUS_CLOSED]);
    }

    public function scopeAwaitingVerification($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function acceptsEvidenceChanges(): bool
    {
        return ! in_array($this->status, [
            self::STATUS_VERIFIED,
            self::STATUS_CLOSED,
        ], true);
    }

    public function scopeForAssignee($query, int $userId)
    {
        return $query->where('assigned_to_user_id', $userId);
    }

    public function scopeForEvent($query, int $eventId)
    {
        return $query->where('hs_event_id', $eventId);
    }

    public function scopeForInvestigation($query, int $investigationId)
    {
        return $query->where('hs_investigation_id', $investigationId);
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycle helpers */
    /* ------------------------------------------------------------------ */

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::STATUS_VERIFIED, self::STATUS_CLOSED], true);
    }

    public function isOverdue(): bool
    {
        return $this->due_date
            && $this->due_date->toDateString() < now(self::workerTimezone())->toDateString()
            && $this->isOpen();
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /** Checked, and the check said it didn't work (Q11). */
    public function checkedIneffective(): bool
    {
        return in_array($this->status, [self::STATUS_VERIFIED, self::STATUS_CLOSED], true)
            && $this->effectiveness_confirmed === false;
    }

    /** Checked, and the check said it worked. */
    public function checkedEffective(): bool
    {
        return in_array($this->status, [self::STATUS_VERIFIED, self::STATUS_CLOSED], true)
            && $this->effectiveness_confirmed === true;
    }

    /**
     * Q11: reference numbers of a safety review's actions whose check said they didn't work and that no revised action
     * has yet resolved. A failed action is resolved when one of its revisions was checked as working, or one of its
     * revisions itself failed and was in turn resolved. Cycles can't be made through the service but are guarded.
     *
     * @return list<string>
     */
    public static function unresolvedFailedReferences(int $hsEventId): array
    {
        $actions = self::query()
            ->where('hs_event_id', $hsEventId)
            ->get(['id', 'reference_number', 'status', 'effectiveness_confirmed', 'replaces_action_id']);
        $revisionsOf = $actions->groupBy(fn (self $action): int => (int) $action->replaces_action_id);

        $resolved = function (self $failed, array $seen) use (&$resolved, $revisionsOf): bool {
            if (isset($seen[$failed->id])) {
                return false;
            }
            $seen[$failed->id] = true;
            foreach ($revisionsOf->get($failed->id, collect()) as $revision) {
                if ($revision->checkedEffective() || ($revision->checkedIneffective() && $resolved($revision, $seen))) {
                    return true;
                }
            }

            return false;
        };

        return $actions
            ->filter(fn (self $action): bool => $action->checkedIneffective() && ! $resolved($action, []))
            ->sortBy('id')
            ->map(fn (self $action): string => (string) ($action->reference_number ?: "action {$action->id}"))
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Reference number generation */
    /* ------------------------------------------------------------------ */

    public static function generateReferenceNumber(): string
    {
        return app(ReferenceNumberGenerator::class)->next('CA');
    }
}
