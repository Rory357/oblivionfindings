<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BoundaryVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'recorded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Boundary versions are immutable.'));
        self::deleting(fn () => throw new \LogicException('Boundary versions are retained evidence.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
