<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Prospective source-field snapshot, never a grant or a whole-report revision. */
final class IncidentSavedCopy extends Model
{
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['incident_id' => 'integer', 'actor_id' => 'integer', 'audit_id' => 'integer',
            'source_tuple' => 'array', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $copy): void {
            if ($copy->getOriginal('audit_id') === null && array_keys($copy->getDirty()) === ['audit_id'] && $copy->audit_id > 0) { return; }
            throw new LogicException('Incident saved-copy evidence is immutable.');
        });
        self::deleting(fn () => throw new LogicException('Incident saved-copy evidence must be retained.'));
    }
}
