<?php

namespace App\Domain\Governance\Models;

use App\Domain\Governance\Services\GovernanceResolutionAuthorityService;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if ($model->version_number === null) {
                $max = (int) static::query()->where('fiscal_year', $model->fiscal_year)->max('version_number');
                $model->version_number = $max + 1;
            }
        });
    }

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

        // The generated decision paper is explicitly bound, while still a
        // draft, to this exact budget version and its budgeted lines. Any
        // change to those terms before approval invalidates the authority.
        app(GovernanceResolutionAuthorityService::class)->bind(
            $resolution,
            GovernanceResolutionBinding::SUBJECT_BUDGET,
            (int) $this->getKey(),
            User::query()->findOrFail($userId),
        );

        $this->update([
            'status' => 'proposed',
            'proposed_by' => $userId,
            'proposed_at' => now(),
            'approval_resolution_id' => $resolution->id,
        ]);
    }

    /**
     * Approve this budget under a carried resolution.
     *
     * Authority comes only from an explicit binding created while the paper
     * was a draft, naming this exact budget, version and budgeted lines (as a
     * fingerprint). Titles, cost-impact amounts and motion wording never
     * confer authority. The binding is verified under row locks and consumed
     * once.
     *
     * @throws ValidationException when the resolution does not authorise this budget
     */
    public function approve(int $resolutionId, ?int $actorId = null): void
    {
        $authority = app(GovernanceResolutionAuthorityService::class);

        DB::transaction(function () use ($resolutionId, $actorId, $authority) {
            $lockedBudget = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            $resolution = Resolution::query()->whereKey($resolutionId)->lockForUpdate()->first();

            if (! $resolution) {
                throw ValidationException::withMessages([
                    'resolution_id' => 'The selected resolution does not exist.',
                ]);
            }

            if (! in_array($resolution->status, ['closed', 'implemented', 'archived'], true) || $resolution->outcome !== 'carried') {
                throw ValidationException::withMessages([
                    'resolution_id' => 'Only a closed resolution with a carried outcome can approve a budget.',
                ]);
            }

            if ($lockedBudget->isApproved()) {
                throw ValidationException::withMessages([
                    'resolution_id' => 'This budget is already approved.',
                ]);
            }

            $alreadyUsed = static::query()
                ->where('approval_resolution_id', $resolutionId)
                ->where('id', '!=', $lockedBudget->id)
                ->where('status', 'approved')
                ->exists();

            if ($alreadyUsed) {
                throw ValidationException::withMessages([
                    'resolution_id' => 'The selected resolution has already been applied to another approved budget.',
                ]);
            }

            // Hold the budgeted lines steady while the terms are compared.
            $lockedBudget->lineItems()->lockForUpdate()->get(['id']);

            try {
                $authority->verifyAndConsume(
                    $resolution,
                    GovernanceResolutionBinding::SUBJECT_BUDGET,
                    (int) $lockedBudget->getKey(),
                    $authority->budgetTerms($lockedBudget),
                    $actorId ?? auth()->id(),
                );
            } catch (\DomainException $exception) {
                throw ValidationException::withMessages([
                    'resolution_id' => 'The selected resolution does not authorize approval of this budget. '.$exception->getMessage(),
                ]);
            }

            $lockedBudget->update([
                'status' => 'approved',
                'approval_resolution_id' => $resolutionId,
                'approved_by_board_at' => now(),
            ]);
        }, 3);

        $this->refresh();
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
