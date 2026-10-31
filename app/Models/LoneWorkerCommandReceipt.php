<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable actor-bound confirmation of an actual source command. */
class LoneWorkerCommandReceipt extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['confirmed_result' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('A confirmed lone-worker command is immutable.'));
        static::deleting(fn () => throw new \LogicException('A confirmed lone-worker command must be retained.'));
    }
}
