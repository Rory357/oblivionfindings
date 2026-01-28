<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SafeguardingActionPlan extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'safeguarding_concern_id',
        'action_description',
        'action_type',
        'assigned_to_user_id',
        'due_date',
        'status',
        'priority',
        'completion_notes',
        'completed_at',
        'completed_by_user_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Safeguarding concern.
     */
    public function concern(): BelongsTo
    {
        return $this->belongsTo(SafeguardingConcern::class, 'safeguarding_concern_id');
    }

    /**
     * User assigned to the action.
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * User who completed the action.
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /**
     * User who created the record.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who last updated the record.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope: Pending actions.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: Overdue actions.
     */
    public function scopeOverdue($query)
    {
        return $query->whereNotIn('status', ['completed', 'cancelled'])
            ->where('due_date', '<', now());
    }

    /**
     * Scope: High priority actions.
     */
    public function scopeHighPriority($query)
    {
        return $query->where('priority', 1);
    }

    /**
     * Check if action is complete.
     */
    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if action is overdue.
     */
    public function isOverdue(): bool
    {
        return !in_array($this->status, ['completed', 'cancelled'])
            && $this->due_date
            && $this->due_date->isPast();
    }
}
