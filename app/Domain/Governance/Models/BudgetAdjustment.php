<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BudgetAdjustment extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'budget_id',
        'budget_line_item_id',
        'adjustment_type',
        'amount',
        'reason',
        'status',
        'threshold_applies',
        'approval_resolution_id',
        'approved_at',
        'approved_by',
        'proposed_by',
        'proposed_at',
        'review_notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'proposed_at' => 'datetime',
        'approved_at' => 'datetime',
        'threshold_applies' => 'boolean',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function lineItem(): BelongsTo
    {
        return $this->belongsTo(BudgetLineItem::class, 'budget_line_item_id');
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approvalResolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class, 'approval_resolution_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'submitted');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRequiringBoardApproval($query)
    {
        return $query->where('threshold_applies', true);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isIncrease(): bool
    {
        return $this->adjustment_type === 'increase';
    }

    public function isDecrease(): bool
    {
        return $this->adjustment_type === 'decrease';
    }

    public function isReallocation(): bool
    {
        return $this->adjustment_type === 'reallocate';
    }

    public function submit(): void
    {
        $this->update([
            'status' => 'submitted',
            'proposed_at' => now(),
        ]);
    }

    public function approve(int $userId, ?int $resolutionId = null): void
    {
        $this->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $userId,
            'approval_resolution_id' => $resolutionId,
        ]);

        // Apply adjustment to line item
        $lineItem = $this->lineItem;
        if ($this->isIncrease()) {
            $lineItem->budget_amount += $this->amount;
        } elseif ($this->isDecrease()) {
            $lineItem->budget_amount -= $this->amount;
        }
        $lineItem->save();
    }

    public function reject(int $userId, string $reason): void
    {
        $this->update([
            'status' => 'rejected',
            'review_notes' => $reason,
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);
    }
}
