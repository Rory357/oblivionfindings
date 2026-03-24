<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientMedicalProfile extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'client_id',
        'medical_history',
        'disabilities',
        'allergies',
        'notes',
        'gp_name',
        'gp_practice',
        'gp_phone',
        'hospital_preference',
        'blood_type',
        'organ_donor',
        'immunisation_notes',
        'mental_health_history',
        'surgical_history',
    ];

    protected $casts = [
        'organ_donor' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
