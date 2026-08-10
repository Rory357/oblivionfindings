<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\Database\MonitoringInboxBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

/**
 * Identity and evidence writes must use this model and its Eloquent builder.
 * Direct database writes are reserved for schema and migration operations.
 */
class MonitoringInbox extends Model
{
    public const array IMMUTABLE_DELIVERY_ATTRIBUTES = [
        'message_id',
        'consumer',
        'source',
        'sequence',
        'idempotency_key',
        'envelope_bytes',
        'payload_hash',
    ];

    protected $table = 'monitoring_inbox';

    protected $fillable = [
        'message_id',
        'consumer',
        'source',
        'sequence',
        'idempotency_key',
        'payload_hash',
        'envelope_bytes',
        'processed_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'processed_at' => 'immutable_datetime',
    ];

    public function newEloquentBuilder($query): MonitoringInboxBuilder
    {
        return new MonitoringInboxBuilder($query);
    }

    protected function performInsert(Builder $query)
    {
        $this->assertPayloadIntegrity();

        return parent::performInsert($query);
    }

    protected function performUpdate(Builder $query)
    {
        if ($this->isDirty(self::IMMUTABLE_DELIVERY_ATTRIBUTES)) {
            throw new UnexpectedValueException('Monitoring inbox delivery identity and evidence are immutable.');
        }

        return parent::performUpdate($query);
    }

    private function assertPayloadIntegrity(): void
    {
        $expectedHash = hash('sha256', $this->envelope_bytes);

        if (! is_string($this->payload_hash) || ! hash_equals($expectedHash, $this->payload_hash)) {
            throw new UnexpectedValueException('Monitoring inbox payload hash does not match envelope bytes.');
        }
    }
}
