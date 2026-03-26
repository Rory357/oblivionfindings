<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicationPrescriberOrder extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'client_id',
        'client_medication_id',
        'order_type',
        'status',
        'prescriber_name',
        'prescriber_registration',
        'prescriber_type',
        'medication_name',
        'dose',
        'route',
        'frequency',
        'instructions',
        'indication',
        'clinical_notes',
        'order_date',
        'effective_date',
        'expiry_date',
        'requires_countersign',
        'countersigned_at',
        'countersigned_by',
        'received_by',
        'dispensed_by',
        'dispensed_at',
        'pharmacy_notes',
        'pharmacy_name',
        'batch_number',
        'batch_expiry',
    ];

    protected $casts = [
        'order_date' => 'date',
        'effective_date' => 'date',
        'expiry_date' => 'date',
        'batch_expiry' => 'date',
        'countersigned_at' => 'datetime',
        'dispensed_at' => 'datetime',
        'requires_countersign' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function medication()
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id');
    }

    public function receivedByUser()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function countersignedByUser()
    {
        return $this->belongsTo(User::class, 'countersigned_by');
    }

    public function dispensedByUser()
    {
        return $this->belongsTo(User::class, 'dispensed_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAwaitingCountersign($query)
    {
        return $query->where('requires_countersign', true)->whereNull('countersigned_at');
    }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function needsCountersign(): bool
    {
        return $this->requires_countersign && !$this->countersigned_at;
    }
}
