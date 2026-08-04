<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @deprecated Read-only migration access for the retired geofence_zones table.
 *             Runtime geofences are stored in AssetGeofence.
 */
class GeofenceZone extends Model
{
    use HasFactory;

    protected $table = 'geofence_zones';

    protected $guarded = ['*'];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_active' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    protected static function booted(): void
    {
        static::saving(fn () => throw new LogicException(
            'GeofenceZone is retired. Use the canonical AssetGeofence store.',
        ));
        static::deleting(fn () => throw new LogicException(
            'GeofenceZone is retired. Migrate legacy rows before removing them.',
        ));
    }
}
