<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ChemicalExposureRecoveryCase extends Model
{
    public $timestamps = false;
    protected $table = 'chemical_exposure_recovery_cases';
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['exposure_id' => 'integer', 'worker_id' => 'integer', 'site_id' => 'integer', 'substance_id' => 'integer', 'revision' => 'integer', 'current_version_id' => 'integer', 'sealed_identity' => 'array', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $row) {
            if (array_diff(array_keys($row->getDirty()), ['revision', 'current_version_id']) !== []) { throw new LogicException('Recovery legacy identity is immutable.'); }
        });
        static::deleting(fn () => throw new LogicException('Recovery evidence and history are retained.'));
    }
}
