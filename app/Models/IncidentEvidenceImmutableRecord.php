<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Passive provenance. Use a separately authorised explicit DTO for every read/export. */
abstract class IncidentEvidenceImmutableRecord extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $visible = ['id'];
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Incident reconstruction provenance is append-only.'));
        static::deleting(fn () => throw new LogicException('Incident reconstruction provenance must be retained.'));
    }
}