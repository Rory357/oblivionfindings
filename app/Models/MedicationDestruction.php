<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MedicationDestruction extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id',
        'client_medication_id',
        'site_id',
        'medication_name',
        'form',
        'strength',
        'quantity',
        'unit',
        'batch_number',
        'expiry_date',
        'reason',
        'disposal_method',
        'is_controlled_drug',
        'controlled_drug_class',
        'authorised_by_name',
        'authorised_by_registration',
        'destroyed_by',
        'witness_1_id',
        'witness_2_id',
        'destroyed_at',
        'notes',
        'photo_path',
        'voided_at',
        'void_reason',
        'voided_by',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'destroyed_at' => 'datetime',
        'voided_at' => 'datetime',
        'is_controlled_drug' => 'boolean',
        'quantity' => 'decimal:2',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function medication()
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id');
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function destroyedByUser()
    {
        return $this->belongsTo(User::class, 'destroyed_by');
    }

    public function witness1()
    {
        return $this->belongsTo(User::class, 'witness_1_id');
    }

    public function witness2()
    {
        return $this->belongsTo(User::class, 'witness_2_id');
    }

    public function voidedByUser()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function scopeControlled($query)
    {
        return $query->where('is_controlled_drug', true);
    }

    /**
     * Records that have not been voided — the live disposal register. Voided
     * records remain in the table (immutable, MoD Regs 1977) but are superseded.
     */
    public function scopeVerified($query)
    {
        return $query->whereNull('voided_at');
    }

    public function getIsVoidedAttribute(): bool
    {
        return $this->voided_at !== null;
    }

    public function getReasonLabelAttribute(): string
    {
        return match ($this->reason) {
            'expired' => 'Expired',
            'ceased' => 'Medication Ceased',
            'contaminated' => 'Contaminated',
            'damaged' => 'Damaged',
            'deceased' => 'Client Deceased',
            'discharged' => 'Client Discharged',
            'surplus' => 'Surplus Stock',
            default => ucfirst($this->reason ?? ''),
        };
    }

    public function getDisposalMethodLabelAttribute(): string
    {
        return match ($this->disposal_method) {
            'pharmacy_return' => 'Return to Pharmacy',
            'incineration' => 'Incineration',
            'denaturing' => 'Denaturing',
            'sharps_bin' => 'Sharps Bin',
            'other' => 'Other',
            default => ucfirst($this->disposal_method ?? ''),
        };
    }
}
