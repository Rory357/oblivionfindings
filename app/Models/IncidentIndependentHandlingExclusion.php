<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A new declaration's exact link to exclusion evidence; pre-existing exclusions are not retrocertified. */
final class IncidentIndependentHandlingExclusion extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected function casts(): array
    {
        return ['declaration_id' => 'integer', 'user_id' => 'integer', 'exclusion_id' => 'integer', 'prior_existing' => 'boolean', 'recorded_at' => 'immutable_datetime'];
    }
    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Independent-handling exclusion provenance is immutable.'));
        self::deleting(fn () => throw new LogicException('Independent-handling exclusion provenance must be retained.'));
    }
}
