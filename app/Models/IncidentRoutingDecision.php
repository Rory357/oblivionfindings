<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class IncidentRoutingDecision extends Model
{
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['configuration' => 'json', 'configuration_revision' => 'integer', 'source_tuple' => 'json', 'attempt' => 'integer', 'recorded_at' => 'immutable_datetime', 'escalate_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Incident routing decisions are immutable.'));
        self::deleting(fn () => throw new LogicException('Incident routing decisions must be retained.'));
    }
}
