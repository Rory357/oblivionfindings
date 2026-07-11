<?php

namespace App\Models;

use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class ClientPersonalAsset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'name',
        'category',
        'description',
        'serial_number',
        'estimated_value',
        'condition',
        'location',
        'site_id',
        'room_id',
        'tracker_hardware_id',
        'photo_path',
        'acquired_at',
        'notes',
        'recorded_by_user_id',
        // Status & lifecycle
        'status',
        'ownership',
        'funding_source',
        'return_required',
        'return_by',
        // Service & maintenance
        'last_serviced_at',
        'next_service_due',
        'service_provider',
        // Warranty & insurance
        'warranty_expires_at',
        'insurance_reference',
        // Disposal
        'disposed_at',
        'disposal_reason',
        // Visibility
        'portal_visible',
    ];

    protected $casts = [
        'estimated_value' => 'decimal:2',
        'acquired_at' => 'date',
        'return_required' => 'boolean',
        'return_by' => 'date',
        'last_serviced_at' => 'date',
        'next_service_due' => 'date',
        'warranty_expires_at' => 'date',
        'disposed_at' => 'date',
        'portal_visible' => 'boolean',
    ];

    protected $appends = ['photo_url'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(SiteHouseRoom::class, 'room_id');
    }

    public function tracker(): BelongsTo
    {
        return $this->belongsTo(LocationHardware::class, 'tracker_hardware_id');
    }

    /**
     * Canonical device represented by the temporary LocationHardware bridge.
     *
     * client_personal_assets.tracker_hardware_id still references the legacy
     * compatibility table, so profile mutations resolve a canonical Device and
     * persist only its legacy_location_hardware_id until that bridge is retired.
     */
    public function trackerDevice(): HasOne
    {
        return $this->hasOne(Device::class, 'legacy_location_hardware_id', 'tracker_hardware_id');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    public function isServiceOverdue(): bool
    {
        return $this->next_service_due && $this->next_service_due->isPast();
    }

    public function isWarrantyExpired(): bool
    {
        return $this->warranty_expires_at && $this->warranty_expires_at->isPast();
    }

    public function isWarrantyExpiringSoon(int $days = 30): bool
    {
        return $this->warranty_expires_at
            && ! $this->isWarrantyExpired()
            && $this->warranty_expires_at->diffInDays(now()) <= $days;
    }

    public function isReturnOverdue(): bool
    {
        return $this->return_required && $this->return_by && $this->return_by->isPast() && $this->status === 'active';
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeNeedingAttention($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($sub) {
                $sub->whereNotNull('next_service_due')->where('next_service_due', '<=', now());
            })->orWhere(function ($sub) {
                $sub->whereNotNull('warranty_expires_at')->where('warranty_expires_at', '<=', now()->addDays(30));
            })->orWhere(function ($sub) {
                $sub->where('return_required', true)->whereNotNull('return_by')->where('return_by', '<=', now());
            })->orWhere('condition', 'poor');
        });
    }
}
