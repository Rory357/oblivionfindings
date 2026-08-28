<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientControlledDrugEntry extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $fillable = [
        'client_id',
        'client_medication_id',
        'pharmacy_order_id',
        'shift_id',
        'service_context_id',
        'entry_type',
        'quantity',
        'unit',
        'batch_number',
        'expiry_date',
        'on_hand_before',
        'on_hand_after',
        'reason',
        'notes',
        'recorded_at',
        'recorded_by',
        'witnessed_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'on_hand_before' => 'decimal:2',
        'on_hand_after' => 'decimal:2',
        'recorded_at' => 'datetime',
        'expiry_date' => 'date',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function medication()
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id')->withTrashed();
    }

    public function pharmacyOrder()
    {
        return $this->belongsTo(MedicationPharmacyOrder::class, 'pharmacy_order_id');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function serviceContext()
    {
        return $this->belongsTo(ServiceContext::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function witnessedBy()
    {
        return $this->belongsTo(User::class, 'witnessed_by');
    }
}
