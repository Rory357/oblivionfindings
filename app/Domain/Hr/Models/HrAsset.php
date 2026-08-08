<?php

namespace App\Domain\Hr\Models;

use App\Models\Asset;
use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\FleetIncident;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrAsset extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes, WritesLegacyStorageContext;

    public const HR_OWNED_CATEGORIES = ['uniform', 'card', 'other'];

    /** Technology rows retained only as history until they are reconciled to Device. */
    public const LEGACY_TECHNOLOGY_CATEGORIES = ['laptop', 'phone', 'tablet'];

    protected $fillable = [
        'asset_tag',
        'name',
        'category',
        'serial_number',
        'make',
        'model',
        'purchase_date',
        'purchase_cost',
        'supplier',
        'warranty_expiry',
        'condition',
        'depreciation_method',
        'useful_life_years',
        'status',
        'fleet_asset_id',
        'qr_token',
        'disposal_reason',
        'disposed_at',
        'disposal_value',
        'notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'purchase_cost' => 'decimal:2',
        'warranty_expiry' => 'date',
        'useful_life_years' => 'integer',
        'disposed_at' => 'date',
        'disposal_value' => 'decimal:2',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
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

    public function maintenanceLogs(): HasMany
    {
        return $this->hasMany(HrAssetMaintenanceLog::class, 'asset_id');
    }

    public function openMaintenanceLog(): HasOne
    {
        return $this->hasOne(HrAssetMaintenanceLog::class, 'asset_id')
            ->whereNull('completed_at')
            ->latest('created_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(HrAssetDocument::class, 'asset_id');
    }

    /**
     * The canonical Fleet & Assets record this HR row federates to, when the
     * category is fleet-owned (vehicle / key). When set, the row is a read-through
     * pointer — HR never writes the underlying Fleet data.
     */
    public function fleetAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'fleet_asset_id');
    }

    /** Fleet incidents recorded against the linked canonical Fleet asset. */
    public function fleetIncidents(): HasMany
    {
        return $this->hasMany(FleetIncident::class, 'asset_id', 'fleet_asset_id');
    }

    /** A row that points at the canonical Fleet register rather than owning the record. */
    public function isFleetLinked(): bool
    {
        return $this->fleet_asset_id !== null;
    }

    /** A pre-canonical technology row whose lifecycle now belongs to Security & Devices. */
    public function isLegacyTechnology(): bool
    {
        return in_array($this->category, self::LEGACY_TECHNOLOGY_CATEGORIES, true);
    }

    /** Whether HR remains the authoritative lifecycle owner for this record. */
    public function isHrLifecycleOwned(): bool
    {
        return ! $this->isFleetLinked()
            && in_array($this->category, self::HR_OWNED_CATEGORIES, true);
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeAssigned($query)
    {
        return $query->where('status', 'assigned');
    }
}
