<?php

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

final class MonitoringRecoveryRequest extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['request_hash', 'evidence_hash', 'saved_acknowledgement'];

    protected function casts(): array
    {
        return ['actor_user_id' => 'integer', 'dead_letter_id' => 'integer', 'site_id' => 'integer', 'saved_acknowledgement' => 'array', 'created_at' => 'immutable_datetime', 'committed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $row): void {
            if ($row->getRawOriginal('status') !== 'reserved' || $row->status !== 'committed'
                || $row->isDirty(['request_uuid', 'actor_user_id', 'dead_letter_id', 'site_id', 'operation', 'request_hash', 'evidence_hash', 'created_at'])
                || $row->saved_acknowledgement === null || $row->committed_at === null) {
                throw new UnexpectedValueException('Retain the original monitoring recovery decision.');
            }
        });
        self::deleting(fn () => throw new UnexpectedValueException('Retain monitoring recovery decisions.'));
    }
}
