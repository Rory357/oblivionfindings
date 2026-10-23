<?php

namespace App\Models;

use App\Services\HealthSafety\ChemicalCommandValues;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ChemicalExposureSourceVersion extends Model
{

    public $timestamps = false;
    protected $table = 'chemical_exposure_source_versions';
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['exposure_id' => 'integer', 'source_id' => 'integer', 'substance_id' => 'integer', 'worker_id' => 'integer', 'site_id' => 'integer', 'recorder_id' => 'integer', 'actor_id' => 'integer', 'intent_id' => 'integer', 'aggregate_revision' => 'integer', 'expected_version' => 'integer', 'source_schema_version' => 'integer', 'actor_snapshot' => 'array', 'content' => 'array', 'recorded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Private exposure facts are immutable.'));
        static::deleting(fn () => throw new LogicException('Private exposure history is retained.'));
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