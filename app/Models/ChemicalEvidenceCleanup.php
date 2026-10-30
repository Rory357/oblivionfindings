<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ChemicalEvidenceCleanup extends Model
{
    protected $table = 'chemical_evidence_cleanup';
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['staging_id' => 'integer', 'attempts' => 'integer'];
}
