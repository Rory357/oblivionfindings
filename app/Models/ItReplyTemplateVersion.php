<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ItReplyTemplateVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['version' => 'integer', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Recorded reply-template versions are immutable.'));
        self::deleting(fn () => throw new \LogicException('Recorded reply-template versions cannot be deleted.'));
    }
}
