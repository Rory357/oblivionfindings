<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetVehicleOdometerObservation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'asset_id', 'value_km', 'observed_at', 'source_kind', 'source_type', 'source_id',
        'source_reference', 'recorded_by_user_id', 'corrects_observation_id', 'correction_reason',
        'request_key', 'request_fingerprint', 'created_at',
    ];

    protected $casts = ['value_km' => 'decimal:1', 'observed_at' => 'datetime', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Vehicle odometer observations are immutable.'));
        static::deleting(fn () => throw new \LogicException('Vehicle odometer observations are immutable.'));
    }

    public function asset(): BelongsTo { return $this->belongsTo(Asset::class); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by_user_id'); }
    public function corrects(): BelongsTo { return $this->belongsTo(self::class, 'corrects_observation_id'); }
    public function corrections(): HasMany { return $this->hasMany(self::class, 'corrects_observation_id'); }
}
