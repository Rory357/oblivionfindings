<?php

namespace App\Models;

use App\Models\Concerns\ImmutableMedicationPaperEvidence;
use Illuminate\Database\Eloquent\Model;

class MedicationPaperRecoveryAuthorization extends Model
{
    use ImmutableMedicationPaperEvidence;

    protected $guarded = ['id'];

    protected $casts = ['evidence' => 'array'];
}
