<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ChemicalExposureRecoveryEvidence extends Model
{
    public $timestamps = false;
    protected $table = 'chemical_exposure_recovery_evidence';
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['case_id' => 'integer', 'version_id' => 'integer', 'staging_id' => 'integer', 'size' => 'integer'];

    protected static function booted(): void
    {
        static::updating(function (self $row) {
            throw new LogicException('Recovery accepted history is immutable.');
        });
        static::deleting(fn () => throw new LogicException('Recovery evidence and history are retained.'));
    }
}
