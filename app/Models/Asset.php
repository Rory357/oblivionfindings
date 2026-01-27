<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
}
