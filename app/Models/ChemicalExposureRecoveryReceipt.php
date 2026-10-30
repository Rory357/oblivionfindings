<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ChemicalExposureRecoveryReceipt extends Model
{
    public $timestamps = false;
    protected $table = 'chemical_exposure_recovery_receipts';
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['case_id' => 'integer', 'version_id' => 'integer', 'intent_id' => 'integer', 'actor_id' => 'integer', 'audit_log_id' => 'integer', 'accepted_result' => 'array', 'audit_metadata' => 'array', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $row) {
            throw new LogicException('Recovery accepted history is immutable.');
        });
        static::deleting(fn () => throw new LogicException('Recovery evidence and history are retained.'));
    }
}
