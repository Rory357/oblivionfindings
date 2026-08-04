<?php

namespace App\Domain\SecurityDevices\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeviceGroup extends Model
{
    use SoftDeletes, WritesLegacyStorageContext;

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
}
