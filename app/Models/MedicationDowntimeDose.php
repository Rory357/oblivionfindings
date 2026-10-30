<?php

namespace App\Models;

use App\Models\Concerns\ImmutableMedicationPaperEvidence;
use Illuminate\Database\Eloquent\Model;

class MedicationDowntimeDose extends Model
{
    use ImmutableMedicationPaperEvidence;

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'scheduled_for' => 'immutable_datetime'];
}
