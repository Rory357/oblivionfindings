<?php

namespace App\Models;

use App\Services\HealthSafety\ChemicalCommandValues;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ChemicalCommandReceipt extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['intent_id' => 'integer', 'actor_id' => 'integer', 'root_id' => 'integer', 'source_id' => 'integer',
        'parent_id' => 'integer', 'expected_version' => 'integer', 'parent_expected_version' => 'integer',
        'source_version_id' => 'integer', 'audit_log_id' => 'integer', 'accepted_result' => 'array', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Chemical acceptance receipts are immutable.'));
        static::deleting(fn () => throw new LogicException('Chemical acceptance evidence is retained.'));
    }

    public function persisted(): self
    {
        $row = self::query()->whereKey($this->id)->lockForUpdate()->first();
        if (! $row || ChemicalCommandValues::digest($row->attributesToArray()) !== ChemicalCommandValues::digest($this->attributesToArray())) {
            throw new LogicException('Chemical acceptance was not persisted exactly.');
        }
        return $row;
    }
}
