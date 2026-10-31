<?php

namespace App\Models;

use App\Services\HealthSafety\ChemicalCommandValues;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ChemicalExposureSourceDelivery extends Model
{


    protected $table = 'chemical_exposure_source_deliveries';
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['exposure_id' => 'integer', 'source_version_id' => 'integer', 'actor_id' => 'integer', 'recipient_id' => 'integer', 'hs_event_id' => 'integer', 'attempts' => 'integer', 'membership' => 'array',
        'failed_attempts' => 'integer', 'needs_manager' => 'boolean', 'last_attempted_at' => 'datetime', 'next_attempt_at' => 'datetime'];


    public function persisted(): self
    {
        $row = self::query()->whereKey($this->id)->lockForUpdate()->first();
        if (! $row || ChemicalCommandValues::digest($row->attributesToArray()) !== ChemicalCommandValues::digest($this->attributesToArray())) {
            throw new LogicException('Private exposure record was not persisted exactly.');
        }
        return $row;
    }
}