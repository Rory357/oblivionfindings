<?php

namespace App\Models;

use App\Models\Concerns\ImmutableMedicationPaperEvidence;
use Illuminate\Database\Eloquent\Model;

class MedicationPaperConfirmation extends Model
{
    use ImmutableMedicationPaperEvidence;

    protected $guarded = ['id'];

    protected $casts = ['confirmed_at' => 'immutable_datetime'];
}
