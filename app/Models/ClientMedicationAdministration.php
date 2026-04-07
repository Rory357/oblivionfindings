<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientMedicationAdministration extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'corrected_of_id',
        'is_correction',
        'client_id',
        'client_medication_id',
        'shift_id',
        'medication_round_id',
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
        'review_required',
        'review_reason',
        'review_flagged_at',
        'review_flagged_by',
        'blood_glucose_level',
        'insulin_units_given',
        'injection_site',
        'inhaler_technique_observed',
        'spacer_used',
        'peak_flow_before',
        'peak_flow_after',
        'topical_area',
        'topical_skin_condition',
        'correction_status',
        'correction_approved_by',
        'correction_approved_at',
        'correction_rejection_reason',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'administered_at' => 'datetime',
        'is_correction' => 'boolean',
        'review_required' => 'boolean',
        'review_flagged_at' => 'datetime',
        'blood_glucose_level' => 'decimal:1',
        'insulin_units_given' => 'decimal:1',
        'inhaler_technique_observed' => 'boolean',
        'spacer_used' => 'boolean',
        'peak_flow_before' => 'integer',
        'peak_flow_after' => 'integer',
        'correction_approved_at' => 'datetime',
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

    public function reviewFlaggedBy()
    {
        return $this->belongsTo(User::class, 'review_flagged_by');
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
