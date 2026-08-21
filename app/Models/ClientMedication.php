<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientMedication extends Model
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;

    private const VERIFICATION_SENSITIVE_FIELDS = [
        'client_id',
        'created_by',
        'name',
        'dosage',
        'dose_amount',
        'dose_unit',
        'frequency',
        'frequency_code',
        'dose_times',
        'is_prn',
        'controlled_drug',
        'cd_schedule',
        'high_risk',
        'witness_required',
        'prn_reason',
        'max_per_day',
        'min_hours_between_doses',
        'route',
        'form',
        'prescriber',
        'indication',
        'pharmacy',
        'pharmac_therapeutic_group',
        'pharmac_subgroup',
        'start_date',
        'end_date',
        'review_date',
        'instructions',
        'barcode',
        'nzulm_code',
    ];

    protected $fillable = [
        'client_id',
        'created_by',
        'name',
        'dosage',
        'dose_amount',
        'dose_unit',
        'frequency',
        'frequency_code',
        'dose_times',
        'is_prn',
        'controlled_drug',
        'cd_schedule',
        'high_risk',
        'witness_required',
        'prn_reason',
        'max_per_day',
        'min_hours_between_doses',
        'route',
        'form',
        'prescriber',
        'indication',
        'pharmacy',
        'pharmac_therapeutic_group',
        'pharmac_subgroup',
        'start_date',
        'end_date',
        'review_date',
        'ceased_at',
        'ceased_reason',
        'ceased_by',
        'instructions',
        'active',
        'state',
        'approval_status',
        'verified_by',
        'verified_at',
        'rejection_reason',
        'paused_at',
        'version',
        'superseded_by',
        'superseded_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'review_date' => 'date',
        'ceased_at' => 'datetime',
        'paused_at' => 'datetime',
        'verified_at' => 'datetime',
        'superseded_at' => 'datetime',
        'is_prn' => 'boolean',
        'controlled_drug' => 'boolean',
        'cd_schedule' => 'integer',
        'high_risk' => 'boolean',
        'witness_required' => 'boolean',
        'active' => 'boolean',
        'dose_times' => 'array',
        'max_per_day' => 'integer',
        'dose_amount' => 'decimal:4',
        'min_hours_between_doses' => 'float',
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $medication): void {
            if ($medication->created_by !== null) {
                $medication->approval_status = 'pending_verification';
                $medication->verified_by = null;
                $medication->verified_at = null;
                $medication->rejection_reason = null;
            }
        });

        static::updating(function (self $medication): void {
            if (! $medication->isDirty(self::VERIFICATION_SENSITIVE_FIELDS)) {
                return;
            }

            $medication->forceFill([
                'approval_status' => 'pending_verification',
                'verified_by' => null,
                'verified_at' => null,
                'rejection_reason' => null,
            ]);
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ceasedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ceased_by');
    }

    public function verifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function stock(): HasOne
    {
        return $this->hasOne(ClientMedicationStock::class, 'client_medication_id');
    }

    public function administrations(): HasMany
    {
        return $this->hasMany(ClientMedicationAdministration::class, 'client_medication_id');
    }

    public function inrRecords(): HasMany
    {
        return $this->hasMany(ClientInrRecord::class, 'client_medication_id');
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
            ->where(function ($q) {
                $q->where('approval_status', 'verified')
                    ->orWhereNull('approval_status');
            })
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
     * Scope for active orders that are visible but cannot yet be administered.
     */
    public function scopeAwaitingVerification($query)
    {
        return $query->where('state', 'active')
            ->where('active', true)
            ->whereNull('superseded_by')
            ->whereNull('deleted_at')
            ->whereNotIn('approval_status', ['verified']);
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
     * High-risk order classes need a verifier other than their creator.
     */
    public function requiresIndependentVerification(): bool
    {
        return (bool) $this->high_risk
            || (bool) $this->controlled_drug
            || (bool) $this->witness_required;
    }

    /**
     * Canonical pre-transition evidence for the exact order revision being
     * verified or rejected. The audit log stores only the digest, avoiding a
     * second copy of medication instructions while still binding the decision
     * to the locked order, client, clinical fields, and scan identifiers.
     *
     * @return array<string, mixed>
     */
    public function verificationEvidence(): array
    {
        return [
            'order_id' => (int) $this->getKey(),
            'client_id' => (int) $this->client_id,
            'version' => (int) ($this->version ?? 1),
            'created_by' => $this->created_by !== null ? (int) $this->created_by : null,
            'name' => (string) $this->name,
            'dosage' => (string) $this->dosage,
            'dose_amount' => $this->dose_amount !== null ? (string) $this->dose_amount : null,
            'dose_unit' => $this->dose_unit,
            'frequency' => (string) $this->frequency,
            'frequency_code' => $this->frequency_code,
            'dose_times' => array_values($this->dose_times ?? []),
            'route' => $this->route,
            'form' => $this->form,
            'instructions' => $this->instructions,
            'indication' => $this->indication,
            'is_prn' => (bool) $this->is_prn,
            'controlled_drug' => (bool) $this->controlled_drug,
            'high_risk' => (bool) $this->high_risk,
            'witness_required' => (bool) $this->witness_required,
            'prescriber' => $this->prescriber,
            'barcode' => $this->getAttribute('barcode'),
            'nzulm_code' => $this->getAttribute('nzulm_code'),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'state' => (string) $this->state,
            'active' => (bool) $this->active,
            'approval_status' => $this->approval_status,
            'superseded_by' => $this->superseded_by !== null ? (int) $this->superseded_by : null,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    public function verificationEvidenceHash(): string
    {
        return hash('sha256', json_encode(
            $this->verificationEvidence(),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /**
     * Check if medication is currently active
     */
    public function isActive(): bool
    {
        return $this->state === 'active'
            && $this->active
            && $this->superseded_by === null
            && $this->deleted_at === null
            && $this->isVerifiedForAdministration();
    }

    public function isVerifiedForAdministration(): bool
    {
        return ($this->approval_status ?? 'verified') === 'verified';
    }

    public function isAdministrable(): bool
    {
        return $this->state === 'active'
            && (bool) $this->active
            && $this->superseded_by === null
            && $this->deleted_at === null
            && $this->isVerifiedForAdministration();
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new \LogicException('Medication orders are retained; discontinue the order instead.');
        });
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
        if (! $this->end_date) {
            return false;
        }

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
    public function getPrnLast24HoursAttribute(): Collection
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
        if (! $this->is_prn || ! $this->max_per_day) {
            return false;
        }

        $maxPerDay = (int) filter_var($this->max_per_day, FILTER_SANITIZE_NUMBER_INT);
        if ($maxPerDay <= 0) {
            return false;
        }

        $current = $this->prnCountLast24Hours;

        return $current >= ($maxPerDay * 0.75); // 75% threshold
    }

    /**
     * Check if PRN is over limit
     */
    public function isPrnOverLimit(): bool
    {
        if (! $this->is_prn || ! $this->max_per_day) {
            return false;
        }

        $maxPerDay = (int) filter_var($this->max_per_day, FILTER_SANITIZE_NUMBER_INT);
        if ($maxPerDay <= 0) {
            return false;
        }

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
        if (! $this->is_prn || ! $this->max_per_day) {
            return null;
        }

        $maxPerDay = (int) filter_var($this->max_per_day, FILTER_SANITIZE_NUMBER_INT);
        if ($maxPerDay <= 0) {
            return null;
        }

        $remaining = $maxPerDay - $this->prnCountLast24Hours;

        return max(0, $remaining);
    }

    /**
     * Create a new version of this medication order
     */
    public function createVersion(int $changedBy, ?string $changeReason = null): self
    {
        $isPrn = (bool) ($this->getAttribute('is_prn') ?? false);

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
        $newVersion->created_by = $changedBy;
        $newVersion->is_prn = $isPrn;
        $newVersion->approval_status = 'pending_verification';
        $newVersion->verified_by = null;
        $newVersion->verified_at = null;
        $newVersion->rejection_reason = null;
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
            'is_prn' => $isPrn,
            'prn_reason' => $this->prn_reason,
            'max_per_day' => $this->max_per_day,
            'min_hours_between_doses' => $this->min_hours_between_doses,
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
            'change_reason' => 'Medication paused: '.$reason,
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
