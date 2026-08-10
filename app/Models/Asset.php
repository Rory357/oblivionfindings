<?php

namespace App\Models;

use App\Domain\Hr\Models\HrAsset;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Asset extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($asset) {
            if (empty($asset->qr_token)) {
                $asset->qr_token = Str::random(32);
            }
        });
    }

    protected $fillable = [
        'site_id',
        'room_id',
        'site_room_id',
        'home_site_id',
        'primary_driver_user_id',
        'client_id',
        'created_by_user_id',
        'updated_by_user_id',
        'asset_tag',
        'qr_token',
        'name',
        'category',
        'asset_category_id',
        'description',
        'manufacturer',
        'model',
        'serial_number',
        'registration_number',
        'registration_expires_at',
        'wof_expires_at',
        'cof_expires_at',
        'fuel_type',
        'odometer_km',
        'purchase_date',
        'warranty_expires_at',
        'status',
        'risk_level',
        'location',
        'requires_inspection',
        'inspection_due_at',
        'requires_maintenance',
        'maintenance_due_at',
        'notes',
        'alert_config',
        'has_wheelchair_ramp',
        'has_hoist',
        'has_child_seat_anchors',
        'has_medical_storage',
        'seating_capacity',
        'accessibility_notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'warranty_expires_at' => 'date',
        'registration_expires_at' => 'date',
        'wof_expires_at' => 'date',
        'cof_expires_at' => 'date',
        'odometer_km' => 'decimal:1',
        'requires_inspection' => 'boolean',
        'inspection_due_at' => 'date',
        'requires_maintenance' => 'boolean',
        'maintenance_due_at' => 'date',
        'alert_config' => 'array',
        'has_wheelchair_ramp' => 'boolean',
        'has_hoist' => 'boolean',
        'has_child_seat_anchors' => 'boolean',
        'has_medical_storage' => 'boolean',
        'seating_capacity' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(SiteHouseRoom::class, 'room_id');
    }

    /** Canonical physical-space identity used by Devices and monitoring. */
    public function canonicalRoom(): BelongsTo
    {
        return $this->belongsTo(SiteRoom::class, 'site_room_id');
    }

    public function categoryRef(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(AssetInspection::class);
    }

    public function maintenanceLogs(): HasMany
    {
        return $this->hasMany(AssetMaintenanceLog::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AssetDocument::class);
    }

    public function ownerships(): HasMany
    {
        return $this->hasMany(AssetOwnership::class);
    }

    public function value(): HasOne
    {
        return $this->hasOne(AssetValue::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }

    /**
     * Canonical Security & Devices link history for this operational asset.
     */
    public function deviceLinks(): HasMany
    {
        return $this->hasMany(DeviceAssetLink::class);
    }

    /**
     * Devices currently installed in or otherwise linked to this asset.
     */
    public function activeDeviceLinks(): HasMany
    {
        return $this->deviceLinks()->whereNull('unlinked_at');
    }

    /**
     * @deprecated Use DeviceAssetLink::active()->forAsset($this->id) instead.
     * Legacy relationship — AssetTracker is retired as active source of truth.
     * Kept only for telemetry ingestion and consent compatibility.
     */
    public function trackers(): HasMany
    {
        return $this->hasMany(AssetTracker::class);
    }

    public function telemetrySnapshots(): HasMany
    {
        return $this->hasMany(AssetTelemetrySnapshot::class);
    }

    public function telemetryHistories(): HasMany
    {
        return $this->hasMany(AssetTelemetryHistory::class);
    }

    /**
     * @deprecated Legacy asset_alert history only.
     * ControlRoomAlert is the active operational alert surface.
     *
     * Kept for archived asset alert visibility in asset/fleet detail views.
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(AssetAlert::class);
    }

    public function scanEvents(): HasMany
    {
        return $this->hasMany(AssetScanEvent::class);
    }

    public function geofences(): HasMany
    {
        return $this->hasMany(AssetGeofence::class);
    }

    public function assignedGeofences(): BelongsToMany
    {
        return $this->belongsToMany(AssetGeofence::class, 'asset_geofence_assignments')
            ->withTimestamps();
    }

    public function fleetState()
    {
        return $this->hasOne(FleetVehicleStateSnapshot::class, 'asset_id');
    }

    public function fleetTrips(): HasMany
    {
        return $this->hasMany(FleetTrip::class, 'asset_id');
    }

    public function fleetSignals(): HasMany
    {
        return $this->hasMany(FleetSignal::class, 'asset_id');
    }

    public function incidentLinks(): HasMany
    {
        return $this->hasMany(AssetIncidentLink::class);
    }

    /**
     * @deprecated Use DeviceAssetLink::active()->forAsset($this->id) instead.
     * Legacy relationship — LocationHardware is retired as active source of truth.
     * Kept only for historical/bridge reads; manual link editing UI was removed
     * in PR27.
     */
    public function linkedHardware(): HasMany
    {
        return $this->hasMany(LocationHardware::class, 'linked_asset_id');
    }

    public function homeSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'home_site_id');
    }

    public function primaryDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_driver_user_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(FleetVehicleBooking::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(FleetWorkOrder::class);
    }

    public function checklistRuns(): HasMany
    {
        return $this->hasMany(FleetChecklistRun::class);
    }

    public function serviceSchedules(): HasMany
    {
        return $this->hasMany(FleetServiceSchedule::class);
    }

    public function fleetIncidents(): HasMany
    {
        return $this->hasMany(FleetIncident::class);
    }

    /**
     * The HR-register wrapper row that federates to this canonical Fleet
     * asset (inverse of HrAsset::fleetAsset()).
     */
    public function hrAsset(): HasOne
    {
        return $this->hasOne(HrAsset::class, 'fleet_asset_id');
    }

    public function fleetHandovers(): HasMany
    {
        return $this->hasMany(FleetShiftHandover::class);
    }

    public function keyLogs(): HasMany
    {
        return $this->hasMany(FleetKeyLog::class);
    }

    public function latestKeyLog(): HasOne
    {
        return $this->hasOne(FleetKeyLog::class)->latestOfMany();
    }

    public function scopeWofExpiring($query, int $days = 30)
    {
        return $query->whereNotNull('wof_expires_at')
            ->where('wof_expires_at', '<=', now()->addDays($days))
            ->where('wof_expires_at', '>=', now());
    }

    public function scopeRegistrationExpiring($query, int $days = 30)
    {
        return $query->whereNotNull('registration_expires_at')
            ->where('registration_expires_at', '<=', now()->addDays($days))
            ->where('registration_expires_at', '>=', now());
    }

    public function scopeVehicles($query)
    {
        // Grouped so the orWhereHas branch can't escape and poison constraints
        // chained onto the scope (status/site filters etc.).
        return $query->where(function ($q) {
            $q->where('category', 'vehicle')
                ->orWhereHas('categoryRef', fn ($qq) => $qq->where('slug', 'vehicle'));
        });
    }
}
