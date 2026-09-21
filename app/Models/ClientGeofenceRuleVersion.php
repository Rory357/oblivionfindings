<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientGeofenceRuleVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['geometry_proposal' => 'array', 'schedule_proposal' => 'array', 'revision' => 'integer', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Zone revisions are immutable. Append a new revision.'));
        static::deleting(fn () => throw new \LogicException('Zone revisions are retained evidence.'));
    }
}
