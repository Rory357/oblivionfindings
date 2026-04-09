<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Monthly budget line for a site + category.
 *
 * Planned amounts are set by operational managers.
 * Actual amounts are calculated dynamically from fin_cost_allocations.
 * Variance is derived, never stored.
 */
class SiteBudgetLine extends Model
{
    use AuditableChanges;

    protected $table = 'site_budget_lines';

    protected $fillable = [
        'tenant_id',
        'site_id',
        'period',
        'category',
        'planned_amount',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'planned_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    /**
     * Budget categories that map to event_type groups in fin_cost_allocations.
     */
    public const CATEGORIES = [
        'payroll' => 'Payroll & Staffing',
        'rent' => 'Rent / Lease',
        'utilities' => 'Utilities',
        'maintenance' => 'Maintenance',
        'fleet' => 'Fleet / Transport',
        'house_operating' => 'House Operating',
        'training' => 'Training',
        'other' => 'Other',
    ];

    /**
     * Map budget categories to the event_types they aggregate from fin_cost_allocations.
     */
    public const CATEGORY_EVENT_TYPES = [
        'payroll' => ['payroll_cost', 'employer_oncost', 'leave_provision', 'expense_claim'],
        'rent' => ['site_rent_expense'],
        'utilities' => ['site_utilities_expense', 'site_utilities_true_up'],
        'maintenance' => ['site_maintenance_expense', 'asset_maintenance_expense', 'fleet_maintenance_expense'],
        'fleet' => ['fuel_expense'],
        'house_operating' => ['house_ledger_expense'],
        'training' => ['training_cost'],
        'other' => ['mileage_reimbursement', 'client_ledger_expense'],
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForSite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    public function scopeForPeriod($query, string $period)
    {
        return $query->where('period', $period);
    }

    public function scopeForPeriodRange($query, string $fromPeriod, string $toPeriod)
    {
        return $query->where('period', '>=', $fromPeriod)
            ->where('period', '<=', $toPeriod);
    }

    /**
     * Get the human-readable label for this category.
     */
    public function getCategoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst(str_replace('_', ' ', $this->category));
    }

    /**
     * Get the event_types this budget category aggregates.
     */
    public function getEventTypes(): array
    {
        return self::CATEGORY_EVENT_TYPES[$this->category] ?? [];
    }
}
