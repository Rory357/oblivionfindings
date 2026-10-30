<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Services\Medication\Stock\StockAvailability;
use App\Support\Medication\MedicationStockQuantity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientMedicationStock extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $appends = ['available_on_hand', 'pack_workflow_url'];

    private bool $writingPackEvidence = false;

    protected static function booted(): void
    {
        static::saving(function (self $stock): void {
            // Once packs exist, ordinary AND controlled scalar changes must
            // come from the governed pack/register adapter, never a legacy form.
            $started = $stock->exists && $stock->getRawOriginal('lots_started_at') !== null;
            if ($started && ! $stock->writingPackEvidence
                && $stock->isDirty(['on_hand', 'unit', 'batch_number', 'expiry_date', 'last_counted_at', 'lots_started_at'])) {
                $stock->rejectScalarWrite('on_hand');
            }
        });
    }

    public function rejectScalarWrite(string $field = 'quantity'): void
    {
        if ($this->lots_started_at !== null) {
            throw ValidationException::withMessages([
                $field => 'This medicine uses pack records. Receive, count or remove the actual packs in Stock & controlled drugs. Your entries have not been applied.',
                'stock_workflow_url' => $this->packWorkflowUrl(),
            ]);
        }
    }

    public function packWorkflowUrl(): string
    {
        return '/emar/stock/packs?medication_id='.$this->client_medication_id;
    }

    /** Internal ledger write after canonical stock/lot locks and validation. */
    public function saveFromPackLedger(): bool
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Pack evidence must be saved in the governing stock transaction.');
        }
        $this->writingPackEvidence = true;
        try {
            return $this->save();
        } finally {
            $this->writingPackEvidence = false;
        }
    }

    public function availableQuantity(): ?string
    {
        return app(StockAvailability::class)->quantity($this);
    }

    public function usableQuantity(): ?string
    {
        return app(StockAvailability::class)->usableQuantity($this);
    }

    public function currentExpiryDate(): ?CarbonInterface
    {
        return $this->lots_started_at === null ? $this->expiry_date
            : app(StockAvailability::class)->nextUsablePack($this)?->expiry_date;
    }

    public function currentBatchNumber(): ?string
    {
        return $this->lots_started_at === null ? $this->batch_number
            : app(StockAvailability::class)->nextUsablePack($this)?->batch_number;
    }

    public function packExpiries(bool $expired, int $days = 30): Collection
    {
        return app(StockAvailability::class)->expiries($this, $expired, $days);
    }

    public function getAvailableOnHandAttribute(): ?string
    {
        return $this->availableQuantity();
    }

    public function getPackWorkflowUrlAttribute(): ?string
    {
        return $this->lots_started_at !== null ? $this->packWorkflowUrl() : null;
    }

    protected $fillable = [
        'client_medication_id',
        'on_hand',
        'unit',
        'reorder_level',
        'reorder_quantity',
        'last_counted_at',
        'notes',
        'expiry_date',
        'batch_number',
        'last_reorder_alert_at',
        'supplier_name',
        'storage_condition',
    ];

    protected $casts = [
        'on_hand' => 'decimal:2',
        'last_counted_at' => 'datetime',
        'lots_started_at' => 'datetime',
        'expiry_date' => 'date',
        'last_reorder_alert_at' => 'datetime',
    ];

    public function medication()
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id');
    }

    public function lots()
    {
        return $this->hasMany(MedicationStockLot::class, 'client_medication_stock_id');
    }

    // ─── Scopes ─────────────────────────────────────────────

    /**
     * Stocks expiring within the given number of days.
     */
    public function scopeExpiringSoon(Builder $query, int $days = 30): Builder
    {
        return StockAvailability::expiryScope($query, false, $days);
    }

    /**
     * Stocks that have already expired.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return StockAvailability::expiryScope($query, true);
    }

    /**
     * Stocks where on_hand is at or below the reorder level.
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereNotNull('reorder_level')->whereRaw('('.StockAvailability::usableQuantitySql().') <= client_medication_stocks.reorder_level', [StockAvailability::today()]);
    }

    // ─── Helper Methods ─────────────────────────────────────

    /**
     * Check if this stock has expired.
     */
    public function isExpired(): bool
    {
        return $this->packExpiries(true)->isNotEmpty();
    }

    /**
     * Check if this stock is expiring soon (within given days).
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->packExpiries(false, $days)->isNotEmpty();
    }

    /**
     * Check if stock is at or below reorder level.
     */
    public function isLowStock(): bool
    {
        return $this->reorder_level !== null
            && $this->usableQuantity() !== null
            && MedicationStockQuantity::lessThanOrEqual($this->usableQuantity(), $this->reorder_level);
    }

    /**
     * Whether this medication must be kept in the cold chain (fridge) or a
     * controlled room temperature — anything other than ambient storage.
     */
    public function requiresColdChain(): bool
    {
        return in_array($this->storage_condition, ['fridge', 'controlled_room'], true);
    }

    /**
     * Check if this stock needs reorder (low stock or expiring soon).
     */
    public function needsReorder(): bool
    {
        return $this->isLowStock() || $this->isExpiringSoon() || $this->isExpired();
    }
}
