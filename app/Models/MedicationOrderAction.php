<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicationOrderAction extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['evidence' => 'array', 'occurred_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Order action evidence is immutable.'));
        static::deleting(fn () => throw new \LogicException('Order action evidence is retained.'));
    }
}
