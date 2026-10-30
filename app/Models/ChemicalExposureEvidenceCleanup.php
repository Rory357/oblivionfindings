<?php

namespace App\Models;

use App\Services\HealthSafety\ChemicalCommandValues;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ChemicalExposureEvidenceCleanup extends Model
{


    protected $table = 'chemical_exposure_evidence_cleanups';
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['actor_id' => 'integer', 'exposure_id' => 'integer', 'site_id' => 'integer', 'evidence_id' => 'integer', 'receipt_id' => 'integer', 'size' => 'integer', 'attempts' => 'integer'];


    public function persisted(): self
    {
        $row = self::query()->whereKey($this->id)->lockForUpdate()->first();
        if (! $row || ChemicalCommandValues::digest($row->attributesToArray()) !== ChemicalCommandValues::digest($this->attributesToArray())) {
            throw new LogicException('Private exposure record was not persisted exactly.');
        }
        return $row;
    }
}