<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ChemicalExposureRecoveryVersion extends Model
{
    public $timestamps = false;
    protected $table = 'chemical_exposure_recovery_versions';
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['case_id' => 'integer', 'exposure_id' => 'integer', 'intent_id' => 'integer', 'actor_id' => 'integer', 'revision' => 'integer', 'expected_version' => 'integer', 'previous_version_id' => 'integer', 'review_context' => 'array', 'content' => 'array', 'reviewed_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $row) {
            throw new LogicException('Recovery accepted history is immutable.');
        });
        static::deleting(fn () => throw new LogicException('Recovery evidence and history are retained.'));
    }
}
