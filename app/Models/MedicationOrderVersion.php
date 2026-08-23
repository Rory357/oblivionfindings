<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationOrderVersion extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $fillable = [
        'client_medication_id',
        'client_id',
        'version_number',
        'cessation_request_key',
        'cessation_payload_sha256',
        'name',
        'dosage',
        'dose_amount',
        'dose_unit',
        'frequency',
        'frequency_code',
        'dose_times',
        'route',
        'form',
        'instructions',
        'indication',
        'is_prn',
        'prn_reason',
        'max_per_day',
        'min_hours_between_doses',
        'controlled_drug',
        'high_risk',
        'witness_required',
        'prescriber',
        'pharmacy',
        'start_date',
        'end_date',
        'ceased_at',
        'ceased_reason',
        'state',
        'paused_at',
        'active',
        'change_reason',
        'changed_by',
        'changed_at',
    ];

    protected $casts = [
        'dose_times' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'ceased_at' => 'datetime',
        'paused_at' => 'datetime',
        'changed_at' => 'datetime',
        'is_prn' => 'boolean',
        'controlled_drug' => 'boolean',
        'high_risk' => 'boolean',
        'witness_required' => 'boolean',
        'active' => 'boolean',
        'max_per_day' => 'integer',
        'dose_amount' => 'decimal:4',
        'min_hours_between_doses' => 'float',
    ];

    protected $hidden = [
        'cessation_request_key',
        'cessation_payload_sha256',
    ];

    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id')->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('Medication order version evidence is immutable.');
        });

        static::deleting(function (): never {
            throw new \LogicException('Medication order version evidence is immutable.');
        });
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
     * Get change summary for audit trail
     */
    public function getChangeSummaryAttribute(): array
    {
        return [
            'version' => $this->version_number,
            'changed_at' => $this->changed_at?->toIso8601String(),
            'changed_by' => $this->changedBy?->name,
            'change_reason' => $this->change_reason,
            'state' => $this->state,
        ];
    }
}
