<?php

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

/** The request binding and committed configuration acknowledgement are immutable. */
final class NativeMonitorCommandReceipt extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['request_hash', 'definition_hash', 'saved_snapshot'];

    protected function casts(): array
    {
        return ['actor_user_id' => 'integer', 'target_monitor_id' => 'integer', 'monitor_id' => 'integer',
            'device_id' => 'integer', 'site_id' => 'integer', 'saved_snapshot' => 'array',
            'created_at' => 'immutable_datetime', 'committed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $receipt): void {
            if ($receipt->getRawOriginal('status') !== 'reserved'
                || $receipt->isDirty(['request_uuid', 'actor_user_id', 'operation', 'request_hash', 'target_monitor_id', 'device_id', 'site_id', 'created_at'])
                || $receipt->status !== 'committed' || $receipt->monitor_id === null
                || $receipt->saved_snapshot === null || $receipt->definition_hash === null || $receipt->committed_at === null) {
                throw new UnexpectedValueException('Retain the original native monitor request binding.');
            }
        });
        self::deleting(fn () => throw new UnexpectedValueException('Retain native monitor command receipts.'));
    }
}
