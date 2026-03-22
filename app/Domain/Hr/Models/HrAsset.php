<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrAsset extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'asset_tag',
        'name',
        'category',
        'serial_number',
        'make',
        'model',
        'purchase_date',
        'purchase_cost',
        'warranty_expiry',
        'status',
        'notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'purchase_cost' => 'decimal:2',
        'warranty_expiry' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function assignments(): HasMany
    {
        return $this->hasMany(HrAssetAssignment::class, 'asset_id');
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(HrAssetAssignment::class, 'asset_id')
            ->whereNull('returned_at')
            ->latest('assigned_at');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', 'available');
    }

    public function scopeAssigned(Builder $query): Builder
    {
        return $query->where('status', 'assigned');
    }
}
