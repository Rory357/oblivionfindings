<?php

namespace App\Models;

use App\Services\HealthSafety\ChemicalCommandValues;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ChemicalExposureFollowup extends Model
{


    protected $table = 'chemical_exposure_followups';
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['exposure_id' => 'integer', 'source_revision' => 'integer', 'source_schema_version' => 'integer', 'assigned_to' => 'integer', 'recorded_by' => 'integer', 'completed_by' => 'integer', 'due_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'assignee_snapshot' => 'array'];


    public function persisted(): self
    {
        $row = self::query()->whereKey($this->id)->lockForUpdate()->first();
        if (! $row || ChemicalCommandValues::digest($row->attributesToArray()) !== ChemicalCommandValues::digest($this->attributesToArray())) {
            throw new LogicException('Private exposure record was not persisted exactly.');
        }
        return $row;
    }
}