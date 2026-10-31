<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ChemicalCommandIntent extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['actor_id' => 'integer', 'actor_snapshot' => 'array', 'source_id' => 'integer', 'parent_id' => 'integer',
        'expected_version' => 'integer', 'parent_expected_version' => 'integer', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A chemical UUID intent cannot be rebound.'));
        static::deleting(fn () => throw new LogicException('Chemical UUID intent is retained.'));
    }
}
