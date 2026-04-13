<?php

namespace App\Domain\SecurityDevices\Models;

use App\Domain\SecurityDevices\Enums\LinkType;
use App\Models\Asset;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceAssetLink extends Model
{
    use AuditableChanges;

    protected $table = 'device_asset_links';

    protected $fillable = [
        'device_id',
        'asset_id',
        'link_type',
        'linked_at',
        'unlinked_at',
        'linked_by_user_id',
        'notes',
    ];

    protected $casts = [
        'link_type' => LinkType::class,
        'linked_at' => 'datetime',
        'unlinked_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->whereNull('unlinked_at');
    }

    public function scopeForAsset($query, int $assetId)
    {
        return $query->where('asset_id', $assetId);
    }

    public function scopeForDevice($query, int $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    // ── Helpers ────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->unlinked_at === null;
    }
}
