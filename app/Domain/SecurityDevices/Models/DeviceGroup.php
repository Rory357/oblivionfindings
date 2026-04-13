<?php

namespace App\Domain\SecurityDevices\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeviceGroup extends Model
{
    use SoftDeletes;

    protected $table = 'device_groups';

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'description',
        'auto_rules',
    ];

    protected $casts = [
        'auto_rules' => 'array',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'device_group_members');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
