<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\Database\MaintenanceRevisionBuilder;
use App\Domain\Monitoring\Services\MaintenanceConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class MonitoringMaintenanceRevision extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['snapshot', 'snapshot_hash'];

    protected function casts(): array
    {
        return ['window_id' => 'integer', 'version' => 'integer', 'site_id' => 'integer', 'actor_user_id' => 'integer', 'supersedes_id' => 'integer', 'snapshot' => 'array', 'effective_from' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }

    public function newEloquentBuilder($query): MaintenanceRevisionBuilder
    {
        return new MaintenanceRevisionBuilder($query);
    }

    protected function performInsert(Builder $query)
    {
        $snapshot = $this->snapshot;
        if (! in_array($this->operation, ['create', 'update', 'cancel', 'baseline'], true)
            || ! is_array($snapshot) || ($snapshot['id'] ?? null) !== $this->window_id
            || ($snapshot['version'] ?? null) !== $this->version || ($snapshot['site_id'] ?? null) !== $this->site_id
            || ! hash_equals(MaintenanceConfiguration::hash($snapshot), (string) $this->snapshot_hash)
            || $this->effective_from === null || $this->recorded_at === null || $this->effective_from != $this->recorded_at) {
            throw new LogicException('Maintenance revision provenance is invalid.');
        }

        return parent::performInsert($query);
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException('Maintenance revisions are immutable.');
    }

    public function delete(): never
    {
        throw new LogicException('Retain original maintenance revisions.');
    }
}
