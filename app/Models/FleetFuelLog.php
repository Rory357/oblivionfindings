<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetFuelLog extends Model
{
    protected $fillable = [
        'asset_id',
        'user_id',
        'logged_at',
        'fuel_type',
        'quantity_litres',
        'cost_per_litre',
        'total_cost',
        'odometer_km',
        'full_tank',
        'station_name',
        'location',
        'receipt_path',
        'notes',
    ];

    protected $casts = [
        'logged_at' => 'datetime',
        'quantity_litres' => 'decimal:2',
        'cost_per_litre' => 'decimal:3',
        'total_cost' => 'decimal:2',
        'odometer_km' => 'decimal:1',
        'full_tank' => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Calculate fuel efficiency (km per litre) based on previous full tank fill-up.
     */
    public function calculateEfficiency(): ?float
    {
        if (!$this->full_tank || !$this->odometer_km) {
            return null;
        }

        $previousFullTank = static::query()
            ->where('asset_id', $this->asset_id)
            ->where('full_tank', true)
            ->where('logged_at', '<', $this->logged_at)
            ->orderByDesc('logged_at')
            ->first();

        if (!$previousFullTank || !$previousFullTank->odometer_km) {
            return null;
        }

        $distanceKm = $this->odometer_km - $previousFullTank->odometer_km;
        if ($distanceKm <= 0 || $this->quantity_litres <= 0) {
            return null;
        }

        return round($distanceKm / $this->quantity_litres, 2);
    }
}
