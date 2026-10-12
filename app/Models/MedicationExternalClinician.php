<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MedicationExternalClinician extends Model
{
    protected $table = 'medication_external_clinicians';

    protected $guarded = ['id'];

    protected $casts = ['identity_evidence' => 'encrypted', 'identity_verified_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'register_checked_at' => 'date'];

    /** EA-104: the registration check was recorded as structured evidence. */
    public function hasStructuredCheck(): bool
    {
        return $this->registration_check === 'structured' && $this->register_checked_at !== null;
    }

    /** The yearly re-check of the public register is due (structured records only). */
    public function registerRecheckDue(): bool
    {
        return $this->hasStructuredCheck() && $this->register_checked_at->copy()->addYear()->isPast();
    }

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
