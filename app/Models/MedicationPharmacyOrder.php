<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicationPharmacyOrder extends Model
{
    use AuditableChanges, HasFactory;

    protected $fillable = [
        'client_id',
        'client_medication_id',
        'pharmacy_name',
        'pharmacy_phone',
        'pharmacy_email',
        'order_type',
        'status',
        'order_notes',
        'ordered_by',
        'submitted_at',
        'confirmed_at',
        'dispensed_at',
        'delivered_at',
        'received_by',
        'quantity_ordered',
        'quantity_received',
        'batch_number',
        'batch_expiry',
        'delivery_notes',
        'needed_by',
        'expected_delivery',
        'communication_method',
        'communication_reference',
        'communication_recorded_by',
        'communication_recorded_at',
        'closed_at',
        'closed_by',
        'closure_reason',
        'quantity_dispensed',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'dispensed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'batch_expiry' => 'date',
        'needed_by' => 'date:Y-m-d',
        'expected_delivery' => 'date:Y-m-d',
        'communication_recorded_at' => 'datetime',
        'closed_at' => 'datetime',
        'quantity_dispensed' => 'decimal:2',
        'quantity_ordered' => 'integer',
        'quantity_received' => 'decimal:2',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function medication()
    {
        // Pharmacy orders are retained after a medication is discontinued.
        // Keep the historical medication classification available so a soft
        // delete cannot erase controlled-drug authorization requirements.
        return $this->belongsTo(ClientMedication::class, 'client_medication_id')->withTrashed();
    }

    public function orderedByUser()
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    public function receivedByUser()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function controlledDrugEntry()
    {
        return $this->hasOne(ClientControlledDrugEntry::class, 'pharmacy_order_id');
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['draft', 'submitted', 'confirmed']);
    }

    public function scopeAwaitingDelivery($query)
    {
        return $query->whereIn('status', ['confirmed', 'dispensed']);
    }
}
