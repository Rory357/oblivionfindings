<?php

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

/** Reserved and committed atomically. A committed binding/snapshot is immutable. */
final class MonitoringMaintenanceCommandReceipt extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['request_hash', 'before_snapshot', 'after_snapshot', 'before_scope', 'after_scope'];

    protected function casts(): array
    {
        return ['actor_user_id' => 'integer', 'target_window_id' => 'integer', 'window_id' => 'integer',
            'before_snapshot' => 'array', 'after_snapshot' => 'array', 'before_scope' => 'array', 'after_scope' => 'array',
            'created_at' => 'immutable_datetime', 'committed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $receipt): void {
            if ($receipt->getRawOriginal('status') !== 'reserved'
                || $receipt->isDirty(['request_uuid', 'actor_user_id', 'operation', 'request_hash', 'target_window_id', 'before_snapshot', 'before_scope', 'created_at'])
                || $receipt->status !== 'committed' || $receipt->window_id === null || $receipt->after_snapshot === null || $receipt->after_scope === null || $receipt->committed_at === null) {
                throw new UnexpectedValueException('The maintenance command binding is immutable.');
            }
        });
        self::deleting(fn () => throw new UnexpectedValueException('Retain the original maintenance command receipt.'));
    }
}
