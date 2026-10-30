<?php

namespace App\Models;

use App\Services\HealthSafety\ChemicalCommandValues;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ChemicalExposureCommandReceipt extends Model
{

    public $timestamps = false;
    protected $table = 'chemical_exposure_command_receipts';
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['intent_id' => 'integer', 'actor_id' => 'integer', 'exposure_id' => 'integer', 'source_id' => 'integer', 'expected_version' => 'integer', 'source_version_id' => 'integer', 'audit_log_id' => 'integer', 'source_tuple' => 'array', 'accepted_result' => 'array', 'created_at' => 'immutable_datetime'];

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