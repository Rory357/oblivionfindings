<?php

namespace App\Models;

use App\Services\HealthSafety\RestraintCommandValues;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Immutable global event/plan command acceptance; never a source-read grant. */
class RestraintCommandReceipt extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['accepted_result' => 'array', 'actor_snapshot' => 'array', 'expected_version' => 'integer', 'created_at' => 'immutable_datetime'];

    public function persisted(): self
    {
        $row = self::query()->whereKey($this->id)->lockForUpdate()->first();
        if (! $row || $row->fingerprint !== $this->fingerprint || RestraintCommandValues::digest($row->accepted_result) !== RestraintCommandValues::digest($this->accepted_result)) {
            throw new LogicException('The accepted restraint command was not durably confirmed.');
        }

        return $row;
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Restraint command acceptance is immutable.'));
        static::deleting(fn () => throw new LogicException('Restraint command acceptance must be retained.'));
    }
}
