<?php

namespace App\Models;

use App\Services\HealthSafety\RestraintCommandValues;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** One immutable ledger across injury and recovery/evidence commands; never a read grant. */
final class WorkplaceInjuryCommandReceipt extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['workplace_injury_id' => 'integer', 'source_id' => 'integer', 'user_id' => 'integer',
        'audit_log_id' => 'integer', 'requires_medical_access' => 'boolean', 'expected_version' => 'integer', 'accepted_result' => 'array',
        'actor_snapshot' => 'array', 'created_at' => 'immutable_datetime'];

    public function persisted(): self
    {
        $saved = self::query()->whereKey($this->id)->lockForUpdate()->first();
        if (! $saved || RestraintCommandValues::digest($saved->attributesToArray()) !== RestraintCommandValues::digest($this->attributesToArray())) {
            throw new LogicException('The injury command receipt was not durably confirmed.');
        }
        return $saved;
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Injury command acceptance is immutable.'));
        static::deleting(fn () => throw new LogicException('Injury command acceptance must be retained.'));
    }
}

