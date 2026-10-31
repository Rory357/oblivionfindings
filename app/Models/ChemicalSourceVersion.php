<?php

namespace App\Models;

use App\Services\HealthSafety\ChemicalCommandValues;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ChemicalSourceVersion extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['root_id' => 'integer', 'source_id' => 'integer', 'parent_id' => 'integer', 'site_id' => 'integer',
        'aggregate_revision' => 'integer', 'expected_version' => 'integer', 'parent_expected_version' => 'integer',
        'source_schema_version' => 'integer', 'actor_id' => 'integer', 'actor_snapshot' => 'array',
        'content' => 'array', 'recorded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Chemical source versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Chemical source versions are retained.'));
    }

    public function persisted(): self
    {
        $row = self::query()->whereKey($this->id)->lockForUpdate()->first();
        if (! $row || ChemicalCommandValues::digest($row->attributesToArray()) !== ChemicalCommandValues::digest($this->attributesToArray())) {
            throw new LogicException('Chemical source version was not persisted exactly.');
        }
        return $row;
    }
}
