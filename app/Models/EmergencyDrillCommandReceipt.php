<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable confirmation of one actor-bound source command. */
class EmergencyDrillCommandReceipt extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['confirmed_result' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('A confirmed drill command is immutable.'));
        static::deleting(fn () => throw new \LogicException('A confirmed drill command must be retained.'));
    }
}
