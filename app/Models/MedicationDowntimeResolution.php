<?php

namespace App\Models;

use App\Models\Concerns\ImmutableMedicationPaperEvidence;
use Illuminate\Database\Eloquent\Model;

class MedicationDowntimeResolution extends Model
{
    use ImmutableMedicationPaperEvidence;

    protected $guarded = ['id'];
}
