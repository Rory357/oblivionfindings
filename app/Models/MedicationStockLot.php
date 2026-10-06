<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicationStockLot extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:2',
            'quantity_remaining' => 'decimal:2',
            'expiry_date' => 'date',
            'batch_not_printed' => 'boolean',
            'expiry_not_printed' => 'boolean',
            'received_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $lot): void {
            if ($lot->isDirty(['client_medication_stock_id', 'batch_number', 'batch_not_printed', 'expiry_date', 'expiry_not_printed',
                'quantity_received', 'source', 'source_reference', 'received_by', 'received_at', 'pharmacy_order_id', 'controlled_entry_id'])) {
                throw new \LogicException('Pack receipt provenance is permanent. Keep the original receipt and record a correction.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Pack receipts and their history must be retained.'));
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(ClientMedicationStock::class, 'client_medication_stock_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(MedicationStockMovement::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(MedicationStockPhoto::class)->orderByDesc('taken_at')->orderByDesc('id');
    }

    public function pharmacyOrder(): BelongsTo
    {
        return $this->belongsTo(MedicationPharmacyOrder::class);
    }
}
