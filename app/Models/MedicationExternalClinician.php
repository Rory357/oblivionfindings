<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MedicationExternalClinician extends Model
{
    protected $table = 'medication_external_clinicians';

    protected $guarded = ['id'];

    protected $casts = ['identity_evidence' => 'encrypted', 'identity_verified_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function grants()
    {
        return $this->hasMany(MedicationExternalGrant::class, 'clinician_id');
    }

    public function active(): bool
    {
        return $this->revoked_at === null && $this->identity_verified_at !== null && $this->expires_at?->isFuture();
    }
}
