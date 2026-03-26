<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicationCovertAuthorisation extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'client_id',
        'client_medication_id',
        'authorised_by_name',
        'authorised_by_registration',
        'clinical_justification',
        'legal_basis',
        'administration_method',
        'pharmacist_advice',
        'authorised_date',
        'review_date',
        'status',
        'recorded_by',
    ];

    protected $casts = [
        'authorised_date' => 'date',
        'review_date' => 'date',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function medication()
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id');
    }

    public function recordedByUser()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function isExpired(): bool
    {
        return $this->review_date && $this->review_date->isPast();
    }
}
