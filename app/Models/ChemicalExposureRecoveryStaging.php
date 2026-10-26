<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ChemicalExposureRecoveryStaging extends Model
{
    public $timestamps = true;
    protected $table = 'chemical_exposure_recovery_staging';
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['case_id' => 'integer', 'exposure_id' => 'integer', 'intent_id' => 'integer', 'actor_id' => 'integer', 'expected_version' => 'integer', 'receipt_id' => 'integer', 'attempts' => 'integer'];

    protected static function booted(): void
    {
        static::updating(function (self $row) {
            if (array_diff(array_keys($row->getDirty()), ['state', 'receipt_id', 'attempts', 'updated_at']) !== []) { throw new LogicException('Recovery reservation cannot be rebound.'); }
        });
        static::deleting(fn () => throw new LogicException('Recovery evidence and history are retained.'));
    }
}
