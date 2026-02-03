<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteTask extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'procedure_run_id',
        'subject_type',
        'subject_id',
        'title',
        'description',
        'task_type',
        'status',
        'priority',
        'assigned_to_user_id',
        'assigned_by_user_id',
        'assigned_at',
        'completed_by_user_id',
        'completed_at',
        'completion_notes',
        'requires_approval',
        'approved_by_user_id',
        'approved_at',
        'approval_notes',
        'required_evidence',
        'collected_evidence',
        'evidence_complete',
        'due_at',
        'overdue',
        'sla_minutes',
        'is_stop_gate',
        'checklist_items',
        'step_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'required_evidence' => 'array',
        'collected_evidence' => 'array',
        'checklist_items' => 'array',
        'evidence_complete' => 'boolean',
        'requires_approval' => 'boolean',
        'overdue' => 'boolean',
        'is_stop_gate' => 'boolean',
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
        'approved_at' => 'datetime',
        'due_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_BLOCKED = 'blocked';

    // Task types
    public const TYPE_ACTION = 'action';
    public const TYPE_CHECKLIST = 'checklist';
    public const TYPE_APPROVAL = 'approval';
    public const TYPE_EVIDENCE = 'evidence';
    public const TYPE_NOTIFICATION = 'notification';

    // Priority levels
    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_CRITICAL = 'critical';

    public function procedureRun(): BelongsTo
    {
        return $this->belongsTo(RespiteProcedureRun::class, 'procedure_run_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_AWAITING_APPROVAL]);
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_at', '<', now())
            ->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_SKIPPED, self::STATUS_APPROVED]);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('assigned_to_user_id', $userId);
    }

    public function scopeStopGates($query)
    {
        return $query->where('is_stop_gate', true);
    }

    // Helper methods
    public function isComplete(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_APPROVED, self::STATUS_SKIPPED]);
    }

    public function canComplete(): bool
    {
        if ($this->requires_approval && $this->status !== self::STATUS_APPROVED) {
            return false;
        }

        if (!empty($this->required_evidence) && !$this->evidence_complete) {
            return false;
        }

        return true;
    }

    public function markComplete(int $userId, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_by_user_id' => $userId,
            'completed_at' => now(),
            'completion_notes' => $notes,
        ]);
    }

    public function markInProgress(): void
    {
        $this->update(['status' => self::STATUS_IN_PROGRESS]);
    }

    public function submitForApproval(): void
    {
        $this->update(['status' => self::STATUS_AWAITING_APPROVAL]);
    }

    public function approve(int $userId, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by_user_id' => $userId,
            'approved_at' => now(),
            'approval_notes' => $notes,
        ]);
    }

    public function reject(int $userId, string $notes): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'approved_by_user_id' => $userId,
            'approved_at' => now(),
            'approval_notes' => $notes,
        ]);
    }

    public function checkOverdue(): bool
    {
        if ($this->due_at && $this->due_at->isPast() && !$this->overdue && !$this->isComplete()) {
            $this->update(['overdue' => true]);
            return true;
        }
        return $this->overdue;
    }

    public function addEvidence(string $type, array $data): void
    {
        $evidence = $this->collected_evidence ?? [];
        $evidence[] = [
            'type' => $type,
            'data' => $data,
            'collected_at' => now()->toIso8601String(),
        ];

        $required = $this->required_evidence ?? [];
        $collectedTypes = array_column($evidence, 'type');
        $allCollected = empty(array_diff($required, $collectedTypes));

        $this->update([
            'collected_evidence' => $evidence,
            'evidence_complete' => $allCollected,
        ]);
    }

    public function updateChecklistItem(int $index, bool $completed): void
    {
        $items = $this->checklist_items ?? [];
        if (isset($items[$index])) {
            $items[$index]['completed'] = $completed;
            $items[$index]['completed_at'] = $completed ? now()->toIso8601String() : null;
            $this->update(['checklist_items' => $items]);
        }
    }

    public function getChecklistProgress(): array
    {
        $items = $this->checklist_items ?? [];
        $total = count($items);
        $completed = count(array_filter($items, fn($item) => $item['completed'] ?? false));

        return [
            'total' => $total,
            'completed' => $completed,
            'percentage' => $total > 0 ? round(($completed / $total) * 100) : 0,
        ];
    }
}
