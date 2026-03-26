<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientMedicationStock extends Model
{
    use HasFactory;
    use AuditableChanges;

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
    ];

    protected $casts = [
        'last_counted_at' => 'datetime',
        'expiry_date' => 'date',
        'last_reorder_alert_at' => 'datetime',
    ];

    public function medication()
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id');
    }

    // ─── Scopes ─────────────────────────────────────────────

    /**
     * Stocks expiring within the given number of days.
     */
    public function scopeExpiringSoon(Builder $query, int $days = 30): Builder
    {
        return $query->whereNotNull('expiry_date')
            ->where('expiry_date', '>', Carbon::today())
            ->where('expiry_date', '<=', Carbon::today()->addDays($days));
    }

    /**
     * Stocks that have already expired.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', Carbon::today());
    }

    /**
     * Stocks where on_hand is at or below the reorder level.
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereNotNull('reorder_level')
            ->whereColumn('on_hand', '<=', 'reorder_level');
    }

    // ─── Helper Methods ─────────────────────────────────────

    /**
     * Check if this stock has expired.
     */
    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->lte(Carbon::today());
    }

    /**
     * Check if this stock is expiring soon (within given days).
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        if (! $this->expiry_date || $this->isExpired()) {
            return false;
        }

        return $this->expiry_date->lte(Carbon::today()->addDays($days));
    }

    /**
     * Check if stock is at or below reorder level.
     */
    public function isLowStock(): bool
    {
        return $this->reorder_level !== null && $this->on_hand <= $this->reorder_level;
    }

    /**
     * Check if this stock needs reorder (low stock or expiring soon).
     */
    public function needsReorder(): bool
    {
        return $this->isLowStock() || $this->isExpiringSoon() || $this->isExpired();
    }
}
