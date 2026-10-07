<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MedicationExternalProposal extends Model
{
    protected $table = 'medication_external_proposals';

    protected $guarded = ['id'];

    protected $casts = ['prescription' => 'encrypted:array', 'reason' => 'encrypted', 'decision_evidence' => 'encrypted:array', 'decision_note' => 'encrypted', 'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'expected_version' => 'integer', 'controlled' => 'boolean'];

    public function clinician()
    {
        return $this->belongsTo(MedicationExternalClinician::class, 'clinician_id');
    }

    public function grant()
    {
        return $this->belongsTo(MedicationExternalGrant::class, 'grant_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
