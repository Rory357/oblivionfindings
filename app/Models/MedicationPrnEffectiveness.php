<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicationPrnEffectiveness extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'medication_prn_effectiveness';

    protected $fillable = [
        'client_medication_administration_id',
        'client_id',
        'client_medication_id',
        'effectiveness',
        'review_minutes_after',
        'observations',
        'escalation_needed',
        'escalation_action',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'escalation_needed' => 'boolean',
        'review_minutes_after' => 'integer',
    ];

    public function administration()
    {
        return $this->belongsTo(ClientMedicationAdministration::class, 'client_medication_administration_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function medication()
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id');
    }

    public function reviewedByUser()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function getEffectivenessLabelAttribute(): string
    {
        return match ($this->effectiveness) {
            'effective' => 'Effective',
            'partially_effective' => 'Partially Effective',
            'not_effective' => 'Not Effective',
            default => 'Unknown',
        };
    }
}
