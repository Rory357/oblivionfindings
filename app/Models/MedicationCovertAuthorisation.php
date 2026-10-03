<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicationCovertAuthorisation extends Model
{
    use AuditableChanges, HasFactory;

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
        'structured_evidence',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
    ];

    protected $casts = [
        'structured_evidence' => 'array',
        'revoked_at' => 'datetime',
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

    public function files(): HasMany
    {
        return $this->hasMany(MedicationOrderFile::class, 'medication_covert_authorisation_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function isExpired(): bool
    {
        return $this->review_date && $this->review_date->toDateString() < now()->timezone('Pacific/Auckland')->toDateString();
    }
}
