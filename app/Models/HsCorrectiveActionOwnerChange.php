<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class HsCorrectiveActionOwnerChange extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime', 'revision' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Corrective action ownership history is append-only.'));
        static::deleting(fn () => throw new LogicException('Corrective action ownership history is append-only.'));
    }
}
