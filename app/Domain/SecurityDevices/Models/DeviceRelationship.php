<?php

namespace App\Domain\SecurityDevices\Models;

use App\Domain\SecurityDevices\Enums\RelationshipType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceRelationship extends Model
{
    protected $table = 'device_relationships';

    protected $fillable = [
        'parent_device_id',
        'child_device_id',
        'relationship_type',
        'port',
        'notes',
    ];

    protected $casts = [
        'relationship_type' => RelationshipType::class,
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'parent_device_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'child_device_id');
    }
}
