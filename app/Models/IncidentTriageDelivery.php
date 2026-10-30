<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class IncidentTriageDelivery extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'due_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $row): void {
            if ($row->isDirty(['decision_id', 'recipient_user_id', 'recipient_role', 'due_at'])) {
                throw new LogicException('Triage delivery cannot change its accepted recipient or source.');
            }
        });
        self::deleting(fn () => throw new LogicException('Triage delivery evidence must be retained.'));
    }
}
