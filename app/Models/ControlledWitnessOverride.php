<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ControlledWitnessOverride extends Model
{
    public function administrations(): HasMany
    {
        return $this->hasMany(ClientMedicationAdministration::class, 'witness_override_id');
    }

    protected $guarded = [];

    protected $casts = ['medicine_ids' => 'array', 'starts_at' => 'datetime', 'expires_at' => 'datetime', 'decided_at' => 'datetime', 'followup_due_at' => 'datetime', 'signed_off_at' => 'datetime'];
}
