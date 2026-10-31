<?php

namespace App\Models;

use App\Services\HealthSafety\ChemicalCommandValues;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ChemicalExposureEvidenceStaging extends Model
{


    protected $table = 'chemical_exposure_evidence_staging';
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['intent_id' => 'integer', 'actor_id' => 'integer', 'exposure_id' => 'integer', 'substance_id' => 'integer', 'worker_id' => 'integer', 'site_id' => 'integer', 'expected_version' => 'integer', 'receipt_id' => 'integer', 'evidence_id' => 'integer', 'attempts' => 'integer', 'file_evidence' => 'array', 'command_values' => 'array'];


    protected static function booted(): void
    {
        static::updating(function (self $row) {
            if (array_diff(array_keys($row->getDirty()), ['state', 'receipt_id', 'evidence_id', 'attempts', 'updated_at']) !== []) {
                throw new LogicException('Exposure reservation identity and intent are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Exposure physical attempts remain discoverable.'));
    }
    public function persisted(): self
    {
        $row = self::query()->whereKey($this->id)->lockForUpdate()->first();
        if (! $row || ChemicalCommandValues::digest($row->attributesToArray()) !== ChemicalCommandValues::digest($this->attributesToArray())) {
            throw new LogicException('Private exposure record was not persisted exactly.');
        }
        return $row;
    }
}