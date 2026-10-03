<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationStockPhoto extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path', 'sha256'];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime', 'bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Keep previous pack photos; add a new photo record.'));
        static::deleting(fn () => throw new \LogicException('Pack photo history must be retained.'));
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(MedicationStockLot::class, 'medication_stock_lot_id');
    }

    public function takenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }
}

