<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OperationsHandoverWitness extends Model
{
    protected $guarded = ['*'];
    protected $hidden = ['key_id', 'signature'];
    protected $casts = ['attempt_id' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Handover commit witness is immutable.'));
        static::deleting(fn () => throw new \LogicException('Handover commit witness cannot be discarded.'));
    }
}
