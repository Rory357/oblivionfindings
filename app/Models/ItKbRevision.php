<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ItKbRevision extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'encrypted:array', 'site_scope' => 'array', 'published_at' => 'datetime', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Recorded knowledge revisions are immutable.'));
        self::deleting(fn () => throw new \LogicException('Recorded knowledge revisions cannot be deleted.'));
    }
}
