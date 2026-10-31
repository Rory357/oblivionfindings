<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Private destination provenance; general activity must never copy this projection. */
final class ChemicalExposureHsProjection extends Model
{
    protected $table = 'chemical_exposure_hs_projections';

    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = ['exposure_id' => 'integer', 'hs_event_id' => 'integer', 'source_version_id' => 'integer',
        'source_revision' => 'integer', 'audit_log_id' => 'integer', 'projection' => 'array', 'recorded_by' => 'integer', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Exposure destination provenance is immutable.'));
        static::deleting(fn () => throw new LogicException('Exposure destination provenance is retained.'));
    }
}
