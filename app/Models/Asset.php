<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use App\Models\FleetSignal;
use App\Models\FleetTrip;
use App\Models\FleetVehicleStateSnapshot;

class Asset extends Model
{
    use AuditableChanges;

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
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'warranty_expires_at' => 'date',
        'requires_inspection' => 'boolean',
        'inspection_due_at' => 'date',
        'requires_maintenance' => 'boolean',
        'maintenance_due_at' => 'date',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
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
}
