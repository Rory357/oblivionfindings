<?php

namespace App\Models;

use App\Models\Concerns\ImmutableMedicationPaperEvidence;
use Illuminate\Database\Eloquent\Model;

class MedicationPaperPosting extends Model
{
    use ImmutableMedicationPaperEvidence;

    protected $guarded = ['id'];
}
