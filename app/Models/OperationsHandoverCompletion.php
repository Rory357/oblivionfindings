<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Atomic domain result. Alone this is never proof that the root committed. */
final class OperationsHandoverCompletion extends Model
{
    protected $guarded = ['*'];
    protected $hidden = ['receipt', 'intended_after_hash'];
    protected $casts = ['attempt_id' => 'integer', 'handover_id' => 'integer', 'receipt' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Handover completion evidence is immutable.'));
        static::deleting(fn () => throw new \LogicException('Handover completion evidence cannot be discarded.'));
    }
}
