<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MedicationExternalGrant extends Model
{
    protected $table = 'medication_external_grants';

    protected $guarded = ['id'];

    protected $casts = ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'can_propose' => 'boolean', 'include_controlled' => 'boolean'];

    public function clinician()
    {
        return $this->belongsTo(MedicationExternalClinician::class, 'clinician_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
