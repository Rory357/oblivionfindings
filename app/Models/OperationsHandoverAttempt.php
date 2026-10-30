<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OperationsHandoverAttempt extends Model
{
    protected $guarded = ['*'];
    protected $casts = ['actor_id' => 'integer', 'shift_id' => 'integer', 'requested_handover_id' => 'integer',
        'expected_version' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Original handover attempt evidence is immutable.'));
        static::deleting(fn () => throw new \LogicException('Original handover attempt evidence cannot be discarded.'));
    }
}
