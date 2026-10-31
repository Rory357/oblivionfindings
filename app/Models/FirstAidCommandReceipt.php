<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Immutable actor/source/payload confirmation; never a record-read grant. */
class FirstAidCommandReceipt extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['confirmed_result' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A confirmed first-aid command is immutable.'));
        static::deleting(fn () => throw new LogicException('A first-aid confirmation must be retained.'));
    }
}
