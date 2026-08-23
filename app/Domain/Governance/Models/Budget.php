<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Budget extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $fillable = [
        'fiscal_year',
        'title',
        'description',
        'total_budget',
        'currency',
        'status',
        'proposed_by',
        'proposed_at',
        'approval_resolution_id',
        'approved_by_board_at',
        'version_number',
        'supersedes_budget_id',
        'created_by',
    ];

    protected $casts = [
        'total_budget' => 'decimal:2',
        'proposed_at' => 'datetime',
        'approved_by_board_at' => 'datetime',
        'version_number' => 'integer',
    ];

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvalResolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class, 'approval_resolution_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'supersedes_budget_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(BudgetLineItem::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(BudgetAdjustment::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BudgetAllocation::class);
    }

    public function scopeByFiscalYear($query, string $year)
    {
        return $query->where('fiscal_year', $year);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function isDrafting(): bool
    {
        return $this->status === 'drafting';
    }

    public function isProposed(): bool
    {
        return $this->status === 'proposed';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function propose(int $userId): void
    {
        $this->loadMissing('lineItems');

        $totalBudget = number_format((float) $this->total_budget, 2);
        $lineItemCount = $this->lineItems->count();

        $resolution = Resolution::create([
            'title' => 'Budget Approval: '.($this->title ?: "FY{$this->fiscal_year}"),
            'decision_type' => 'budget_approval',
            'context' => 'The '.($this->title ?: "FY{$this->fiscal_year}")." budget totalling \${$totalBudget} across {$lineItemCount} line items has been submitted for board approval.",
            'options' => [],
            'recommendation' => 'Approve the proposed budget as presented.',
            'cost_impact' => [
                'amount' => (float) $this->total_budget,
                'currency' => $this->currency ?? 'NZD',
                'description' => "Total budget envelope for FY{$this->fiscal_year}",
            ],
            'voting_threshold' => 'simple_majority',
            'status' => 'draft',
            'proposed_by' => $userId,
            'proposed_at' => now(),
        ]);

        $this->update([
            'status' => 'proposed',
            'proposed_by' => $userId,
            'proposed_at' => now(),
            'approval_resolution_id' => $resolution->id,
        ]);
    }

    public function approve(int $resolutionId): void
    {
        $this->update([
            'status' => 'approved',
            'approval_resolution_id' => $resolutionId,
            'approved_by_board_at' => now(),
        ]);
    }

    public function getTotalAllocated(): float
    {
        return $this->lineItems->sum('budget_amount');
    }

    public function getTotalActual(): float
    {
        return $this->lineItems->sum('actual_amount');
    }

    public function getTotalVariance(): float
    {
        return $this->getTotalActual() - $this->getTotalAllocated();
    }

    public function getVariancePercentage(): float
    {
        if ($this->getTotalAllocated() == 0) {
            return 0;
        }

        return ($this->getTotalVariance() / $this->getTotalAllocated()) * 100;
    }

    public function getRemainingBudget(): float
    {
        return $this->total_budget - $this->getTotalAllocated();
    }

    public function recalculateTotals(): void
    {
        $this->total_budget = $this->lineItems()->sum('budget_amount');
        $this->save();
    }

    /**
     * Check if a budget adjustment requires a board resolution based on threshold.
     * Default threshold: adjustments exceeding 5% of total budget require board approval.
     */
    public function requiresBoardApproval(float $adjustmentAmount, float $thresholdPct = 5.0): bool
    {
        if ($this->total_budget == 0) {
            return true;
        }
        $pct = abs($adjustmentAmount) / $this->total_budget * 100;

        return $pct >= $thresholdPct;
    }
}
