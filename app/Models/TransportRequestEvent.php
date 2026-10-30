<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TransportRequestEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Transport observations are immutable. Record a correction.'));
        static::deleting(fn () => throw new LogicException('Transport observations are retained.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
