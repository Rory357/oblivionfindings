<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Immutable reported request; the retained setting snapshot never certifies innocence or misconduct. */
final class IncidentIndependentHandlingDeclaration extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected function casts(): array
    {
        return ['incident_id' => 'integer', 'revision' => 'integer', 'actor_id' => 'integer', 'acceptance_id' => 'integer',
            'source_tuple' => 'json', 'handling' => 'json', 'policy_snapshot' => 'json', 'actor_snapshot' => 'json', 'recorded_at' => 'immutable_datetime'];
    }
    protected static function booted(): void
    {
        self::updating(function (self $row): void {
            if ($row->getOriginal('acceptance_id') === null && array_keys($row->getDirty()) === ['acceptance_id'] && $row->acceptance_id > 0) { return; }
            throw new LogicException('Independent-handling declaration evidence is immutable.');
        });
        self::deleting(fn () => throw new LogicException('Independent-handling declarations must be retained.'));
    }
}
