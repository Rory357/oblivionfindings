<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientMedicationAdministration extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'corrected_of_id',
        'is_correction',
        'client_id',
        'client_medication_id',
        'shift_id',
        'service_context_id',
        'administered_by',
        'witnessed_by',
        'scheduled_for',
        'administered_at',
        'status',
        'reason',
        'correction_reason',
        'dose_given',
        'notes',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'administered_at' => 'datetime',
        'is_correction' => 'boolean',
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

    public function administeredBy()
    {
        return $this->belongsTo(User::class, 'administered_by');
    }

    public function witnessedBy()
    {
        return $this->belongsTo(User::class, 'witnessed_by');
    }

    public function round()
    {
        return $this->belongsTo(MedicationRound::class, 'medication_round_id');
    }

    public function prnEffectiveness()
    {
        return $this->hasOne(MedicationPrnEffectiveness::class, 'client_medication_administration_id');
    }
}
