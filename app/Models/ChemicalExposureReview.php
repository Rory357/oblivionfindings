<?php

namespace App\Models;

use App\Services\HealthSafety\ChemicalCommandValues;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ChemicalExposureReview extends Model
{


    protected $table = 'chemical_exposure_reviews';
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['exposure_id' => 'integer', 'source_revision' => 'integer', 'source_schema_version' => 'integer', 'recorded_by' => 'integer', 'reviewed_at' => 'immutable_datetime', 'recorder_snapshot' => 'array'];

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