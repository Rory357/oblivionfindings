<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientControlledDrugEntry extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'client_id',
        'client_medication_id',
        'shift_id',
        'service_context_id',
        'entry_type',
        'quantity',
        'unit',
        'on_hand_before',
        'on_hand_after',
        'reason',
        'notes',
        'recorded_at',
        'recorded_by',
        'witnessed_by',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function medication()
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id');
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
