<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationStockCountRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'counted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $count): void {
            if ($count->isDirty(['lines', 'client_medication_stock_id', 'counted_by', 'counted_at', 'reason'])) {
                throw new \LogicException('The submitted count is permanent evidence.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Stock counts must be retained.'));
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(ClientMedicationStock::class, 'client_medication_stock_id');
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}

