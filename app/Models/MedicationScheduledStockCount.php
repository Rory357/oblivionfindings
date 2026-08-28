<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Support\Medication\MedicationStockQuantity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationScheduledStockCount extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $fillable = [
        'client_id',
        'client_medication_id',
        'scheduled_date',
        'scheduled_time',
        'status',
        'expected_quantity',
        'actual_quantity',
        'discrepancy',
        'notes',
        'completed_by',
        'witnessed_by',
        'completed_at',
    ];

    protected $casts = [
        'expected_quantity' => 'decimal:2',
        'actual_quantity' => 'decimal:2',
        'discrepancy' => 'decimal:2',
        'scheduled_date' => 'date',
        'scheduled_time' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function medication(): BelongsTo
    {
        // Pending counts survive medication discontinuation. Authorization
        // must still see the historical controlled-drug classification.
        return $this->belongsTo(ClientMedication::class, 'client_medication_id')->withTrashed();
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function witnessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'witnessed_by');
    }

    /**
     * Check if count is overdue
     */
    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->scheduled_date->isPast();
    }

    /**
     * Mark as completed
     */
    public function complete(int|float|string $actualQty, ?string $notes = null, ?int $completedBy = null, ?int $witnessedBy = null): void
    {
        $actualQuantity = MedicationStockQuantity::normalize($actualQty);
        $expectedQuantity = $this->expected_quantity ?? $actualQuantity;

        $this->actual_quantity = $actualQuantity;
        $this->discrepancy = MedicationStockQuantity::subtract($actualQuantity, $expectedQuantity);
        $this->notes = $notes;
        $this->completed_by = $completedBy;
        $this->witnessed_by = $witnessedBy;
        $this->completed_at = now();
        $this->status = 'completed';
        $this->save();
    }

    /**
     * Scope for overdue counts
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', 'pending')
            ->where('scheduled_date', '<', now()->toDateString());
    }

    /**
     * Scope for today's counts
     */
    public function scopeToday($query)
    {
        return $query->where('scheduled_date', now()->toDateString());
    }

    /**
     * Scope for pending counts
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
