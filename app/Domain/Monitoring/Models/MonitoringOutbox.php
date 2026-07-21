<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\Database\MonitoringOutboxBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

class MonitoringOutbox extends Model
{
    public const array IMMUTABLE_DELIVERY_ATTRIBUTES = [
        'message_id',
        'stream',
        'source',
        'sequence',
        'idempotency_key',
        'envelope_bytes',
    ];

    protected $table = 'monitoring_outbox';

    protected $fillable = [
        'message_id',
        'stream',
        'source',
        'sequence',
        'idempotency_key',
        'envelope_bytes',
        'available_at',
        'published_at',
        'attempts',
        'last_error',
        'dispatch_token',
        'dispatch_lease_until',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'available_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
        'attempts' => 'integer',
        'dispatch_lease_until' => 'immutable_datetime',
    ];

    public function newEloquentBuilder($query): MonitoringOutboxBuilder
    {
        return new MonitoringOutboxBuilder($query);
    }

    protected function performUpdate(Builder $query)
    {
        if ($this->isDirty(self::IMMUTABLE_DELIVERY_ATTRIBUTES)) {
            throw new UnexpectedValueException('Monitoring outbox delivery identity and evidence are immutable.');
        }

        return parent::performUpdate($query);
    }
}
