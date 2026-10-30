<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetProfileEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'occurred_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Asset evidence is retained as recorded.'));
        static::deleting(fn () => throw new \LogicException('Asset evidence is retained as recorded.'));
    }
}
