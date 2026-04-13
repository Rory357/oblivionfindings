<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinFixedAsset extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected static function newFactory()
    {
        return \Database\Factories\Finance\FinFixedAssetFactory::new();
    }

    protected $table = 'fin_fixed_assets';

    protected $fillable = [
        'organization_id',
        'asset_name',
        'asset_tag',
        'category',
        'purchase_date',
        'purchase_cost',
        'residual_value',
        'useful_life_months',
        'depreciation_method',
        'accumulated_depreciation',
        'gl_asset_account_id',
        'gl_depreciation_account_id',
        'gl_expense_account_id',
        'status',
        'disposed_date',
        'disposal_proceeds',
        'linked_asset_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'purchase_cost' => 'decimal:2',
        'residual_value' => 'decimal:2',
        'useful_life_months' => 'integer',
        'accumulated_depreciation' => 'decimal:2',
        'disposed_date' => 'date',
        'disposal_proceeds' => 'decimal:2',
    ];

    public function glAssetAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'gl_asset_account_id');
    }

    public function glDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'gl_depreciation_account_id');
    }

    public function glExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'gl_expense_account_id');
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(FinFixedAssetDepreciation::class, 'fixed_asset_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The operational asset this fixed asset is linked to.
     * Used to follow the chain: FinFixedAsset → Asset → DeviceAssetLink → Device.
     */
    public function linkedAsset(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Asset::class, 'linked_asset_id');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn($q) => $q->where('organization_id', $orgId));
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOfCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Get the current book value of the asset.
     */
    public function getBookValue(): float
    {
        return (float) $this->purchase_cost - (float) $this->accumulated_depreciation;
    }

    /**
     * Get monthly depreciation amount using straight-line method.
     */
    public function getMonthlyDepreciation(): float
    {
        if ($this->useful_life_months <= 0) {
            return 0.0;
        }

        return ((float) $this->purchase_cost - (float) $this->residual_value) / $this->useful_life_months;
    }
}
