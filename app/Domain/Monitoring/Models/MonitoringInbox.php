<?php

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

class MonitoringInbox extends Model
{
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

    protected static function booted(): void
    {
        static::saving(function (self $inbox): void {
            $expectedHash = hash('sha256', $inbox->envelope_bytes);

            if (! is_string($inbox->payload_hash) || ! hash_equals($expectedHash, $inbox->payload_hash)) {
                throw new UnexpectedValueException('Monitoring inbox payload hash does not match envelope bytes.');
            }
        });
    }
}
