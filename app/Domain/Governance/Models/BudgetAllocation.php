<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links an annual board-approved governance Budget to per-site, per-month
 * operational variance buckets. Allows the Governance dashboard to read
 * aggregated site-level variance without owning the underlying line data.
 */
class BudgetAllocation extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'budget_id', 'budget_line_item_id', 'site_id', 'site_budget_line_id',
        'period_year_month', 'category',
        'allocated_amount', 'forecast_amount', 'actual_amount',
        'notes', 'created_by',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'forecast_amount' => 'decimal:2',
        'actual_amount' => 'decimal:2',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function budgetLineItem(): BelongsTo
    {
        return $this->belongsTo(BudgetLineItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Variance = actual - allocated. Positive means overspend.
     */
    public function getVarianceAttribute(): float
    {
        $actual = (float) ($this->actual_amount ?? 0);
        $allocated = (float) ($this->allocated_amount ?? 0);

        return $actual - $allocated;
    }

    public function getVariancePercentAttribute(): ?float
    {
        $allocated = (float) ($this->allocated_amount ?? 0);
        if ($allocated <= 0) {
            return null;
        }

        return round(($this->variance / $allocated) * 100, 2);
    }
}
