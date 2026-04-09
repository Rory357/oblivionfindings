<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HsCorrectiveAction extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $table = 'hs_corrective_actions';

    /* ------------------------------------------------------------------ */
    /*  Constants                                                          */
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
    /*  Fillable / Casts                                                   */
    /* ------------------------------------------------------------------ */

    protected $fillable = [
        'hs_event_id',
        'hs_investigation_id',
        'organization_id',
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
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function hsEvent(): BelongsTo
    {
        return $this->belongsTo(HsEvent::class, 'hs_event_id');
    }

    public function hsInvestigation(): BelongsTo
    {
        return $this->belongsTo(HsInvestigation::class, 'hs_investigation_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
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
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', [self::STATUS_VERIFIED, self::STATUS_CLOSED]);
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->whereNotIn('status', [self::STATUS_VERIFIED, self::STATUS_CLOSED]);
    }

    public function scopeAwaitingVerification($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
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
    /*  Lifecycle helpers                                                   */
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
            && $this->due_date->isPast()
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

    /* ------------------------------------------------------------------ */
    /*  Reference number generation                                        */
    /* ------------------------------------------------------------------ */

    public static function generateReferenceNumber(): string
    {
        $year = now()->year;
        $prefix = "CA-{$year}-";

        $last = static::withTrashed()
            ->where('reference_number', 'like', "{$prefix}%")
            ->orderByDesc('reference_number')
            ->value('reference_number');

        $next = $last ? ((int) str_replace($prefix, '', $last)) + 1 : 1;

        return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
    }
}
