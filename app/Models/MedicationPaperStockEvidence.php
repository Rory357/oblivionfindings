<?php

namespace App\Models;

use App\Models\Concerns\ImmutableMedicationPaperEvidence;
use Illuminate\Database\Eloquent\Model;

/** A reviewed physical settlement never rewrites the signed clinical paper. */
class MedicationPaperStockEvidence extends Model
{
    use ImmutableMedicationPaperEvidence;

    protected $table = 'medication_paper_stock_evidence';

    protected $guarded = ['id'];

    protected $casts = ['evidence' => 'array'];
}
