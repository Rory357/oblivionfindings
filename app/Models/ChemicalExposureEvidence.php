<?php

namespace App\Models;

use App\Services\HealthSafety\ChemicalCommandValues;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ChemicalExposureEvidence extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $table = 'chemical_exposure_evidence';
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['exposure_id' => 'integer', 'source_revision' => 'integer', 'source_schema_version' => 'integer', 'size' => 'integer', 'uploaded_by' => 'integer', 'removed_by' => 'integer', 'uploader_snapshot' => 'array', 'deleted_at' => 'immutable_datetime'];


    public function persisted(): self
    {
        $row = self::query()->whereKey($this->id)->lockForUpdate()->first();
        if (! $row || ChemicalCommandValues::digest($row->attributesToArray()) !== ChemicalCommandValues::digest($this->attributesToArray())) {
            throw new LogicException('Private exposure record was not persisted exactly.');
        }
        return $row;
    }
}