<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class IncidentRoutingIntent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['owner_revision' => 'integer', 'source_tuple' => 'json', 'trigger_actor_id' => 'integer', 'attempts' => 'integer', 'evaluated_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $row): void {
            if ($row->isDirty(['incident_id', 'owner_revision', 'kind', 'request_uuid', 'source_tuple', 'source_digest', 'trigger_actor_id', 'trigger_actor_name'])) {
                throw new LogicException('An incident routing intent cannot change its original source or attribution.');
            }
        });
        self::deleting(fn () => throw new LogicException('Incident routing intents must be retained.'));
    }
}
