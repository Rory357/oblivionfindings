<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientMedication extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'name',
        'dosage',
        'dose_amount',
        'dose_unit',
        'frequency',
        'frequency_code',
        'dose_times',
        'is_prn',
        'controlled_drug',
        'high_risk',
        'witness_required',
        'prn_reason',
        'max_per_day',
        'route',
        'form',
        'prescriber',
        'indication',
        'pharmacy',
        'start_date',
        'end_date',
        'review_date',
        'ceased_at',
        'ceased_reason',
        'instructions',
        'active',
        'state',
        'paused_at',
        'version',
        'superseded_by',
        'superseded_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'review_date' => 'date',
        'ceased_at' => 'date',
        'paused_at' => 'datetime',
        'superseded_at' => 'datetime',
        'is_prn' => 'boolean',
        'controlled_drug' => 'boolean',
        'high_risk' => 'boolean',
        'witness_required' => 'boolean',
        'active' => 'boolean',
        'dose_times' => 'array',
        'dose_amount' => 'decimal:4',
        'version' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function stock(): HasOne
    {
        return $this->hasOne(ClientMedicationStock::class, 'client_medication_id');
    }

    public function administrations(): HasMany
    {
        return $this->hasMany(ClientMedicationAdministration::class, 'client_medication_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MedicationOrderVersion::class, 'client_medication_id')
            ->orderByDesc('version_number');
    }

    public function controlledDrugEntries(): HasMany
    {
        return $this->hasMany(ClientControlledDrugEntry::class, 'client_medication_id')
            ->orderByDesc('recorded_at');
    }

    public function scheduledStockCounts(): HasMany
    {
        return $this->hasMany(MedicationScheduledStockCount::class, 'client_medication_id');
    }

    public function dashboardAlerts(): HasMany
    {
        return $this->hasMany(MedicationDashboardAlert::class, 'client_medication_id');
    }

    public function prescriberOrders(): HasMany
    {
        return $this->hasMany(MedicationPrescriberOrder::class, 'client_medication_id');
    }

    public function destructions(): HasMany
    {
        return $this->hasMany(MedicationDestruction::class, 'client_medication_id');
    }

    public function covertAuthorisation(): HasOne
    {
        return $this->hasOne(MedicationCovertAuthorisation::class, 'client_medication_id')
            ->where('status', 'active');
    }

    public function pharmacyOrders(): HasMany
    {
        return $this->hasMany(MedicationPharmacyOrder::class, 'client_medication_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by');
    }

    public function supersededFrom(): HasMany
    {
        return $this->hasMany(self::class, 'superseded_by');
    }

    /**
     * Scope for active medications only
     */
    public function scopeActive($query)
    {
        return $query->where('state', 'active')
            ->where('active', true)
            ->whereNull('superseded_by')
            ->whereNull('deleted_at');
    }

    /**
     * Scope for current medications (not superseded)
     */
    public function scopeCurrent($query)
    {
        return $query->whereNull('superseded_by')
            ->whereNull('deleted_at');
    }

    /**
     * Scope for controlled drugs
     */
    public function scopeControlled($query)
    {
        return $query->where('controlled_drug', true);
    }

    /**
     * Scope for PRN medications
     */
    public function scopePrn($query)
    {
        return $query->where('is_prn', true);
    }

    /**
     * Scope for high-risk medications
     */
    public function scopeHighRisk($query)
    {
        return $query->where('high_risk', true);
    }

    /**
     * Check if medication requires a witness
     */
    public function requiresWitness(): bool
    {
        return $this->witness_required || $this->controlled_drug;
    }

    /**
     * Check if medication is currently active
     */
    public function isActive(): bool
    {
        return $this->state === 'active' 
            && $this->active 
            && $this->superseded_by === null
            && $this->deleted_at === null;
    }

    /**
     * Check if medication has expired
     */
    public function isExpired(): bool
    {
        return $this->end_date && $this->end_date->isPast();
    }

    /**
     * Check if medication is expiring soon (within 7 days)
     */
    public function isExpiringSoon(int $days = 7): bool
    {
        if (!$this->end_date) return false;
        return $this->end_date->diffInDays(now(), false) <= $days && $this->end_date->isFuture();
    }

    /**
     * Get formatted dose display
     */
    public function getFormattedDoseAttribute(): string
    {
        if ($this->dose_amount && $this->dose_unit) {
            return "{$this->dose_amount} {$this->dose_unit}";
        }
        return $this->dosage ?? '—';
    }

    /**
     * Get PRN administrations in last 24 hours
     */
    public function getPrnLast24HoursAttribute(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->administrations()
            ->where('status', 'given')
            ->where('administered_at', '>=', now()->subHours(24))
            ->orderByDesc('administered_at')
            ->get();
    }

    /**
     * Get PRN count in last 24 hours
     */
    public function getPrnCountLast24HoursAttribute(): int
    {
        return $this->prnLast24Hours->count();
    }

    /**
     * Check if PRN is near limit
     */
    public function isPrnNearLimit(): bool
    {
        if (!$this->is_prn || !$this->max_per_day) return false;
        
        $maxPerDay = (int) filter_var($this->max_per_day, FILTER_SANITIZE_NUMBER_INT);
        if ($maxPerDay <= 0) return false;
        
        $current = $this->prnCountLast24Hours;
        return $current >= ($maxPerDay * 0.75); // 75% threshold
    }

    /**
     * Check if PRN is over limit
     */
    public function isPrnOverLimit(): bool
    {
        if (!$this->is_prn || !$this->max_per_day) return false;
        
        $maxPerDay = (int) filter_var($this->max_per_day, FILTER_SANITIZE_NUMBER_INT);
        if ($maxPerDay <= 0) return false;
        
        return $this->prnCountLast24Hours >= $maxPerDay;
    }

    /**
     * Check if PRN is blocked (over limit)
     */
    public function isPrnBlocked(): bool
    {
        return $this->isPrnOverLimit();
    }

    /**
     * Get remaining PRN doses for today
     */
    public function getPrnRemainingAttribute(): ?int
    {
        if (!$this->is_prn || !$this->max_per_day) return null;
        
        $maxPerDay = (int) filter_var($this->max_per_day, FILTER_SANITIZE_NUMBER_INT);
        if ($maxPerDay <= 0) return null;
        
        $remaining = $maxPerDay - $this->prnCountLast24Hours;
        return max(0, $remaining);
    }

    /**
     * Create a new version of this medication order
     */
    public function createVersion(int $changedBy, ?string $changeReason = null): self
    {
        // Mark current as superseded
        $this->superseded_at = now();
        $this->save();

        // Create new medication record
        $newVersion = $this->replicate([
            'id',
            'created_at',
            'updated_at',
            'superseded_at',
            'superseded_by',
        ]);
        $newVersion->version = ($this->version ?? 1) + 1;
        $newVersion->save();

        // Link old to new
        $this->superseded_by = $newVersion->id;
        $this->save();

        // Create version history record
        MedicationOrderVersion::create([
            'client_medication_id' => $this->id,
            'client_id' => $this->client_id,
            'version_number' => $this->version ?? 1,
            'name' => $this->name,
            'dosage' => $this->dosage,
            'dose_amount' => $this->dose_amount,
            'dose_unit' => $this->dose_unit,
            'frequency' => $this->frequency,
            'frequency_code' => $this->frequency_code,
            'dose_times' => $this->dose_times,
            'route' => $this->route,
            'form' => $this->form,
            'instructions' => $this->instructions,
            'indication' => $this->indication,
            'is_prn' => $this->is_prn,
            'prn_reason' => $this->prn_reason,
            'max_per_day' => $this->max_per_day,
            'controlled_drug' => $this->controlled_drug,
            'high_risk' => $this->high_risk,
            'witness_required' => $this->witness_required,
            'prescriber' => $this->prescriber,
            'pharmacy' => $this->pharmacy,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'ceased_at' => $this->ceased_at,
            'ceased_reason' => $this->ceased_reason,
            'state' => $this->state,
            'paused_at' => $this->paused_at,
            'active' => $this->active,
            'change_reason' => $changeReason,
            'changed_by' => $changedBy,
            'changed_at' => now(),
        ]);

        return $newVersion;
    }

    /**
     * Get all version history
     */
    public function getVersionHistory(): array
    {
        $history = $this->versions->toArray();
        
        // Add current version
        array_unshift($history, [
            'version_number' => $this->version ?? 1,
            'name' => $this->name,
            'dosage' => $this->dosage,
            'state' => $this->state,
            'changed_at' => $this->updated_at?->toIso8601String(),
            'is_current' => true,
        ]);
        
        return $history;
    }

    /**
     * Cease this medication
     */
    public function cease(string $reason, ?int $ceasedBy = null): void
    {
        $this->state = 'ceased';
        $this->ceased_at = now();
        $this->ceased_reason = $reason;
        $this->active = false;
        $this->save();

        // Create version record for audit
        MedicationOrderVersion::create([
            'client_medication_id' => $this->id,
            'client_id' => $this->client_id,
            'version_number' => ($this->version ?? 1) + 1,
            'name' => $this->name,
            'dosage' => $this->dosage,
            'state' => 'ceased',
            'ceased_at' => $this->ceased_at,
            'ceased_reason' => $reason,
            'change_reason' => 'Medication ceased: ' . $reason,
            'changed_by' => $ceasedBy,
            'changed_at' => now(),
        ]);
    }

    /**
     * Pause this medication
     */
    public function pause(string $reason, ?int $pausedBy = null): void
    {
        $this->state = 'paused';
        $this->paused_at = now();
        $this->active = false;
        $this->save();

        // Create version record for audit
        MedicationOrderVersion::create([
            'client_medication_id' => $this->id,
            'client_id' => $this->client_id,
            'version_number' => ($this->version ?? 1) + 1,
            'name' => $this->name,
            'dosage' => $this->dosage,
            'state' => 'paused',
            'paused_at' => $this->paused_at,
            'change_reason' => 'Medication paused: ' . $reason,
            'changed_by' => $pausedBy,
            'changed_at' => now(),
        ]);
    }

    /**
     * Resume this medication
     */
    public function resume(?int $resumedBy = null): void
    {
        $this->state = 'active';
        $this->paused_at = null;
        $this->active = true;
        $this->save();

        // Create version record for audit
        MedicationOrderVersion::create([
            'client_medication_id' => $this->id,
            'client_id' => $this->client_id,
            'version_number' => ($this->version ?? 1) + 1,
            'name' => $this->name,
            'dosage' => $this->dosage,
            'state' => 'active',
            'change_reason' => 'Medication resumed',
            'changed_by' => $resumedBy,
            'changed_at' => now(),
        ]);
    }
}
