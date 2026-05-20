<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLineItem extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'budget_id',
        'category',
        'subcategory',
        'description',
        'account_code',
        // `gl_account_id` is advisory only — Finance owns the chart of accounts.
        // Prefer `gl_account_code` (cached string) for display; keep the id for
        // legacy callers but never enforce a foreign-key relationship from
        // Governance.
        'gl_account_id',
        'gl_account_code',
        'budget_amount',
        'forecast_amount',
        'actual_amount',
        'variance_amount',
        'variance_pct',
        'variance_explanation',
        'variance_explained',
        'notes',
    ];

    protected $casts = [
        'budget_amount' => 'decimal:2',
        'forecast_amount' => 'decimal:2',
        'actual_amount' => 'decimal:2',
        'variance_amount' => 'decimal:2',
        'variance_pct' => 'decimal:2',
        'variance_explained' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        
        static::saving(function ($model) {
            $model->calculateVariance();
        });
    }

    public function calculateVariance(): void
    {
        $this->variance_amount = $this->actual_amount - $this->budget_amount;
        if ($this->budget_amount != 0) {
            $this->variance_pct = ($this->variance_amount / $this->budget_amount) * 100;
        }
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeWithVariance($query)
    {
        return $query->whereRaw('ABS(variance_pct) > 0');
    }

    public function scopeSignificantVariance($query, float $threshold = 5)
    {
        return $query->whereRaw('ABS(variance_pct) >= ?', [$threshold]);
    }

    public function getVarianceColor(): string
    {
        $pct = abs($this->variance_pct ?? 0);
        return match(true) {
            $pct >= 10 => 'red',
            $pct >= 5 => 'orange',
            default => 'green',
        };
    }

    public function isOverBudget(): bool
    {
        return $this->variance_amount > 0;
    }

    public function isUnderBudget(): bool
    {
        return $this->variance_amount < 0;
    }

    public function hasSignificantVariance(float $threshold = 5): bool
    {
        return abs($this->variance_pct ?? 0) >= $threshold;
    }
}
