<?php

namespace App\Models;

use App\Services\HealthSafety\PpeCommandValues;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class PpeCommandReceipt extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['intent_claim_id' => 'integer', 'user_id' => 'integer', 'root_id' => 'integer', 'source_id' => 'integer',
        'expected_version' => 'integer', 'source_version_id' => 'integer', 'audit_log_id' => 'integer',
        'accepted_result' => 'array', 'created_at' => 'immutable_datetime'];

    public function persisted(): self
    {
        $saved = self::whereKey($this->id)->lockForUpdate()->first();
        if (! $saved || PpeCommandValues::digest($saved->attributesToArray()) !== PpeCommandValues::digest($this->attributesToArray())) {
            throw new LogicException('PPE acceptance was not durably saved.');
        }
        return $saved;
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('PPE acceptance is immutable.'));
        static::deleting(fn () => throw new LogicException('PPE acceptance must be retained.'));
    }
}
