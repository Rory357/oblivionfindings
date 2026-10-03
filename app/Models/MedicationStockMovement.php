<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MedicationStockMovement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'balance_before' => 'decimal:2', 'balance_after' => 'decimal:2', 'recorded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock movements are permanent evidence; record a correction.'));
        static::deleting(fn () => throw new LogicException('Stock movements must be retained.'));
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(MedicationStockLot::class, 'medication_stock_lot_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function returns()
    {
        return $this->hasMany(self::class, 'return_of_id');
    }

    public function returnOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'return_of_id');
    }
}


